<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

/**
 * Processes queued AI requests.
 */
final class AiRequestProcessor {

  public function __construct(
    private readonly GenerationCoordinator $generationCoordinator,
    private readonly IngredientImageAnalyzer $ingredientImageAnalyzer,
    private readonly AiUsageLedger $usageLedger,
  ) {}

  /**
   * Processes a queued AI request.
   *
   * @param array<string, mixed> $payload
   *   Queue payload.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  public function process(array $payload): array {
    $type = (string) ($payload['type'] ?? '');
    return match ($type) {
      'generate_test' => $this->processGenerateTest($payload),
      'image_test' => $this->processImageTest($payload),
      default => throw new \InvalidArgumentException('Unsupported AI queue request.'),
    };
  }

  /**
   * Builds a deterministic sample recipe request.
   *
   * @return array<string, mixed>
   *   Recipe generation request.
   */
  public function sampleGenerateRequest(): array {
    return [
      'mode' => 'ingredients',
      'menu_item' => 'vegetable bowl',
      'liked_notes' => 'Bright, savory, gently spiced, and practical for appliance cooking.',
      'cuisine_vibe' => 'vegan comfort food',
      'avoid_ingredients' => 'No dairy, no meat',
      'ingredient_policy' => 'suggest_missing',
      'ingredients' => ['potatoes', 'scallions', 'garlic', 'thyme', 'coconut milk'],
      'appliances' => ['instant pot'],
    ];
  }

  /**
   * Builds a tiny valid PNG sample for image-provider checks.
   */
  public function sampleImageBytes(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5uN0YAAAAASUVORK5CYII=', TRUE) ?: '';
  }

  /**
   * Processes a generation test.
   *
   * @param array<string, mixed> $payload
   *   Queue payload.
   *
   * @return array<string, mixed>
   *   Result summary.
   */
  private function processGenerateTest(array $payload): array {
    $result = $this->generationCoordinator->generate(is_array($payload['request'] ?? NULL) ? $payload['request'] : $this->sampleGenerateRequest());
    $this->usageLedger->record('generate_test', $result->provider, (int) ($payload['uid'] ?? 0), $result->fallbackReason === NULL ? 'ok' : 'fallback', $result->fallbackReason);
    return [
      'provider' => $result->provider,
      'fallback_reason' => $result->fallbackReason,
      'title' => $result->recipe->title,
    ];
  }

  /**
   * Processes an image-provider test.
   *
   * @param array<string, mixed> $payload
   *   Queue payload.
   *
   * @return array<string, mixed>
   *   Result summary.
   */
  private function processImageTest(array $payload): array {
    $image = base64_decode((string) ($payload['image_b64'] ?? ''), TRUE);
    $mimeType = (string) ($payload['mime_type'] ?? 'image/png');
    if ($image === FALSE || $image === '') {
      $image = $this->sampleImageBytes();
    }
    $candidates = $this->ingredientImageAnalyzer->analyze($image, $mimeType);
    $this->usageLedger->record('image_test', (string) ($payload['provider'] ?? 'image'), (int) ($payload['uid'] ?? 0), 'ok');
    return [
      'candidate_count' => count($candidates),
      'candidates' => $candidates,
    ];
  }

}
