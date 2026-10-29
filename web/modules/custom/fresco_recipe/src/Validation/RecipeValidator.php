<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Runs all tagged recipe validation rules.
 */
final class RecipeValidator {

  /**
   * Constructs the recipe validator.
   *
   * @param iterable<\Drupal\fresco_recipe\Validation\ValidationRuleInterface> $rules
   *   Tagged recipe validation rules.
   */
  public function __construct(private readonly iterable $rules) {}

  /**
   * Validates a recipe using every registered rule.
   *
   * @return array<int, \Drupal\fresco_recipe\Validation\ValidationResult>
   *   Flattened validation results.
   */
  public function validate(RecipeContract $recipe): array {
    $results = [];
    foreach ($this->rules as $rule) {
      array_push($results, ...$rule->validate($recipe));
    }
    return $results;
  }

}
