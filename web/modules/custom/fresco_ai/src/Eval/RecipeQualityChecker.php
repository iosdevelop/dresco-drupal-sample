<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Eval;

use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Scores what a cook would notice and the validator does not check.
 *
 * Each check returns NULL when it does not apply, or a list of problems
 * (empty when it passes).
 */
final class RecipeQualityChecker {

  /**
   * Foods a recipe should not use in its method without listing them.
   *
   * Water, salt, pepper, and cooking oil are assumed pantry basics.
   */
  private const TRACKED_FOODS = [
    'garlic', 'onion', 'scallion', 'shallot', 'leek', 'ginger', 'lemongrass',
    'thyme', 'allspice', 'cumin', 'coriander', 'paprika', 'turmeric', 'curry',
    'cinnamon', 'nutmeg', 'cardamom', 'oregano', 'basil', 'rosemary',
    'cilantro', 'parsley', 'dill', 'mint', 'chili', 'chile', 'cayenne',
    'lime', 'lemon', 'orange', 'vinegar', 'soy sauce', 'tamari', 'miso',
    'sesame', 'peanut', 'coconut', 'tomato', 'carrot', 'celery', 'mushroom',
    'spinach', 'kale', 'tofu', 'tempeh', 'rice', 'quinoa', 'noodle', 'pasta',
    'bean', 'chickpea', 'lentil', 'potato', 'corn', 'vanilla', 'maple syrup',
    'sugar', 'flour', 'oat', 'banana', 'berry', 'berries',
  ];

  /**
   * Method verbs expected for each appliance, keyed by a label pattern.
   */
  private const APPLIANCE_METHODS = [
    '/no[- ]cook/i' => '/\b(?:toss|combine|mix|whisk|stir|marinat|chill|assembl|layer)\w*/',
    '/blender/i' => '/\bblend\w*/',
    '/air fryer/i' => '/\bair[- ]?fr(?:y|ie)\w*/',
    '/instant pot|pressure/i' => '/\b(?:pressure cook|sauté|saute|steam|slow cook|sous vide|bake|high pressure)\w*/u',
    '/rice cooker/i' => '/\b(?:rice cooker|cook cycle|white rice setting|brown rice setting)\b/',
    '/oven/i' => '/\b(?:bake|roast|broil)\w*/',
    '/stovetop|stove/i' => '/\b(?:simmer|sauté|saute|boil|stir-fr|fry|sear|toast|cook)\w*/u',
  ];

  /**
   * Heat methods that contradict a no-cook request.
   */
  private const HEAT_METHODS = '/\b(?:simmer|sauté|saute|boil|bake|roast|fry|fried|sear|toast|steam|pressure cook|microwave|grill)\w*/u';

  /**
   * Runs every check.
   *
   * @param array<string, mixed> $request
   *   The generation request after substitutions.
   * @param \Drupal\fresco_recipe\Value\RecipeContract $recipe
   *   The generated recipe.
   * @param string|null $titlePattern
   *   Optional regular expression the title must match.
   *
   * @return array<string, array<int, string>|null>
   *   Problems per check; NULL when a check does not apply.
   */
  public function check(array $request, RecipeContract $recipe, ?string $titlePattern = NULL): array {
    return [
      'uses_ingredients' => $this->unusedIngredients($request, $recipe),
      'no_unlisted' => $this->unlistedFoods($recipe),
      'method' => $this->methodProblems($request, $recipe),
      'title' => $titlePattern === NULL ? NULL : (
        preg_match($titlePattern, $recipe->title) === 1 ? [] : [sprintf('"%s" does not match %s', $recipe->title, $titlePattern)]
      ),
    ];
  }

  /**
   * Lists entered ingredients the method never uses.
   *
   * @param array<string, mixed> $request
   *   The generation request.
   * @param \Drupal\fresco_recipe\Value\RecipeContract $recipe
   *   The generated recipe.
   *
   * @return array<int, string>|null
   *   Unused ingredient names, or NULL when nothing was entered.
   */
  public function unusedIngredients(array $request, RecipeContract $recipe): ?array {
    $entered = is_array($request['ingredients'] ?? NULL) ? $request['ingredients'] : [];
    if ($entered === []) {
      return NULL;
    }
    $method = $this->methodText($recipe);
    $unused = [];
    foreach ($entered as $line) {
      $noun = self::headNoun((string) $line);
      // "berry" must still match "berries".
      $stem = preg_replace('/y$/', '', $noun) ?? $noun;
      if ($noun !== '' && !str_contains($method, $stem)) {
        $unused[] = $noun;
      }
    }
    return $unused;
  }

