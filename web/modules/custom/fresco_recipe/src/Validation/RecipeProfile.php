<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Classifies a recipe as sweet or savory so rules can apply the right limits.
 */
final class RecipeProfile {

  /**
   * Dish names that make a recipe sweet regardless of its ingredients.
   */
  private const SWEET_DISH_PATTERN = '/\b(?:cakes?|cupcakes?|cookies?|muffins?|brownies?|blondies?|pies?|puddings?|smoothies?|shakes?|oatmeal|overnight oats|porridge|pancakes?|waffles?|crumbles?|cobblers?|banana bread|zucchini bread|sweet bread|quick bread|desserts?|granola|parfaits?|compotes?|sorbets?|nice cream|ice cream|truffles?|energy (?:balls?|bites?)|scones?|cinnamon rolls?|french toast|jam|chia pudding|fruit salad)\b/';

  /**
   * Phrases that keep a "sweet" match savory, such as "pot pie".
   */
  private const SAVORY_OVERRIDE_PATTERN = '/\b(?:pot pie|shepherd\'?s pie|savou?ry|cornbread stuffing|potato cakes?|crab cakes?|fritters?|rice cakes?|corn cakes?|cornmeal cakes?)\b/';

  /**
   * Returns TRUE when the recipe is a dessert, baked sweet, or sweet drink.
   */
  public static function isSweet(RecipeContract $recipe): bool {
    return self::isSweetText($recipe->title, $recipe->description);
  }

  /**
   * Returns TRUE when a title and description name a sweet dish.
   */
  public static function isSweetText(string $title, string $description): bool {
    $text = strtolower($title . ' ' . $description);
    // "Sweet potato" names an ingredient, not a sweet dish.
    $text = preg_replace('/\bsweet potato(?:es)?\b/', '', $text) ?? $text;
    if (preg_match(self::SAVORY_OVERRIDE_PATTERN, $text) === 1) {
      return FALSE;
    }
    return preg_match(self::SWEET_DISH_PATTERN, $text) === 1;
  }

}
