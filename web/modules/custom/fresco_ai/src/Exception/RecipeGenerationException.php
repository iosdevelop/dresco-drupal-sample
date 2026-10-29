<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Exception;

/**
 * Signals that recipe generation failed validation after fallback.
 */
final class RecipeGenerationException extends \RuntimeException {

  /**
   * Constructs a recipe generation exception.
   *
   * @param string $message
   *   Exception message.
   * @param array<int, \Drupal\fresco_recipe\Validation\ValidationResult> $validationResults
   *   Validation results from the failed generation attempt.
   * @param string|null $providerId
   *   Provider that produced the invalid result.
   * @param string|null $fallbackReason
   *   Optional fallback reason from the upstream provider.
   * @param \Throwable|null $previous
   *   Optional previous throwable.
   */
  public function __construct(
    string $message,
    private readonly array $validationResults = [],
    private readonly ?string $providerId = NULL,
    private readonly ?string $fallbackReason = NULL,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Returns the validation results that blocked generation.
   *
   * @return array<int, \Drupal\fresco_recipe\Validation\ValidationResult>
   *   Validation results.
   */
  public function getValidationResults(): array {
    return $this->validationResults;
  }

  /**
   * Returns the provider that produced the invalid recipe.
   */
  public function getProviderId(): ?string {
    return $this->providerId;
  }

  /**
   * Returns the upstream fallback reason, if any.
   */
  public function getFallbackReason(): ?string {
    return $this->fallbackReason;
  }

}