  /**
   * Lists tracked foods the method uses but the ingredient list omits.
   *
   * @return array<int, string>
   *   Unlisted food names.
   */
  public function unlistedFoods(RecipeContract $recipe): array {
    $method = $this->methodText($recipe);
    $listed = strtolower(implode(' ', array_map(
      static fn (array $ingredient): string => (string) ($ingredient['original_text'] ?? '') . ' ' . (string) ($ingredient['food'] ?? ''),
      $recipe->ingredients,
    )));
    $unlisted = [];
    foreach (self::TRACKED_FOODS as $food) {
      $pattern = '/\b' . preg_quote($food, '/') . '(?:e?s)?\b/';
      if (preg_match($pattern, $method) === 1 && preg_match($pattern, $listed) !== 1) {
        $unlisted[] = $food;
      }
    }
    return $unlisted;
  }

  /**
   * Checks that the method fits the selected appliances.
   *
   * @param array<string, mixed> $request
   *   The generation request.
   * @param \Drupal\fresco_recipe\Value\RecipeContract $recipe
   *   The generated recipe.
   *
   * @return array<int, string>|null
   *   Problems, or NULL when no known appliance was selected.
   */
  public function methodProblems(array $request, RecipeContract $recipe): ?array {
    $appliances = is_array($request['appliances'] ?? NULL) ? array_map('strval', $request['appliances']) : [];
    $method = $this->methodText($recipe);
    $expected = [];
    foreach ($appliances as $appliance) {
      foreach (self::APPLIANCE_METHODS as $appliancePattern => $verbs) {
        if (preg_match($appliancePattern, $appliance) === 1) {
          $expected[$appliance] = $verbs;
          break;
        }
      }
    }
    if ($expected === []) {
      return NULL;
    }

    $problems = [];
    $matched = array_filter($expected, static fn (string $verbs): bool => preg_match($verbs, $method) === 1);
    if ($matched === []) {
      $problems[] = 'method uses none of the selected appliances (' . implode(', ', array_keys($expected)) . ')';
    }
    $noCookOnly = array_filter($appliances, static fn (string $appliance): bool => preg_match('/no[- ]cook/i', $appliance) !== 1) === [];
    if ($noCookOnly && preg_match(self::HEAT_METHODS, $method, $heat) === 1) {
      $problems[] = sprintf('no-cook recipe uses heat ("%s")', $heat[0]);
    }
    return $problems;
  }

  /**
   * Reduces an ingredient line to a lowercase singular head noun.
   *
   * "1 can black beans, drained" becomes "bean"; "3 ripe bananas" becomes
   * "banana".
   */
  public static function headNoun(string $line): string {
    $text = strtolower($line);
    $text = preg_replace('/[(,].*$/', '', $text) ?? $text;
    $text = preg_replace('/^[\d\s\/.\-]+/', '', $text) ?? $text;
    $text = preg_replace('/^(?:(?:cups?|tbsp|tsp|tablespoons?|teaspoons?|oz|ounces?|lbs?|pounds?|grams?|g|kg|ml|cans?|jars?|cartons?|packages?|packets?|bags?|cloves?|bunch(?:es)?|heads?|crowns?|pinch(?:es)?|large|medium|small|of)\s+)+/', '', $text) ?? $text;
    $words = preg_split('/[^a-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // "1 broccoli crown" is broccoli; the crown is just the unit.
    while (count($words) > 1 && preg_match('/^(?:crowns?|heads?|bunch(?:es)?|stalks?|sprigs?|cloves?|pieces?|blocks?|fillets?)$/', (string) end($words)) === 1) {
      array_pop($words);
    }
    $noun = (string) end($words);
    return match (TRUE) {
      $noun === '' => '',
      str_ends_with($noun, 'ies') => substr($noun, 0, -3) . 'y',
      str_ends_with($noun, 'oes') => substr($noun, 0, -2),
      preg_match('/[^su]s$/', $noun) === 1 => substr($noun, 0, -1),
      default => $noun,
    };
  }

  /**
   * Returns the lowercase instruction text.
   */
  private function methodText(RecipeContract $recipe): string {
    return strtolower(implode(' ', array_map(
      static fn (array $instruction): string => (string) ($instruction['text'] ?? ''),
      $recipe->instructions,
    )));
  }

}
