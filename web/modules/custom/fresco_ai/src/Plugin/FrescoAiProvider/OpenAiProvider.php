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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates strict recipe JSON through the OpenAI Responses API.
 */
#[AiProvider(
  id: 'openai',
  label: 'OpenAI Responses API',
  description: 'Uses strict structured outputs with an environment-provided API key.',
)]
final class OpenAiProvider extends PluginBase implements AiProviderInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the OpenAI provider.
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
   * @param \Psr\Log\LoggerInterface $logger
   *   Dresco AI logger.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PromptBuilder $promptBuilder,
    private readonly RecipeSchema $recipeSchema,
    private readonly LoggerInterface $logger,
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
      $container->get('logger.channel.fresco_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function generate(array $request): array {
    $apiKey = trim((string) getenv('OPENAI_API_KEY'));
    if ($apiKey === '') {
      throw new \RuntimeException('OPENAI_API_KEY is not configured.');
    }
    $model = (string) $this->configFactory->get('fresco_ai.settings')->get('openai_model');
    $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $model,
        'instructions' => $this->promptBuilder->systemInstruction(),
        'input' => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'text' => [
          'format' => [
            'type' => 'json_schema',
            'name' => 'fresco_recipe',
            'strict' => TRUE,
            'schema' => $this->recipeSchema->get(),
          ],
        ],
      ],
      'connect_timeout' => 10,
      'timeout' => 90,
      'http_errors' => FALSE,
    ]);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      $this->logger->warning('OpenAI recipe request failed with HTTP {status}.', [
        'status' => $response->getStatusCode(),
      ]);
      throw new \RuntimeException('The OpenAI provider returned an unsuccessful response.');
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $text = $this->outputText(is_array($payload) ? $payload : []);
    $recipe = json_decode($text, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($recipe)) {
      throw new \RuntimeException('The OpenAI provider returned invalid recipe data.');
    }
    return $recipe;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return trim((string) getenv('OPENAI_API_KEY')) !== '';
  }

  /**
   * Extracts structured output text from a Responses API payload.
   *
   * @param array<string, mixed> $payload
   *   Decoded Responses API payload.
   */
  private function outputText(array $payload): string {
    if (is_string($payload['output_text'] ?? NULL)) {
      return $payload['output_text'];
    }
    foreach (is_array($payload['output'] ?? NULL) ? $payload['output'] : [] as $output) {
      if (!is_array($output)) {
        continue;
      }
      foreach (is_array($output['content'] ?? NULL) ? $output['content'] : [] as $content) {
        if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? NULL)) {
          return $content['text'];
        }
      }
    }
    throw new \RuntimeException('The OpenAI response contained no structured output text.');
  }

}
