<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Validates one concern in the recipe contract.
 */
interface ValidationRuleInterface {

  /**
   * Validates a recipe.
   *
   * @return array<int, \Drupal\fresco_recipe\Validation\ValidationResult>
   *   Validation failures, or an empty array.
   */
  public function validate(RecipeContract $recipe): array;

}
