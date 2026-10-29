<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\State\StateInterface;
use Drupal\fresco_ai\Plugin\QueueWorker\AiRequestQueueWorker;
use Drupal\fresco_ai\Service\AiRateLimiter;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\AiRequestProcessor;
use Drupal\fresco_ai\Service\GenerationCoordinator;
use Drupal\fresco_ai\Service\IngredientImageAnalyzer;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the Fresco AI request queue worker.
 */
#[Group('fresco_ai')]
final class AiRequestQueueWorkerTest extends UnitTestCase {

  /**
   * Confirms queue failures are logged through the injected channel.
   */
  public function testProcessItemLogsFailures(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())
      ->method('warning')
      ->with('Queued AI request failed: {message}', ['message' => 'Unsupported AI queue request.']);

    $processor = new AiRequestProcessor(
      $this->generationCoordinator(),
      $this->ingredientImageAnalyzer(),
      $this->usageLedger(),
    );

    $worker = new AiRequestQueueWorker([], 'fresco_ai_requests', [], $processor, $logger);
    $worker->processItem(['type' => 'unsupported']);
  }

  /**
   * Builds a minimal generation coordinator.
   */
  private function generationCoordinator(): GenerationCoordinator {
    return new GenerationCoordinator(
      $this->createMock(DefaultPluginManager::class),
      $this->configFactory([]),
      new RecipeContractFactory(),
      new RecipeValidator([]),
      $this->rateLimiter(),
      $this->usageLedger(),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a minimal image analyzer.
   */
  private function ingredientImageAnalyzer(): IngredientImageAnalyzer {
    return new IngredientImageAnalyzer(
      $this->createMock(ClientInterface::class),
      $this->configFactory([
        'image_provider' => 'ollama',
        'openai_vision_model' => 'gpt-4.1-mini',
        'ollama_model' => 'moondream:latest',
        'ollama_base_url' => 'http://localhost:11434',
      ]),
      $this->rateLimiter(),
      $this->usageLedger(),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a minimal usage ledger.
   */
  private function usageLedger(): AiUsageLedger {
    return new AiUsageLedger($this->state([]), $this->configFactory(['audit_retention_days' => 30]));
  }

  /**
   * Builds a minimal rate limiter.
   */
  private function rateLimiter(): AiRateLimiter {
    return new AiRateLimiter($this->state([]), $this->configFactory(['request_rate_limit_per_hour' => 20]));
  }

  /**
   * Builds a minimal in-memory state implementation.
   *
   * @param array<string, mixed> $values
   *   Initial state values.
   */
  private function state(array $values): StateInterface {
    return new TestState($values);
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

}

/**
 * Small in-memory state store for this unit test.
 */
final class TestState implements StateInterface {

  /**
   * Creates the in-memory state store.
   *
   * @param array<string, mixed> $values
   *   Initial values.
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
