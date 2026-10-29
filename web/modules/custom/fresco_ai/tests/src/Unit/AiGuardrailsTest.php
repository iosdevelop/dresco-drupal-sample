<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Plugin\Discovery\StaticDiscovery;
use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\State\StateInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\AiProviderInterface;
use Drupal\fresco_ai\Service\AiPreflight;
use Drupal\fresco_ai\Service\AiRateLimiter;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\AiProviderManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Fresco AI guardrails and readiness reporting.
 */
#[Group('fresco_ai')]
final class AiGuardrailsTest extends UnitTestCase {

  /**
   * Confirms the rate limiter blocks once the hourly cap is reached.
   */
  public function testRateLimiterTracksRequestsAndBlocksAfterLimit(): void {
    $state = new InMemoryState();
    $configFactory = $this->configFactory(['request_rate_limit_per_hour' => 2]);
    $limiter = new AiRateLimiter($state, $configFactory);

    $limiter->assertAllowed('generate', 7);
    $limiter->assertAllowed('generate', 7);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('AI request limit reached for this hour (2).');
    $limiter->assertAllowed('generate', 7);
  }

  /**
   * Confirms old ledger entries are purged by the retention window.
   */
  public function testUsageLedgerPurgesOldEntries(): void {
    $state = new InMemoryState([
      'fresco_ai.usage_ledger' => [
        ['time' => time() - 40 * 86400, 'type' => 'generate', 'provider' => 'local', 'uid' => 1, 'status' => 'ok'],
        ['time' => time(), 'type' => 'generate', 'provider' => 'local', 'uid' => 1, 'status' => 'ok'],
      ],
    ]);
    $ledger = new AiUsageLedger($state, $this->configFactory(['audit_retention_days' => 30]));

    $summary = $ledger->summary();
    self::assertSame(1, $summary['entries']);
    self::assertCount(1, $summary['recent']);
    self::assertSame(1, $summary['counts']['ok']);
    self::assertSame(1, $ledger->purgeExpired());
  }

  /**
   * Confirms preflight reports an okay readiness state when configured.
   */
  public function testPreflightReportsReadyState(): void {
    $configFactory = $this->configFactory([
      'provider' => 'local',
      'image_provider' => 'ollama',
      'openai_vision_model' => 'gpt-4.1-mini',
      'ollama_model' => 'llama3.1',
      'ollama_vision_model' => 'moondream:latest',
      'ollama_base_url' => 'http://localhost:11434',
      'request_rate_limit_per_hour' => 20,
      'audit_retention_days' => 30,
    ]);
    $providerManager = $this->providerManager();
    $state = new InMemoryState();
    $rateLimiter = new AiRateLimiter($state, $configFactory);
    $usageLedger = new AiUsageLedger($state, $configFactory);
    $preflight = new AiPreflight($configFactory, $providerManager, $rateLimiter, $usageLedger);

    $report = $preflight->check();

    self::assertSame('ok', $report['status']);
    self::assertTrue($report['provider_available']);
    self::assertTrue($report['image_provider_available']);
    self::assertSame(20, $report['rate_limit']['limit_per_hour']);
    self::assertSame(0, $report['usage']['entries']);
  }

  /**
   * Confirms preflight surfaces missing configuration cleanly.
   */
  public function testPreflightReportsMissingImageProviderConfiguration(): void {
    $configFactory = $this->configFactory([
      'provider' => 'local',
      'image_provider' => 'ollama',
      'openai_vision_model' => 'gpt-4.1-mini',
      'ollama_model' => '',
      'ollama_base_url' => '',
      'request_rate_limit_per_hour' => 20,
      'audit_retention_days' => 30,
    ]);
    $providerManager = $this->providerManager();
    $state = new InMemoryState();
    $rateLimiter = new AiRateLimiter($state, $configFactory);
    $usageLedger = new AiUsageLedger($state, $configFactory);
    $preflight = new AiPreflight($configFactory, $providerManager, $rateLimiter, $usageLedger);

    $report = $preflight->check();

    self::assertSame('needs_configuration', $report['status']);
    self::assertTrue($report['provider_available']);
    self::assertFalse($report['image_provider_available']);
    self::assertSame(['image_provider'], $report['missing']);
  }

