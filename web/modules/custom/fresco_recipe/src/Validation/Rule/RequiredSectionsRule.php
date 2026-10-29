<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Requires the sections needed for a cookable recipe.
 */
final class RequiredSectionsRule implements ValidationRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $results = [];
    foreach ([
      'ingredients' => $recipe->ingredients,
      'instructions' => $recipe->instructions,
      'appliance_settings' => $recipe->applianceSettings,
    ] as $component => $items) {
      if ($items === []) {
        $results[] = new ValidationResult(
          ruleId: 'required.' . $component,
          message: ucfirst(str_replace('_', ' ', $component)) . ' are required.',
          component: $component,
          remediation: 'Add at least one complete item before publishing.',
        );
      }
    }
    return $results;
  }

}
