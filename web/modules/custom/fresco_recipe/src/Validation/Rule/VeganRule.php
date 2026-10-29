<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Rejects unqualified animal products in the ingredient list.
 */
final class VeganRule implements ValidationRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $animalTerms = '(?:chicken|beef|pork|fish|shrimp|egg|bacon|milk|cheese|butter|honey|gelatin|lard)';
    $veganQualifier = '(?:vegan|plant[- ]based|soy|tofu|seitan|tempeh|mushroom|jackfruit|coconut|cashew|almond|oat|flax)';
    foreach ($recipe->ingredients as $ingredient) {
      $text = strtolower((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''));
      $text = preg_replace('/\begg[- ]?plant\b/', 'eggplant', $text) ?? $text;
      // "Vegan" may sit one word back, as in "vegan cream cheese".
      $withoutSubstitutes = preg_replace('/\b(?:vegan|plant[- ]based|dairy[- ]free)\s+[a-z]+\s+' . $animalTerms . 's?\b/', '', $text) ?? $text;
      $withoutSubstitutes = preg_replace('/\b' . $veganQualifier . '\s+' . $animalTerms . 's?\b/', '', $withoutSubstitutes) ?? $withoutSubstitutes;
      $withoutSubstitutes = preg_replace('/\b' . $animalTerms . '\s+(?:style|alternative|substitute|replacement)\b/', '', $withoutSubstitutes) ?? $withoutSubstitutes;
      if (preg_match('/\b' . $animalTerms . 's?\b/', $withoutSubstitutes) === 1) {
        return [new ValidationResult(
          ruleId: 'vegan.ingredients',
          message: 'Recipe ingredients must remain vegan.',
          component: 'ingredients',
          remediation: 'Remove the animal product or identify an explicit vegan substitute.',
          ),
        ];
      }
    }
    return [];
  }

}
