<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Plugin\FrescoAiProvider;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\fresco_ai\Attribute\AiProvider;
use Drupal\fresco_ai\Service\PromptBuilder;
use Drupal\fresco_ai\Service\RecipeSchema;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates recipe JSON through a locally managed Ollama service.
 */
#[AiProvider(
  id: 'ollama',
  label: 'Ollama',
  description: 'Uses a configured loopback or private Ollama endpoint.',
)]
final class OllamaProvider extends PluginBase implements AiProviderInterface, ContainerFactoryPluginInterface {

  /**
   * Cached availability for the current request.
   */
  private ?bool $available = NULL;

  /**
   * Constructs the Ollama provider.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   * @param \Drupal\fresco_ai\Service\PromptBuilder $promptBuilder
   *   Recipe prompt builder.
   * @param \Drupal\fresco_ai\Service\RecipeSchema $recipeSchema
   *   Structured recipe schema.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PromptBuilder $promptBuilder,
    private readonly RecipeSchema $recipeSchema,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Service container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      (string) $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('fresco_ai.prompt_builder'),
      $container->get('fresco_ai.recipe_schema'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function generate(array $request): array {
    $config = $this->configFactory->get('fresco_ai.settings');
    $baseUrl = rtrim((string) $config->get('ollama_base_url'), '/');
    $response = $this->httpClient->request('POST', $baseUrl . '/api/generate', [
      'json' => [
        'model' => (string) $config->get('ollama_model'),
        'system' => $this->promptBuilder->systemInstruction(),
        'prompt' => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'stream' => FALSE,
        'format' => $this->recipeSchema->get(),
        // The default 4096-token window can truncate the system rules once a
        // repair request carries the previous draft and validator feedback.
        'options' => ['num_ctx' => 8192],
      ],
      'connect_timeout' => 5,
      // CPU-only inference plus a cold model load can take several minutes.
      'timeout' => 300,
      'http_errors' => FALSE,
    ]);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new \RuntimeException('The Ollama provider returned an unsuccessful response.');
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $text = is_array($payload) && is_string($payload['response'] ?? NULL) ? $payload['response'] : '';
    $recipe = json_decode($text, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($recipe)) {
      throw new \RuntimeException('The Ollama provider returned invalid recipe data.');
    }
    return $recipe;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    if ($this->available !== NULL) {
      return $this->available;
    }
    $config = $this->configFactory->get('fresco_ai.settings');
    $baseUrl = rtrim((string) $config->get('ollama_base_url'), '/');
    $model = trim((string) $config->get('ollama_model'));
    if ($baseUrl === '' || $model === '') {
      return $this->available = FALSE;
    }
    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/api/tags', [
        'connect_timeout' => 2,
        'timeout' => 5,
        'http_errors' => FALSE,
      ]);
      if ($response->getStatusCode() !== 200) {
        return $this->available = FALSE;
      }
      $payload = json_decode((string) $response->getBody(), TRUE);
      $installed = array_column(is_array($payload['models'] ?? NULL) ? $payload['models'] : [], 'name');
      // Ollama reports "llama3.1" as "llama3.1:latest".
      return $this->available = in_array($model, $installed, TRUE)
        || in_array($model . ':latest', $installed, TRUE);
    }
    catch (\Throwable) {
      return $this->available = FALSE;
    }
  }

}
