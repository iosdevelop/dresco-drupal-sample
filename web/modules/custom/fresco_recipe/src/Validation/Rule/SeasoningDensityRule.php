<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\RecipeProfile;
use Drupal\fresco_recipe\Validation\Seasoning;
use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Requires enough spice for the amount of food in savory recipes.
 *
 * FlavorStructureRule only checks that a seasoning is present, so a whole
 * cabbage with one teaspoon of cumin passes it. This rule compares the pounds
 * of vegetables, legumes, and tofu with the teaspoons of spices, dried herbs,
 * and savory pastes in the ingredient list.
 */
final class SeasoningDensityRule implements ValidationRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    if (RecipeProfile::isSweet($recipe)) {
      return [];
    }
    $lines = array_map(
      static fn (array $ingredient): string => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
      $recipe->ingredients,
    );
    $pounds = Seasoning::pounds($lines);
    $teaspoons = Seasoning::teaspoons($lines);
    if ($pounds < Seasoning::MIN_POUNDS || $teaspoons >= $pounds * Seasoning::MIN_TSP_PER_POUND - 0.0001) {
      return [];
    }
    return [
      new ValidationResult(
        ruleId: 'flavor.seasoning_density',
        message: sprintf('The ingredient list has about %s pounds of vegetables, legumes, and tofu but only %s teaspoons of spices and dried herbs, so the dish will taste bland.', $this->format($pounds), $this->format($teaspoons)),
        component: 'ingredients',
        remediation: sprintf('Use at least %s teaspoons of spices and dried herbs in total. Toast most of them in oil with the aromatics, stir the rest in at the end, and add depth with smoked paprika, tamari, miso, or vinegar.', $this->format(ceil($pounds * Seasoning::TARGET_TSP_PER_POUND * 2) / 2)),
      ),
    ];
  }

  /**
   * Formats a number with at most one decimal place.
   */
  private function format(float $value): string {
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
  }

}
