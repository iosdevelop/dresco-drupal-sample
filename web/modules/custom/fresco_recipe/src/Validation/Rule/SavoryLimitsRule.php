<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\RecipeProfile;
use Drupal\fresco_recipe\Validation\Sweetener;
use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Enforces Dresco's added-sugar limits.
 *
 * Savory recipes allow a small total of added sugar to balance acid and heat.
 * Sweet recipes, as classified by RecipeProfile, get a per-serving sweetener
 * budget instead. Salt is left to the cook's taste.
 */
final class SavoryLimitsRule implements ValidationRuleInterface {

  /**
   * Maximum added sweetener, in teaspoons per serving, for sweet recipes.
   */
  public const SWEET_TSP_PER_SERVING = 3.0;

  /**
   * Maximum added sweetener, in teaspoons for the whole savory recipe.
   */
  public const SAVORY_TSP_TOTAL = 2.0;

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $sweetenerTsp = $this->addedSweetenerTeaspoons($recipe->ingredients);
    if (RecipeProfile::isSweet($recipe)) {
      $servings = max(1, $recipe->servings);
      if ($sweetenerTsp / $servings > self::SWEET_TSP_PER_SERVING + 0.0001) {
        $limit = self::SWEET_TSP_PER_SERVING * $servings;
        return [
          new ValidationResult(
            ruleId: 'nutrition.added_sugar_sweet',
            message: sprintf('Sweet recipes may use no more than %s teaspoons of added sweetener per serving (%s teaspoons for %d servings).', self::SWEET_TSP_PER_SERVING, $limit, $servings),
            component: 'ingredients',
            remediation: sprintf('Reduce added sweetener to %s teaspoons or less and lean on ripe fruit, dates, vanilla, or warm spices for sweetness.', $limit),
          ),
        ];
      }
      return [];
    }
    if ($sweetenerTsp > self::SAVORY_TSP_TOTAL + 0.0001) {
      return [
        new ValidationResult(
          ruleId: 'nutrition.added_sugar',
          message: sprintf('Savory recipes may use no more than %s teaspoons of added sugar.', self::SAVORY_TSP_TOTAL),
          component: 'ingredients',
          remediation: 'Use at most 2 teaspoons of sugar or maple syrup, only to balance acid or heat.',
        ),
      ];
    }
    return [];
  }

  /**
   * Sums added sweetener across ingredient lines, in teaspoons.
   *
   * @param array<int, array<string, mixed>> $ingredients
   *   Structured ingredients.
   */
  private function addedSweetenerTeaspoons(array $ingredients): float {
    return array_sum(array_map(
      static fn (array $ingredient): float => Sweetener::teaspoons((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? '')) ?? 0.0,
      $ingredients,
    ));
  }

}
