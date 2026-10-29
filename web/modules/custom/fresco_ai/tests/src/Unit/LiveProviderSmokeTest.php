<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\OpenAiProvider;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\OllamaProvider;
use Drupal\fresco_ai\Service\PromptBuilder;
use Drupal\fresco_ai\Service\RecipeSchema;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use Psr\Log\NullLogger;

/**
 * Exercises live AI provider credentials when they are available.
 */
final class LiveProviderSmokeTest extends UnitTestCase {

  /**
   * Confirms a live provider can return a recipe when credentials exist.
   */
  public function testLiveProviderCanGenerate(): void {
    $providerId = $this->providerId();
    if ($providerId === NULL) {
      self::markTestSkipped('No live AI provider credentials are configured.');
    }

    $client = new Client();
    $promptBuilder = new PromptBuilder();
    $recipeSchema = new RecipeSchema();
    $logger = new NullLogger();

    $provider = match ($providerId) {
      'openai' => new OpenAiProvider(
        [],
        'openai',
        [],
        $client,
        $this->configFactory([
          'openai_model' => $this->requiredEnv('FRESCO_OPENAI_MODEL', 'gpt-4.1-mini'),
        ]),
        $promptBuilder,
        $recipeSchema,
        $logger,
      ),
      'ollama' => new OllamaProvider(
        [],
        'ollama',
        [],
        $client,
        $this->configFactory([
          'ollama_base_url' => $this->requiredEnv('FRESCO_OLLAMA_BASE_URL'),
          'ollama_model' => $this->requiredEnv('FRESCO_OLLAMA_MODEL'),
        ]),
        $promptBuilder,
        $recipeSchema,
      ),
      default => NULL,
    };

    if ($provider === NULL) {
      self::markTestSkipped('Unsupported live provider selection.');
    }

    $recipe = $provider->generate([
      'ingredients' => ['1 cup okra', '1 cup coconut milk', '1 lime'],
      'appliances' => ['Instant Pot'],
      'intent' => 'vegan island stew',
    ]);

    self::assertNotSame('', trim((string) ($recipe['title'] ?? '')));
    self::assertIsArray($recipe['ingredients'] ?? NULL);
    self::assertIsArray($recipe['instructions'] ?? NULL);
    self::assertIsArray($recipe['appliance_settings'] ?? NULL);
  }

  /**
   * Determines which provider to run.
   */
  private function providerId(): ?string {
    $forced = strtolower(trim((string) getenv('FRESCO_LIVE_AI_PROVIDER')));
    if (in_array($forced, ['openai', 'ollama'], TRUE)) {
      return $forced;
    }

    if (trim((string) getenv('OPENAI_API_KEY')) !== '') {
      return 'openai';
    }

    if (trim((string) getenv('FRESCO_OLLAMA_BASE_URL')) !== '' && trim((string) getenv('FRESCO_OLLAMA_MODEL')) !== '') {
      return 'ollama';
    }

    return NULL;
  }

  /**
   * Returns a required env var or a fallback default.
   */
  private function requiredEnv(string $name, ?string $default = NULL): string {
    $value = trim((string) getenv($name));
    if ($value !== '') {
      return $value;
    }
    if ($default !== NULL) {
      return $default;
    }

    self::markTestSkipped(sprintf('Environment variable %s is not configured.', $name));
  }

  /**
   * Builds a config factory mock.
   *
   * @param array<string, mixed> $values
   *   Config values.
   */
  private function configFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

}
