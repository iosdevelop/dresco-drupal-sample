<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Value;

use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Carries a generated recipe and auditable provider outcome.
 */
final readonly class GenerationResult {

  /**
   * Constructs a generation result.
   *
   * @param \Drupal\fresco_recipe\Value\RecipeContract $recipe
   *   The validated recipe.
   * @param string $provider
   *   The provider that produced the recipe.
   * @param string|null $fallbackReason
   *   Why the configured provider was not used, if it was not.
   * @param array<int, array{from: string, to: string, reason: string}> $substitutions
   *   Entered ingredient lines rewritten before generation; reason is
   *   "vegan" or "sweetener".
   */
  public function __construct(
    public RecipeContract $recipe,
    public string $provider,
    public ?string $fallbackReason = NULL,
    public array $substitutions = [],
  ) {}

}
