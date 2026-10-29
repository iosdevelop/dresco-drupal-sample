<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Identifies visible ingredients without adding them automatically.
 */
final class IngredientImageAnalyzer {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiRateLimiter $rateLimiter,
    private readonly AiUsageLedger $usageLedger,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Analyzes a validated image.
   *
   * @return array<int, array{name: string, quantity: string|null, confidence: string}>
   *   Reviewable ingredient candidates.
   */
  public function analyze(string $image, string $mimeType): array {
    if (strlen($image) < 1 || strlen($image) > 8 * 1024 * 1024) {
      throw new \InvalidArgumentException('Ingredient images must be no larger than 8 MB.');
    }
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], TRUE)) {
      throw new \InvalidArgumentException('Ingredient images must be JPEG, PNG, or WebP.');
    }
    $dimensions = getimagesizefromstring($image);
    if ($dimensions === FALSE || $dimensions[0] * $dimensions[1] > 25_000_000) {
      throw new \InvalidArgumentException('Ingredient image dimensions are invalid or too large.');
    }

    $config = $this->configFactory->get('fresco_ai.settings');
    $provider = (string) $config->get('image_provider');
    $uid = (int) $this->currentUser->id();
    $this->rateLimiter->assertAllowed('analyze_photo', $uid);
    try {
      $text = match ($provider) {
        'openai' => $this->openAi($image, $mimeType),
        'ollama' => $this->ollama($image),
        default => throw new \RuntimeException('Unsupported ingredient image provider.'),
      };
      $candidates = $this->normalize($text);
      $this->usageLedger->record('analyze_photo', $provider, $uid, 'ok');
      return $candidates;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Ingredient image analysis failed for {provider}: {message}', [
        'provider' => $provider,
        'message' => $exception->getMessage(),
      ]);
      $this->usageLedger->record('analyze_photo', $provider, $uid, 'error', $exception->getMessage());
      throw new \RuntimeException('Ingredient image analysis is unavailable. Check the configured provider.', 0, $exception);
    }
  }

  /**
   * Calls the OpenAI Responses API with a Base64 data URL.
   */
  private function openAi(string $image, string $mimeType): string {
    $apiKey = trim((string) getenv('OPENAI_API_KEY'));
    if ($apiKey === '') {
      throw new \RuntimeException('OPENAI_API_KEY is not configured.');
    }
    $config = $this->configFactory->get('fresco_ai.settings');
    $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => (string) $config->get('openai_vision_model'),
        'input' => [[
          'role' => 'user',
          'content' => [
            ['type' => 'input_text', 'text' => $this->prompt()],
            [
              'type' => 'input_image',
              'image_url' => 'data:' . $mimeType . ';base64,' . base64_encode($image),
            ],
          ],
        ],
        ],
        'text' => [
          'format' => [
            'type' => 'json_schema',
            'name' => 'visible_ingredients',
            'strict' => TRUE,
            'schema' => $this->schema(),
          ],
        ],
      ],
      'connect_timeout' => 10,
      'timeout' => 90,
      'http_errors' => FALSE,
    ]);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new \RuntimeException('OpenAI vision returned an unsuccessful response.');
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (is_string($payload['output_text'] ?? NULL)) {
      return $payload['output_text'];
    }
    foreach (is_array($payload['output'] ?? NULL) ? $payload['output'] : [] as $output) {
      foreach (is_array($output['content'] ?? NULL) ? $output['content'] : [] as $content) {
        if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? NULL)) {
          return $content['text'];
        }
      }
    }
    throw new \RuntimeException('OpenAI vision returned no structured output.');
  }

  /**
   * Calls an Ollama vision model with Base64 image bytes.
   */
  private function ollama(string $image): string {
    $config = $this->configFactory->get('fresco_ai.settings');
    $response = $this->httpClient->request(
      'POST',
      rtrim((string) $config->get('ollama_base_url'), '/') . '/api/generate',
      [
        'json' => [
          'model' => (string) $config->get('ollama_vision_model'),
          'prompt' => $this->prompt(),
          'images' => [base64_encode($image)],
          'format' => $this->schema(),
          'stream' => FALSE,
        ],
        'connect_timeout' => 5,
        'timeout' => 120,
        'http_errors' => FALSE,
      ],
    );
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new \RuntimeException('Ollama vision returned an unsuccessful response.');
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['response'] ?? NULL)) {
      throw new \RuntimeException('Ollama vision returned no result.');
    }
    return $payload['response'];
  }

  /**
   * Normalizes structured provider output.
   *
   * @return array<int, array{name: string, quantity: string|null, confidence: string}>
   *   Ingredient candidates.
   */
  private function normalize(string $text): array {
    $decoded = json_decode(trim($text), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !is_array($decoded['ingredients'] ?? NULL)) {
      throw new \RuntimeException('The image provider returned an invalid ingredient list.');
    }
    $ingredients = [];
    foreach ($decoded['ingredients'] as $item) {
      if (!is_array($item) || trim((string) ($item['name'] ?? '')) === '') {
        continue;
      }
      $confidence = (string) ($item['confidence'] ?? 'low');
      $ingredients[] = [
        'name' => trim((string) $item['name']),
        'quantity' => isset($item['quantity']) && trim((string) $item['quantity']) !== ''
          ? trim((string) $item['quantity'])
          : NULL,
        'confidence' => in_array($confidence, ['high', 'medium', 'low'], TRUE) ? $confidence : 'low',
      ];
    }
    return $ingredients;
  }

  /**
   * Returns the conservative recognition instruction.
   */
  private function prompt(): string {
    return 'Identify only food ingredients clearly visible in this image or explicitly named on a readable label. '
      . 'Do not infer hidden contents or likely recipe ingredients. Include quantity only when it is reasonably visible. '
      . 'Use high confidence only for clear evidence and return an empty list rather than guessing.';
  }

  /**
   * Returns the strict detected-ingredient schema.
   *
   * @return array<string, mixed>
   *   Ingredient detection schema.
   */
  private function schema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'ingredients' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'name' => ['type' => 'string'],
              'quantity' => ['type' => ['string', 'null']],
              'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['name', 'quantity', 'confidence'],
            'additionalProperties' => FALSE,
          ],
        ],
      ],
      'required' => ['ingredients'],
      'additionalProperties' => FALSE,
    ];
  }

}