  /**
   * Confirms preflight handles an invalid provider id gracefully.
   */
  public function testPreflightReportsInvalidProviderWithoutThrowing(): void {
    $configFactory = $this->configFactory([
      'provider' => 'does_not_exist',
      'image_provider' => 'ollama',
      'openai_vision_model' => 'gpt-4.1-mini',
      'ollama_model' => 'llama3.1',
      'ollama_vision_model' => 'moondream:latest',
      'ollama_base_url' => 'http://localhost:11434',
      'request_rate_limit_per_hour' => 20,
      'audit_retention_days' => 30,
    ]);
    $providerManager = $this->providerManager();
    $state = new InMemoryState();
    $rateLimiter = new AiRateLimiter($state, $configFactory);
    $usageLedger = new AiUsageLedger($state, $configFactory);
    $preflight = new AiPreflight($configFactory, $providerManager, $rateLimiter, $usageLedger);

    $report = $preflight->check();

    self::assertSame('needs_configuration', $report['status']);
    self::assertFalse($report['provider_available']);
    self::assertNull($report['provider_class']);
    self::assertNotNull($report['provider_error']);
    self::assertSame(['generation_provider'], $report['missing']);
  }

  /**
   * Builds a config factory mock for the provided key/value pairs.
   *
   * @param array<string, mixed> $values
   *   Configuration values.
   */
  private function configFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * Builds a real provider manager wired to the local AI plugin namespace.
   */
  private function providerManager(): AiProviderManager {
    $namespaces = new \ArrayObject([
      'Drupal\\fresco_ai' => dirname(__DIR__, 3),
    ]);
    $cacheBackend = $this->createMock(CacheBackendInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $manager = new AiProviderManager($namespaces, $cacheBackend, $moduleHandler);
    $discovery = new StaticDiscovery();
    $discovery->setDefinition('local', [
      'id' => 'local',
      'class' => LocalProvider::class,
    ]);

    $this->setProperty($manager, 'discovery', $discovery);
    $this->setProperty($manager, 'factory', new DefaultFactory($discovery, AiProviderInterface::class));

    return $manager;
  }

  /**
   * Sets a protected property on an object.
   *
   * @param object $object
   *   The object to modify.
   * @param string $property
   *   The property name.
   * @param mixed $value
   *   The property value.
   */
  private function setProperty(object $object, string $property, mixed $value): void {
    $ref = new \ReflectionObject($object);
    $prop = $ref->getParentClass()->getProperty($property);
    $prop->setValue($object, $value);
  }

}

/**
 * Small in-memory state store for unit testing.
 */
final class InMemoryState implements StateInterface {

  /**
   * Creates the in-memory state store.
   *
   * @param array<string, mixed> $values
   *   Initial state values.
   */
  public function __construct(private array $values = []) {}

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->values[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, string> $keys
   *   State keys.
   *
   * @return array<string, mixed>
   *   State values keyed by state name.
   */
  public function getMultiple(array $keys): array {
    $result = [];
    foreach ($keys as $key) {
      $result[$key] = $this->get((string) $key);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): void {
    $this->values[$key] = $value;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $data
   *   State values keyed by state name.
   */
  public function setMultiple(array $data): void {
    foreach ($data as $key => $value) {
      $this->set((string) $key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): void {
    unset($this->values[$key]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, string> $keys
   *   State keys.
   */
  public function deleteMultiple(array $keys): void {
    foreach ($keys as $key) {
      $this->delete((string) $key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {}

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    if (!array_key_exists($key, $this->values)) {
      return NULL;
    }

    return [
      'value' => $this->values[$key],
      'original' => NULL,
    ];
  }

}
