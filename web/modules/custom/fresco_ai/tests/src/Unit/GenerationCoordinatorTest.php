<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\AiProviderInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\fresco_ai\Service\AiRateLimiter;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\GenerationCoordinator;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Validation\Rule\TechnicalSafetyRule;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests validated generation and local fallback behavior.
 */
#[Group('fresco_ai')]
final class GenerationCoordinatorTest extends UnitTestCase {

  /**
   * Confirms missing steam doneness cues recover through local fallback.
   */
  public function testSteamDonenessFailureFallsBackToCookableRecipe(): void {
    $manager = $this->createMock(DefaultPluginManager::class);
    $manager->method('createInstance')->willReturnCallback(static fn (string $id): AiProviderInterface => $id === 'local'
      ? new LocalProvider([], 'local', [])
      : new class implements AiProviderInterface {

        /**
         * {@inheritdoc}
         */
        public function generate(array $request): array {
          return [
            'schema_version' => '1.0',
            'title' => 'Steamed Cauliflower Bowl',
            'description' => 'A vegan bowl with cauliflower, aromatics, and lime.',
            'servings' => 4,
            'prep_time_minutes' => 10,
            'cook_time_minutes' => 12,
            'cuisine' => 'Caribbean',
            'category' => 'Vegan',
            'ingredients' => [
              ['original_text' => '2 cups cauliflower florets'],
              ['original_text' => '1 cup water'],
              ['original_text' => '2 cloves garlic'],
              ['original_text' => '1 teaspoon thyme'],
              ['original_text' => '1 lime'],
            ],
            'instructions' => [
              ['text' => 'Steam cauliflower with garlic, thyme, and 1 cup water. Finish with lime.'],
            ],
            'appliance_settings' => [
              ['appliance' => 'Instant Pot', 'mode' => 'Steam', 'time' => '12 minutes'],
            ],
            'nutrition' => ['protein' => '8g'],
          ];
        }

        /**
         * {@inheritdoc}
         */
        public function isAvailable(): bool {
          return TRUE;
        }

        /**
         * {@inheritdoc}
         */
        public function getPluginId(): string {
          return 'remote';
        }

        /**
         * {@inheritdoc}
         *
         * @return array<string, mixed>
         *   Plugin definition.
         */
        public function getPluginDefinition(): array {
          return [];
        }

      });

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(7);
    $coordinator = new GenerationCoordinator(
      $manager,
      $this->configFactory(['provider' => 'remote']),
      new RecipeContractFactory(),
      new RecipeValidator([new TechnicalSafetyRule()]),
      new AiRateLimiter($this->state([]), $this->configFactory(['request_rate_limit_per_hour' => 20])),
      new AiUsageLedger($this->state([]), $this->configFactory(['audit_retention_days' => 30])),
      $account,
      $this->createMock(LoggerInterface::class),
    );

    $result = $coordinator->generate([
      'ingredients' => [
        '2 cups cauliflower florets',
        '1 cup water',
        '2 cloves garlic',
        '1 teaspoon thyme',
        '1 lime',
      ],
      'appliances' => ['Instant Pot'],
    ]);

    self::assertSame('local', $result->provider);
    self::assertStringContainsString('fork-tender', $result->recipe->instructions[1]['text']);
    self::assertStringContainsString('Steamed potato or cauliflower steps require', (string) $result->fallbackReason);
  }

  /**
   * Confirms a remote draft that fails validation gets one repair attempt.
   */
  public function testRemoteProviderRepairsValidationErrors(): void {
    $remote = new GenerationCoordinatorRepairingProvider();
    $manager = $this->createMock(DefaultPluginManager::class);
    $manager->method('createInstance')->willReturnCallback(static fn (string $id): AiProviderInterface => $id === 'local'
      ? new LocalProvider([], 'local', [])
      : $remote);

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(7);
    $coordinator = new GenerationCoordinator(
      $manager,
      $this->configFactory(['provider' => 'remote']),
      new RecipeContractFactory(),
      new RecipeValidator([new TechnicalSafetyRule()]),
      new AiRateLimiter($this->state([]), $this->configFactory(['request_rate_limit_per_hour' => 20])),
      new AiUsageLedger($this->state([]), $this->configFactory(['audit_retention_days' => 30])),
      $account,
      $this->createMock(LoggerInterface::class),
    );

    $result = $coordinator->generate([
      'ingredients' => ['2 cups cauliflower florets', '1 cup water'],
      'appliances' => ['Instant Pot'],
    ]);

    self::assertSame('remote', $result->provider);
    self::assertNull($result->fallbackReason);
    self::assertCount(2, $remote->requests);
    self::assertArrayNotHasKey('revision_feedback', $remote->requests[0]);
    $feedback = $remote->requests[1]['revision_feedback'];
    self::assertSame('Steamed Cauliflower Bowl', $feedback['previous_recipe']['title']);
    self::assertStringContainsString('Steamed potato or cauliflower steps require', implode(' ', $feedback['problems']));
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
   * Builds a minimal in-memory state implementation.
   *
   * @param array<string, mixed> $values
   *   Initial state values.
   */
  private function state(array $values): StateInterface {
    return new GenerationCoordinatorTestState($values);
  }

}

/**
 * Small in-memory state store for generation coordinator tests.
 */
final class GenerationCoordinatorTestState implements StateInterface {

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
   *
   * @param string $key
   *   State key.
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return isset($this->values[$key]) && is_array($this->values[$key]) ? $this->values[$key] : NULL;
  }

}

/**
 * Remote provider double that only fixes its recipe after feedback.
 */
final class GenerationCoordinatorRepairingProvider implements AiProviderInterface {

  /**
   * Requests received, in order.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $requests = [];

  /**
   * {@inheritdoc}
   */
  public function generate(array $request): array {
    $this->requests[] = $request;
    $doneness = isset($request['revision_feedback']) ? ' until fork-tender' : '';
    return [
      'schema_version' => '1.0',
      'title' => 'Steamed Cauliflower Bowl',
      'description' => 'A vegan bowl with cauliflower, aromatics, and lime.',
      'servings' => 4,
      'prep_time_minutes' => 10,
      'cook_time_minutes' => 12,
      'cuisine' => 'Caribbean',
      'category' => 'Vegan',
      'ingredients' => [
        ['original_text' => '2 cups cauliflower florets'],
        ['original_text' => '1 cup water'],
      ],
      'instructions' => [
        ['text' => 'Steam cauliflower with 1 cup water' . $doneness . '.'],
      ],
      'appliance_settings' => [
        ['appliance' => 'Instant Pot', 'mode' => 'Steam', 'time' => '12 minutes'],
      ],
      'nutrition' => ['protein' => '8g'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return 'remote';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Plugin definition.
   */
  public function getPluginDefinition(): array {
    return [];
  }

}
