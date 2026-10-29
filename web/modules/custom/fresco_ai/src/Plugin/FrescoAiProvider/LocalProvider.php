<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Plugin\FrescoAiProvider;

use Drupal\Component\Plugin\PluginBase;
use Drupal\fresco_ai\Attribute\AiProvider;
use Drupal\fresco_ai\Fallback\DishContext;
use Drupal\fresco_ai\Fallback\FallbackDishComposer;
use Drupal\fresco_ai\Fallback\TitleFlair;
use Drupal\fresco_recipe\Validation\RecipeProfile;
use Drupal\fresco_recipe\Validation\Seasoning;

/**
 * Deterministic provider used for fallback and offline development.
 */
#[AiProvider(
  id: 'local',
  label: 'Deterministic local provider',
  description: 'Produces a safe baseline recipe without a remote model.',
)]
final class LocalProvider extends PluginBase implements AiProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function generate(array $request): array {
    $ingredients = array_values(array_filter(array_map(
      static fn (mixed $ingredient): string => trim((string) $ingredient),
      is_array($request['ingredients'] ?? NULL) ? $request['ingredients'] : [],
    )));
    $ingredients = $this->ingredientsForRequest($ingredients, $request);
    if ($ingredients === [] && ($request['mode'] ?? '') === 'restaurant') {
      throw new \RuntimeException(sprintf(
        'The offline recipe generator only knows a few restaurant dishes and cannot invent "%s" on its own. List a few ingredients you would like, or check that the AI provider is running.',
        trim((string) ($request['menu_item'] ?? '')),
      ));
    }
    $main = $this->titleMainIngredient($ingredients);
    $appliances = array_values(array_filter(array_map(
      static fn (mixed $appliance): string => trim((string) $appliance),
      is_array($request['appliances'] ?? NULL) ? $request['appliances'] : [],
    )));
    $usesInstantPot = count(array_filter(
      $appliances,
      static fn (string $appliance): bool => str_contains(strtolower($appliance), 'instant pot'),
    )) > 0;
    $grainCups = $this->estimateDryGrainCups($ingredients);
    $liquidCups = max(1.0, $grainCups);
    $liquidAmount = $this->formatCupAmount($liquidCups);
    $hasDryPasta = $this->hasDryPasta($ingredients);
    $hasCannedLegumes = $this->hasCannedLegumes($ingredients);
    $isRiceAndPeas = $this->isRiceAndPeas($ingredients);
    $cuisine = $this->requestedCuisine($request);
    $titleCuisine = $isRiceAndPeas ? 'Caribbean' : $cuisine;
    $menuItem = trim((string) ($request['menu_item'] ?? ''));
    $isRestaurant = ($request['mode'] ?? '') === 'restaurant';
    $isStirFryNoodleDish = $isRestaurant && $this->isStirFryNoodleDish($menuItem . ' ' . implode(' ', $ingredients));
    $isSamosaDish = $isRestaurant && $this->isSamosaDish($menuItem . ' ' . implode(' ', $ingredients));
    $isSweetPotatoCornmealCake = $this->isSweetPotatoCornmealCake($ingredients);
    $description = $this->recipeDescription($cuisine, $isRiceAndPeas, $isStirFryNoodleDish, $isSamosaDish, $isSweetPotatoCornmealCake);
    $cookTime = $this->recipeCookTime($isRiceAndPeas, $isStirFryNoodleDish, $isSamosaDish, $isSweetPotatoCornmealCake, $hasDryPasta, $usesInstantPot);
    $finishInstruction = $this->finishInstruction($isStirFryNoodleDish, $isSamosaDish);

    $recipe = [
      'schema_version' => '1.0',
      'title' => $this->recipeTitle($main, $titleCuisine, $menuItem, $isRiceAndPeas, $isRestaurant, $isStirFryNoodleDish, $isSamosaDish, $isSweetPotatoCornmealCake, $ingredients),
      'description' => $description,
      'servings' => 4,
      'prep_time_minutes' => 15,
      'cook_time_minutes' => $cookTime,
      'cuisine' => $isRiceAndPeas ? 'Caribbean' : $cuisine,
      'category' => 'Vegan',
      'ingredients' => array_map(
        static fn (string $ingredient): array => [
          'key' => hash('sha256', strtolower($ingredient)),
          'food' => $ingredient,
          'original_text' => $ingredient,
        ],
        $ingredients,
      ),
      'instructions' => [
            [
              'position' => 1,
              'text' => $this->openingFlavorInstruction(
                $cuisine,
                $isStirFryNoodleDish,
                $isSamosaDish,
                $isSweetPotatoCornmealCake,
              ),
            ],
            [
              'position' => 2,
              'text' => $this->mainCookingInstruction(
                $ingredients,
                $appliances,
                $usesInstantPot,
                $grainCups,
                $liquidAmount,
                $liquidCups,
                $hasDryPasta,
                $hasCannedLegumes,
                $isRiceAndPeas,
                $isStirFryNoodleDish,
                $isSamosaDish,
                $isSweetPotatoCornmealCake,
              ),
            ],
            ['position' => 3, 'text' => $finishInstruction],
      ],
      'appliance_settings' => array_map(
            fn (string $appliance): array => [
              'appliance' => $appliance,
              'mode' => $this->applianceMode($appliance, $isSamosaDish, $isStirFryNoodleDish, $isSweetPotatoCornmealCake, $grainCups, $hasDryPasta),
              'time' => $this->applianceTime($appliance, $isRiceAndPeas, $isSamosaDish, $isStirFryNoodleDish, $isSweetPotatoCornmealCake, $usesInstantPot, $hasDryPasta),
            ],
            $appliances,
      ),
      'nutrition' => $this->nutritionEstimate($ingredients),
    ];
    if (!$isRiceAndPeas && !$isStirFryNoodleDish && !$isSamosaDish && !$isSweetPotatoCornmealCake && !$hasDryPasta) {
      $composed = (new FallbackDishComposer())->compose($ingredients, $appliances, $cuisine);
      if ($composed !== NULL) {
        return $this->withIngredients(array_merge($recipe, $composed, [
          'instructions' => $this->positioned($composed['instructions']),
        ]));
      }
    }
    return $this->withListedFlavor($recipe, $ingredients, $isRiceAndPeas ? 'Caribbean' : $cuisine, $isStirFryNoodleDish, $isSamosaDish, $isSweetPotatoCornmealCake);
  }

  /**
   * Rewrites the opening and finishing steps so every flavor item is listed.
   *
   * The opening and finish steps used to name garlic, scallions, thyme,
   * allspice, lime, and herbs whether or not the recipe listed them. They now
   * use the cuisine's profile and add whatever they mention to the list.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data built by the provider's own paths.
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   * @param string $cuisine
   *   Cuisine for the flavor profile.
   * @param bool $isStirFryNoodleDish
   *   TRUE for restaurant noodle dishes.
   * @param bool $isSamosaDish
   *   TRUE for restaurant samosas, which keep their own flavor steps.
   * @param bool $isSweetPotatoCornmealCake
   *   TRUE for cornmeal cakes, which keep their own flavor steps.
   *
   * @return array<string, mixed>
   *   Recipe data with listed flavor ingredients.
   */
  private function withListedFlavor(array $recipe, array $ingredients, string $cuisine, bool $isStirFryNoodleDish, bool $isSamosaDish, bool $isSweetPotatoCornmealCake): array {
    if ($isSamosaDish || $isSweetPotatoCornmealCake) {
      return $recipe;
    }
    $composer = new FallbackDishComposer();
    $dish = new DishContext($ingredients, $composer->profile($isStirFryNoodleDish && $cuisine === 'Global' ? 'Asian' : $cuisine), $cuisine);
    $aromatics = $dish->join($dish->aromatic(2));
    $spices = $dish->join($dish->spice(2));
    $bright = $dish->bright();
    $herb = $dish->herb();
    $recipe['instructions'][0]['text'] = $isStirFryNoodleDish
      ? sprintf('Sauté %s over high heat until fragrant.', $aromatics)
      : sprintf('Sauté %s with %s until fragrant.', $aromatics, $spices);
    $last = array_key_last($recipe['instructions']);
    $recipe['instructions'][$last]['text'] = $isStirFryNoodleDish
      ? sprintf('Finish with %s, %s, and a final dash of sesame oil; season with %s to taste.', $bright, $herb, $spices)
      : sprintf('Finish with %s and %s; adjust seasoning to taste.', $bright, $herb);
    $recipe['ingredients'] = $dish->lines();
    return $this->withIngredients($recipe);
  }

  /**
   * Converts ingredient lines to structured ingredients and nutrition.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data whose ingredients may be plain lines.
   *
   * @return array<string, mixed>
   *   Recipe data with structured ingredients.
   */
  private function withIngredients(array $recipe): array {
    $lines = array_map(
      static fn (mixed $ingredient): string => is_array($ingredient) ? (string) ($ingredient['original_text'] ?? '') : (string) $ingredient,
      $recipe['ingredients'],
    );
    if (!RecipeProfile::isSweetText((string) $recipe['title'], (string) $recipe['description'])) {
      $lines = Seasoning::scaleToWeight($lines);
    }
    $recipe['ingredients'] = array_map(
      static fn (string $ingredient): array => [
        'key' => hash('sha256', strtolower($ingredient)),
        'food' => $ingredient,
        'original_text' => $ingredient,
      ],
      $lines,
    );
    $recipe['nutrition'] = $this->nutritionEstimate($lines);
    return $recipe;
  }

  /**
   * Numbers instruction texts.
   *
   * @param array<int, string> $texts
   *   Instruction texts.
   *
   * @return array<int, array{position: int, text: string}>
   *   Positioned instructions.
   */
  private function positioned(array $texts): array {
    return array_map(
      static fn (int $index, string $text): array => ['position' => $index + 1, 'text' => $text],
      array_keys($texts),
      $texts,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * Builds the recipe description for the chosen dish shape.
   */
  private function recipeDescription(string $cuisine, bool $isRiceAndPeas, bool $isStirFryNoodleDish, bool $isSamosaDish, bool $isSweetPotatoCornmealCake): string {
    if ($isRiceAndPeas) {
      return 'A vegan rice and peas dish with canned kidney beans, coconut milk, thyme, and warm spice.';
    }
    if ($isStirFryNoodleDish) {
      return 'A savory vegan ' . $cuisine . '-inspired noodle stir-fry with crisp vegetables, glossy sauce, and a bright finish.';
    }
    if ($isSamosaDish) {
      return 'A savory vegan ' . $cuisine . '-inspired samosa with spiced vegetables, tender pastry, and a bright chutney-style finish.';
    }
    if ($isSweetPotatoCornmealCake) {
      return 'Savory vegan ' . $cuisine . '-inspired sweet potato cornmeal cakes with warm spices and a bright finish.';
    }
    return 'A savory vegan ' . $cuisine . '-inspired stew with layered aromatics and a bright finish.';
  }

  /**
   * Chooses the recipe cook time in minutes.
   */
  private function recipeCookTime(bool $isRiceAndPeas, bool $isStirFryNoodleDish, bool $isSamosaDish, bool $isSweetPotatoCornmealCake, bool $hasDryPasta, bool $usesInstantPot): int {
    if ($isRiceAndPeas) {
      return 22;
    }
    if ($isStirFryNoodleDish || $isSamosaDish) {
      return 18;
    }
    if ($isSweetPotatoCornmealCake) {
      return $usesInstantPot ? 14 : 22;
    }
    if ($hasDryPasta) {
      return $usesInstantPot ? 5 : 12;
    }
    return 30;
  }

  /**
   * Builds the final finishing instruction.
   */
  private function finishInstruction(bool $isStirFryNoodleDish, bool $isSamosaDish): string {
    if ($isSamosaDish) {
      return 'Finish with lime juice, cilantro, and a spoonful of tomato chutney or tamarind-style sauce.';
    }
    if ($isStirFryNoodleDish) {
      return 'Finish with lime juice, cilantro or basil, and a final dash of sesame oil; adjust chile heat to taste.';
    }
    return 'Finish with fresh lime juice and herbs; adjust heat to taste.';
  }

  /**
   * Builds the appliance mode for a generated recipe.
   */
  private function applianceMode(string $appliance, bool $isSamosaDish, bool $isStirFryNoodleDish, bool $isSweetPotatoCornmealCake, float $grainCups, bool $hasDryPasta): string {
    if ($isSamosaDish) {
      return $this->samosaApplianceMode($appliance);
    }
    if ($isStirFryNoodleDish) {
      return str_contains(strtolower($appliance), 'instant pot') ? 'Sauté / Pressure Cook' : 'Boil / Stir-Fry';
    }
    if ($isSweetPotatoCornmealCake && str_contains(strtolower($appliance), 'instant pot')) {
      return 'Pressure Cook / Sauté';
    }
    if (str_contains(strtolower($appliance), 'instant pot')) {
      return $grainCups > 0 || $hasDryPasta ? 'Sauté / Pressure Cook' : 'Sauté';
    }
    return $hasDryPasta ? 'Boil / Cook' : 'Cook';
  }

  /**
   * Builds the appliance timing for a generated recipe.
   */
  private function applianceTime(string $appliance, bool $isRiceAndPeas, bool $isSamosaDish, bool $isStirFryNoodleDish, bool $isSweetPotatoCornmealCake, bool $usesInstantPot, bool $hasDryPasta): string {
    if ($isRiceAndPeas && str_contains(strtolower($appliance), 'instant pot')) {
      return '22 minutes';
    }
    if ($isSamosaDish) {
      return $this->samosaApplianceTime($appliance);
    }
    if ($isStirFryNoodleDish) {
      return '18 minutes';
    }
    if ($isSweetPotatoCornmealCake && str_contains(strtolower($appliance), 'instant pot')) {
      return '2 minutes pressure, 6-8 minutes sauté';
    }
    if ($hasDryPasta) {
      return $usesInstantPot ? '5 minutes' : '12 minutes';
    }
    return '30 minutes';
  }

  /**
   * Builds a rough per-serving nutrition estimate from ingredient families.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   *
   * @return array{calories: int, protein: string, fiber: string}
   *   Estimated nutrition per serving.
   */
  private function nutritionEstimate(array $ingredients): array {
    $totals = ['calories' => 0.0, 'protein' => 0.0, 'fiber' => 0.0];
    foreach ($ingredients as $ingredient) {
      $item = $this->ingredientNutrition($ingredient);
      $totals['calories'] += $item['calories'];
      $totals['protein'] += $item['protein'];
      $totals['fiber'] += $item['fiber'];
    }

    if ($totals['calories'] <= 0.0) {
      $totals = ['calories' => 600.0, 'protein' => 12.0, 'fiber' => 8.0];
    }

    return [
      'calories' => (int) max(80, round($totals['calories'] / 4 / 10) * 10),
      'protein' => (int) max(1, round($totals['protein'] / 4)) . 'g',
      'fiber' => (int) max(1, round($totals['fiber'] / 4)) . 'g',
    ];
  }

  /**
   * Estimates whole-recipe nutrition contribution for one ingredient line.
   *
   * @return array{calories: float, protein: float, fiber: float}
   *   Estimated total contribution.
   */
  private function ingredientNutrition(string $ingredient): array {
    $text = strtolower($ingredient);
    $cups = $this->measuredAmount($text, 'cups?|c');
    $ounces = $this->measuredAmount($text, 'ounces?|oz');
    $tablespoons = $this->measuredAmount($text, 'tablespoons?|tbsp');
    $teaspoons = $this->measuredAmount($text, 'teaspoons?|tsp');
    $count = $this->leadingCount($text);

    if (preg_match('/\b(?:(?:vegan|plant[- ]based|meatless)\s+sausages?|sausages?\s+(?:links?|alternatives?))\b/', $text) === 1) {
      $amount = $count > 0.0 ? $count : 1.0;
      return $this->nutrition($amount * 190, $amount * 16, $amount * 3);
    }
    if (preg_match('/\b(?:brown rice|rice|quinoa|barley|farro|millet|bulgur|couscous|wheat berries)\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : 1.0;
      return $this->nutrition($amount * 680, $amount * 14, $amount * 6);
    }
    if (preg_match('/\b(?:spaghetti|pasta|macaroni|penne|rigatoni|rotini|linguine|fettuccine|farfalle|orzo|noodles?)\b/', $text) === 1) {
      if ($ounces > 0.0) {
        return $this->nutrition($ounces * 100, $ounces * 3.5, $ounces * 0.5);
      }
      if ($cups > 0.0) {
        $isCooked = preg_match('/\b(?:cooked|prepared|leftover|fresh)\b/', $text) === 1;
        $caloriesPerCup = $isCooked ? 200 : 270;
        $proteinPerCup = $isCooked ? 7 : 9.5;
        return $this->nutrition($cups * $caloriesPerCup, $cups * $proteinPerCup, $cups * 1.5);
      }
      return $this->nutrition(800, 28, 4);
    }
    if (preg_match('/\b(?:samosa wrappers?|pastry|pastry sheets|wrappers?)\b/', $text) === 1) {
      return $this->nutrition(480, 12, 4);
    }
    if (preg_match('/\b(?:beans?|kidney beans?|red beans?|black beans?|chickpeas?|lentils?)\b/', $text) === 1) {
      $amount = preg_match('/\b(?:can|canned|jar|carton)\b/', $text) === 1 ? max(1.0, $count) : max(1.0, $cups);
      return $this->nutrition($amount * 330, $amount * 20, $amount * 18);
    }
    if (preg_match('/\btofu\b/', $text) === 1) {
      return $this->nutrition(360, 40, 4);
    }
    if (preg_match('/\bpotatoes?\b/', $text) === 1) {
      $amount = max(2.0, $count);
      return $this->nutrition($amount * 160, $amount * 4, $amount * 4);
    }
    if (preg_match('/\bpeas?\b/', $text) === 1) {
      return $this->nutrition(120, 8, 7);
    }
    if (preg_match('/\b(?:flour|cornmeal)\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : 1.0;
      return $this->nutrition($amount * 455, $amount * 13, $amount * 3);
    }
    if (preg_match('/\bcoconut milk\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : 0.5;
      return $this->nutrition($amount * 450, $amount * 4, 0);
    }
    if (preg_match('/\b(?:vegetable|veggie)\s+(?:broth|stock)\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : 1.0;
      return $this->nutrition($amount * 15, $amount, 0);
    }
    if (preg_match('/\btomato paste\b/', $text) === 1) {
      $amount = $tablespoons > 0.0 ? $tablespoons : 1.0;
      return $this->nutrition($amount * 13, $amount * 0.7, $amount * 0.7);
    }
    if (preg_match('/\b(?:soy sauce|tamari)\b/', $text) === 1) {
      $amount = $tablespoons > 0.0 ? $tablespoons : 1.0;
      return $this->nutrition($amount * 10, $amount * 1.3, $amount * 0.2);
    }
    if (preg_match('/\b(?:oil|vegan butter)\b/', $text) === 1) {
      $calories = $tablespoons > 0.0
        ? $tablespoons * 120
        : ($teaspoons > 0.0
          ? $teaspoons * 40
          : (preg_match('/\bdash\b/', $text) === 1 ? 40 : 120));
      return $this->nutrition($calories, 0, 0);
    }
    if (preg_match('/\b(?:sauce|chutney|tamarind)\b/', $text) === 1) {
      return $this->nutrition(40, 1, 1);
    }
    if (preg_match('/\bmushrooms?\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : 1.0;
      return $this->nutrition($amount * 15, $amount * 2.2, $amount * 0.7);
    }
    if (preg_match('/\bonions?\b/', $text) === 1) {
      $amount = $cups > 0.0 ? $cups : ($count > 0.0 ? $count : 1.0);
      $isCupMeasure = $cups > 0.0;
      return $this->nutrition(
        $amount * ($isCupMeasure ? 64 : 44),
        $amount * ($isCupMeasure ? 1.8 : 1.2),
        $amount * ($isCupMeasure ? 2.7 : 1.9),
      );
    }
    if (preg_match('/\b(?:carrots?|bell peppers?|peppers?|scallions?|green onions?|sprouts?|tomatoes?|eggplant|zucchini|cauliflower|cabbage)\b/', $text) === 1) {
      return $this->nutrition(40, 1.5, 2.5);
    }
    if (preg_match('/\bgarlic\b/', $text) === 1) {
      $amount = $count > 0.0 ? $count : 1.0;
      return $this->nutrition($amount * 4.5, $amount * 0.2, $amount * 0.1);
    }
    if (preg_match('/\b(?:ginger|cilantro|parsley|basil|thyme|lime|lemon|curry|garam masala|cumin|paprika|turmeric|allspice|herbs?)\b/', $text) === 1) {
      return $this->nutrition(10, 0.3, 0.3);
    }
    return $this->nutrition(35, 1, 1);
  }

  /**
   * Creates a nutrition estimate tuple.
   *
   * @return array{calories: float, protein: float, fiber: float}
   *   Nutrition contribution.
   */
  private function nutrition(float $calories, float $protein, float $fiber): array {
    return ['calories' => $calories, 'protein' => $protein, 'fiber' => $fiber];
  }

  /**
   * Extracts a leading count such as "2 potatoes" or "1 can beans".
   */
  private function leadingCount(string $text): float {
    if (preg_match('/^\s*((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|one|two|three|four)\b/', $text, $matches) !== 1) {
      return 0.0;
    }
    return $this->amountToFloat($this->numberWordToAmount($matches[1]));
  }

  /**
   * Extracts a measured amount for a unit pattern.
   */
  private function measuredAmount(string $text, string $unitPattern): float {
    if (preg_match('/\b((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|one|two|three|four)\s*(?:' . $unitPattern . ')\b/', $text, $matches) !== 1) {
      return 0.0;
    }
    return $this->amountToFloat($this->numberWordToAmount($matches[1]));
  }

  /**
   * Converts small number words before numeric parsing.
   */
  private function numberWordToAmount(string $amount): string {
    return match (strtolower(trim($amount))) {
      'one' => '1',
      'two' => '2',
      'three' => '3',
      'four' => '4',
      default => $amount,
    };
  }

  /**
   * Builds ingredient lines from the structured request.
   *
   * @param array<int, string> $ingredients
   *   Explicit ingredient lines from the ingredient textarea.
   * @param array<string, mixed> $request
   *   Generation request.
   *
   * @return array<int, string>
   *   Ingredient lines suitable for the recipe contract.
   */
  private function ingredientsForRequest(array $ingredients, array $request): array {
    $isRestaurant = ($request['mode'] ?? '') === 'restaurant';
    if (!$isRestaurant) {
      return $this->uniqueIngredients(array_map(
        [$this, 'recipeReadyIngredient'],
        array_map([$this, 'veganizeIngredient'], $ingredients),
      ));
    }

    $menuItem = trim((string) ($request['menu_item'] ?? ''));
    $ingredients = array_values(array_filter(
      $ingredients,
      fn (string $ingredient): bool => !$this->isDishNameIngredient($ingredient, $menuItem),
    ));

    if ($ingredients === []) {
      $ingredients = $this->ingredientNotes((string) ($request['liked_notes'] ?? ''));
    }

    if ($ingredients === []) {
      if ($this->isStirFryNoodleDish($menuItem)) {
        $ingredients = $this->defaultStirFryNoodleIngredients();
      }
      elseif ($this->isSamosaDish($menuItem)) {
        $ingredients = $this->defaultSamosaIngredients();
      }
    }

    $ingredients = array_map([$this, 'veganizeIngredient'], $ingredients);

    if (($request['ingredient_policy'] ?? 'suggest_missing') !== 'use_only'
          && $this->isStirFryNoodleDish($menuItem . ' ' . implode(' ', $ingredients))) {
      $ingredients = array_merge($ingredients, ['garlic', 'fresh ginger', 'lime juice']);
    }
    if (($request['ingredient_policy'] ?? 'suggest_missing') !== 'use_only'
          && $this->isSamosaDish($menuItem . ' ' . implode(' ', $ingredients))) {
      $ingredients = array_merge($ingredients, ['lime juice', 'cilantro']);
    }

    return $this->uniqueIngredients($ingredients);
  }

  /**
   * Adds practical quantities to bare pantry staples.
   */
  private function recipeReadyIngredient(string $ingredient): string {
    $ingredient = trim($ingredient);
    if ($ingredient === '' || $this->hasExplicitAmount($ingredient)) {
      return $ingredient;
    }

    $normalized = strtolower($ingredient);
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    return match ($normalized) {
      'all-purpose flour', 'flour' => '1 cup all-purpose flour',
      'cornmeal' => '1/2 cup cornmeal',
      'sweet potato', 'sweet potatoes' => '1 large sweet potato, peeled and diced',
      'potato', 'potatoes' => '2 medium potatoes, diced',
      'brown rice', 'rice' => '1 cup brown rice',
      'spaghetti' => '8 ounces spaghetti',
      'black beans' => '1 can black beans, drained and rinsed',
      'chickpeas' => '1 can chickpeas, drained and rinsed',
      'red kidney beans', 'kidney beans', 'red beans' => '1 can red kidney beans, drained and rinsed',
      'eggplant' => '1 medium eggplant, diced',
      'red bell pepper' => '1 red bell pepper, sliced',
      'sweet onion', 'sweet onions' => '1 sweet onion, sliced',
      'tofu' => '14 ounces firm tofu, cubed',
      'zucchini' => '1 medium zucchini, diced',
      'coconut milk' => '1/2 cup coconut milk',
      default => $ingredient,
    };
  }

  /**
   * Checks whether an ingredient line already has a quantity or package count.
   */
  private function hasExplicitAmount(string $ingredient): bool {
    return preg_match('/^\s*(?:(?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|one|two|three|four|five|six|a|an)\b/i', $ingredient) === 1
      || preg_match('/\b(?:cups?|c|ounces?|oz|pounds?|lbs?|tablespoons?|tbsp|teaspoons?|tsp|cans?|jars?|cartons?|cloves?)\b/i', $ingredient) === 1;
  }

  /**
   * Extracts component-like ingredient phrases from restaurant notes.
   *
   * @return array<int, string>
   *   Ingredient-like phrases.
   */
  private function ingredientNotes(string $notes): array {
    $notes = trim($notes);
    if ($notes === '' || !$this->looksLikeIngredientList($notes)) {
      return [];
    }

    $notes = preg_replace('/[.;]+$/', '', $notes) ?? $notes;
    $parts = preg_split(
      '/\s*,\s*|\s+and\s+(?=(?:a\s+)?(?:dash|pinch|splash|drizzle|teaspoon|tablespoon|cup|vegan|sesame|soy|lime|garlic|ginger|green|yellow|red|bell|bean|carrot|onion|noodles?|sauce)\b)/i',
      $notes,
    ) ?: [];
    $ingredients = [];
    foreach ($parts as $part) {
      $part = trim($part);
      $part = preg_replace('/^(?:with|plus)\s+/i', '', $part) ?? $part;
      if ($part !== '') {
        $ingredients[] = $part;
      }
    }
    return $ingredients;
  }

  /**
   * Checks whether free-text notes are likely to be an ingredient list.
   */
  private function looksLikeIngredientList(string $notes): bool {
    $normalized = strtolower($notes);
    $separatorCount = substr_count($normalized, ',') + substr_count($normalized, ';');
    $ingredientSignals = preg_match_all(
          '/\b(?:noodles?|onions?|peppers?|carrots?|sprouts?|scallions?|green onions?|sauce|oil|garlic|ginger|tofu|beans?|rice|mushrooms?|cabbage|lime|cilantro|basil|tomatoes?|potatoes?|peas?|curry|masala|pastry|wrappers?)\b/',
          $normalized,
        );
    return $separatorCount >= 2 || $ingredientSignals >= 3;
  }

  /**
   * Returns default components for a restaurant noodle stir-fry.
   *
   * @return array<int, string>
   *   Ingredient lines.
   */
  private function defaultStirFryNoodleIngredients(): array {
    return [
      '8 ounces vegan egg-style chow mein noodles',
      '1 yellow onion, sliced',
      '1 red bell pepper, sliced',
      '2 carrots, julienned',
      '2 cups bean sprouts',
      '4 green onions, sliced',
      '3 cloves garlic, minced',
      '1 tbsp fresh ginger, minced',
      '3 tbsp vegan soy-lime cooking sauce',
      '1 tsp sesame oil',
    ];
  }

  /**
   * Returns default components for a vegan samosa-style recipe.
   *
   * @return array<int, string>
   *   Ingredient lines.
   */
  private function defaultSamosaIngredients(): array {
    return [
      '12 vegan samosa wrappers or pastry sheets',
      '2 medium potatoes, diced',
      '1 cup peas',
      '1 yellow onion, diced',
      '2 tomatoes, diced',
      '3 cloves garlic, minced',
      '1 tbsp fresh ginger, minced',
      '2 tsp curry powder',
      '1 tsp garam masala',
      '1 tsp cumin',
      '1/4 cup cilantro, chopped',
      '2 tbsp lime juice',
    ];
  }

  /**
   * Converts common non-vegan restaurant components to explicit vegan forms.
   */
  private function veganizeIngredient(string $ingredient): string {
    $ingredient = trim($ingredient);
    if ($ingredient === '') {
      return '';
    }

    $ingredient = preg_replace(
      '/\begg\s+noodles?\b/i',
      '8 ounces vegan egg-style chow mein noodles',
      $ingredient,
    ) ?? $ingredient;
    $ingredient = preg_replace('/(?<!vegan )\bfish\s+sauce\b/i', 'vegan fish sauce', $ingredient) ?? $ingredient;
    $ingredient = preg_replace('/(?<!vegan )\boyster\s+sauce\b/i', 'vegan oyster sauce', $ingredient) ?? $ingredient;
    $ingredient = preg_replace('/\bhoney\b/i', 'agave', $ingredient) ?? $ingredient;
    $ingredient = preg_replace('/(?<!vegan )\bbutter\b(?!\s+beans?)/i', 'vegan butter', $ingredient) ?? $ingredient;
    return trim(preg_replace('/\s+/', ' ', $ingredient) ?? $ingredient);
  }

  /**
   * Removes duplicate ingredient lines while preserving order.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   *
   * @return array<int, string>
   *   Deduplicated ingredient lines.
   */
  private function uniqueIngredients(array $ingredients): array {
    $unique = [];
    $seen = [];
    foreach ($ingredients as $ingredient) {
      $ingredient = trim($ingredient);
      if ($ingredient === '') {
        continue;
      }
      // "3 cloves garlic, minced" and "garlic" are the same ingredient.
      $key = $this->ingredientName($ingredient);
      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $unique[] = $ingredient;
      }
    }
    return $unique;
  }

  /**
   * Checks whether a line is a dish name accidentally placed in ingredients.
   */
  private function isDishNameIngredient(string $ingredient, string $menuItem): bool {
    $ingredientKey = $this->normalizedDishName($ingredient);
    if ($menuItem !== '' && $ingredientKey === $this->normalizedDishName($menuItem)) {
      return TRUE;
    }
    return preg_match('/\b(?:chow\s*mein|samosas?)\b/i', $ingredient) === 1
          && preg_match('/\b(?:noodles?|sauce|seasoning)\b/i', $ingredient) !== 1;
  }

  /**
   * Builds a title that preserves restaurant dish intent.
   *
   * @param string $main
   *   Main ingredient line.
   * @param string $titleCuisine
   *   Cuisine for the title.
   * @param string $menuItem
   *   Restaurant menu item, if any.
   * @param bool $isRiceAndPeas
   *   TRUE for rice and peas.
   * @param bool $isRestaurant
   *   TRUE in restaurant mode.
   * @param bool $isStirFryNoodleDish
   *   TRUE for noodle stir-fries.
   * @param bool $isSamosaDish
   *   TRUE for samosas.
   * @param bool $isSweetPotatoCornmealCake
   *   TRUE for cornmeal cakes.
   * @param array<int, string> $ingredients
   *   Ingredient lines, used to name pasta and curry dishes.
   */
  private function recipeTitle(
    string $main,
    string $titleCuisine,
    string $menuItem,
    bool $isRiceAndPeas,
    bool $isRestaurant,
    bool $isStirFryNoodleDish,
    bool $isSamosaDish,
    bool $isSweetPotatoCornmealCake,
    array $ingredients = [],
  ): string {
    if ($isRiceAndPeas) {
      return 'Caribbean Rice and Peas';
    }
    if ($isRestaurant && $menuItem !== '') {
      $dishTitle = $this->displayDishName($menuItem);
      return $isStirFryNoodleDish
            ? $dishTitle . '-Inspired Noodles'
            : ($isSamosaDish
              ? $dishTitle . '-Inspired Hand Pies'
              : $dishTitle . '-Inspired ' . $titleCuisine . ' Plate');
    }
    $prefix = $titleCuisine === 'Global' ? '' : $titleCuisine . ' ';
    if ($isSweetPotatoCornmealCake) {
      return TitleFlair::add($prefix . 'Sweet Potato Cornmeal Cakes', $titleCuisine);
    }
    if ($this->hasDryPasta($ingredients)) {
      return TitleFlair::add($prefix . 'One-Pot ' . ucwords($this->dryPastaName($ingredients)), $titleCuisine);
    }
    $dish = preg_match('/\b(?:curry|garam masala)\b/i', implode(' ', $ingredients)) === 1 ? 'Curry' : 'Stew';
    return TitleFlair::add($prefix . ucwords($this->titleIngredientName($main)) . ' ' . $dish, $titleCuisine);
  }

  /**
   * Chooses a meaningful ingredient for recipe titles.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function titleMainIngredient(array $ingredients): string {
    foreach ($ingredients as $ingredient) {
      $name = $this->titleIngredientName($ingredient);
      if ($name !== '' && !$this->isCookingLiquid($name)) {
        return $ingredient;
      }
    }
    return 'mixed vegetables';
  }

  /**
   * Returns a title-ready ingredient name without quantities or packaging.
   */
  private function titleIngredientName(string $ingredient): string {
    $name = $this->ingredientName($ingredient);
    $name = preg_replace('/^\s*(?:(?:\d+(?:\.\d+)?|(?:\d+\s+)?\d+\/\d+)[- ]?)?(?:ounce|ounces|oz|pound|pounds|lb|lbs)[- ]+(?:can|jar|carton|package|bag|box)\s+(?:of\s+)?/', '', $name) ?? $name;
    $name = preg_replace('/^\s*(?:can|canned|jar|carton|package|bag|box)\s+(?:of\s+)?/', '', $name) ?? $name;
    $name = preg_replace('/\b(?:large|medium|small|fresh|raw|whole|peeled|trimmed|drained|rinsed|diced|cubed|chopped|sliced)\b/', '', $name) ?? $name;
    $name = preg_replace('/(?:,\s*)?(?:and\s*)?$/', '', $name) ?? $name;
    $name = trim($name, " \t\n\r\0\x0B,.;");
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    $name = preg_replace('/\b(black|red|kidney|pinto|white|cannellini|navy|garbanzo) beans\b/', '$1 bean', $name) ?? $name;
    $name = preg_replace('/\bchickpeas\b/', 'chickpea', $name) ?? $name;
    $name = preg_replace('/\blentils\b/', 'lentil', $name) ?? $name;
    $name = preg_replace('/\bsweet potatoes\b/', 'sweet potato', $name) ?? $name;
    $name = preg_replace('/\bpotatoes\b/', 'potato', $name) ?? $name;
    // Singularize the head noun: "cucumbers" becomes "cucumber".
    $name = preg_replace('/(\w)ies$/', '$1y', $name) ?? $name;
    $name = preg_replace('/(\w)oes$/', '$1o', $name) ?? $name;
    $name = preg_replace('/(\w[^su\s])s$/', '$1', $name) ?? $name;
    return $name === '' ? 'mixed vegetables' : $name;
  }

  /**
   * Builds a first flavor-building instruction.
   */
  private function openingFlavorInstruction(string $cuisine, bool $isStirFryNoodleDish, bool $isSamosaDish, bool $isSweetPotatoCornmealCake): string {
    if ($isStirFryNoodleDish) {
      return 'Sauté garlic, ginger, and onions over high heat until fragrant.';
    }
    if ($isSamosaDish) {
      return 'Sauté garlic, ginger, onions, curry powder, cumin, and garam masala until fragrant.';
    }
    if ($isSweetPotatoCornmealCake) {
      return 'Sauté garlic, scallions, cumin, coriander, turmeric, and allspice until fragrant.';
    }
    return 'Sauté garlic, scallions, thyme, and allspice until fragrant.';
  }

  /**
   * Checks whether the dish should be treated as a stir-fried noodle dish.
   */
  private function isStirFryNoodleDish(string $text): bool {
    return preg_match('/\b(?:chow\s*mein|lo\s*mein|pad\s*thai|drunken noodles?|stir[- ]?fried noodles?)\b/i', $text) === 1;
  }

  /**
   * Checks whether the dish should be treated as a samosa-style pastry.
   */
  private function isSamosaDish(string $text): bool {
    return preg_match('/\bsamosas?\b/i', $text) === 1;
  }

  /**
   * Checks for a pantry-staple combination that should become cakes.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function isSweetPotatoCornmealCake(array $ingredients): bool {
    $text = strtolower(implode(' ', $ingredients));
    return preg_match('/\bflour\b/', $text) === 1
      && preg_match('/\bcornmeal\b/', $text) === 1
      && preg_match('/\bsweet potato(?:es)?\b/', $text) === 1;
  }

  /**
   * Returns an appliance mode for samosa-style cooking.
   */
  private function samosaApplianceMode(string $appliance): string {
    $appliance = strtolower($appliance);
    if (str_contains($appliance, 'air fryer')) {
      return 'Air Fry';
    }
    if (str_contains($appliance, 'oven')) {
      return 'Bake';
    }
    if (str_contains($appliance, 'instant pot')) {
      return 'Sauté';
    }
    if (str_contains($appliance, 'stovetop')) {
      return 'Toast / Pan-Fry';
    }
    return 'Cook';
  }

  /**
   * Returns an appliance time for samosa-style cooking.
   */
  private function samosaApplianceTime(string $appliance): string {
    $appliance = strtolower($appliance);
    if (str_contains($appliance, 'air fryer')) {
      return '10-12 minutes at 375°F';
    }
    if (str_contains($appliance, 'oven')) {
      return '18-22 minutes at 400°F';
    }
    if (str_contains($appliance, 'instant pot')) {
      return '8 minutes';
    }
    if (str_contains($appliance, 'stovetop')) {
      return '8-10 minutes';
    }
    return '18 minutes';
  }

  /**
   * Builds a selected-appliance cooking method for samosa-style recipes.
   *
   * @param array<int, string> $appliances
   *   Selected appliances.
   */
  private function samosaCookingMethod(array $appliances): string {
    $applianceText = strtolower(implode(' ', $appliances));
    if (str_contains($applianceText, 'air fryer')) {
      return 'air-fry at 375°F for 10-12 minutes';
    }
    if (str_contains($applianceText, 'oven')) {
      return 'bake at 400°F for 18-22 minutes';
    }
    if (str_contains($applianceText, 'stovetop')) {
      return 'pan-toast on the stovetop for 8-10 minutes';
    }
    if (str_contains($applianceText, 'instant pot')) {
      return 'Sauté seam-side down in a lightly oiled Instant Pot insert for 8 minutes';
    }
    return 'cook for 18 minutes';
  }

  /**
   * Returns display casing for common dish names.
   */
  private function displayDishName(string $dishName): string {
    $dishName = $this->normalizedDishName($dishName);
    $dishName = preg_replace('/\bchowmein\b/', 'chow mein', $dishName) ?? $dishName;
    return ucwords($dishName);
  }

  /**
   * Normalizes dish names for comparison and display.
   */
  private function normalizedDishName(string $dishName): string {
    $dishName = strtolower(trim($dishName));
    $dishName = preg_replace('/\bchow\s*mein\b/', 'chow mein', $dishName) ?? $dishName;
    $dishName = preg_replace('/[^a-z0-9]+/', ' ', $dishName) ?? $dishName;
    return trim(preg_replace('/\s+/', ' ', $dishName) ?? $dishName);
  }

  /**
   * Builds the main cooking step with canned legumes treated as already cooked.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   * @param array<int, string> $appliances
   *   Selected appliances from the request.
   * @param bool $usesInstantPot
   *   TRUE when the request includes an Instant Pot-style appliance.
   * @param float $grainCups
   *   Estimated dry grain quantity in cups.
   * @param string $liquidAmount
   *   Formatted water amount for grain cooking.
   * @param float $liquidCups
   *   Numeric water amount for grain cooking.
   * @param bool $hasDryPasta
   *   TRUE when the request includes uncooked dry pasta.
   * @param bool $hasCannedLegumes
   *   TRUE when the request includes canned or packaged legumes.
   * @param bool $isRiceAndPeas
   *   TRUE when the request matches Caribbean rice and peas.
   * @param bool $isStirFryNoodleDish
   *   TRUE when restaurant inspiration should use noodle stir-fry technique.
   * @param bool $isSamosaDish
   *   TRUE when restaurant inspiration should use samosa pastry technique.
   * @param bool $isSweetPotatoCornmealCake
   *   TRUE when pantry staples should become sweet potato cornmeal cakes.
   */
  private function mainCookingInstruction(
    array $ingredients,
    array $appliances,
    bool $usesInstantPot,
    float $grainCups,
    string $liquidAmount,
    float $liquidCups,
    bool $hasDryPasta,
    bool $hasCannedLegumes,
    bool $isRiceAndPeas,
    bool $isStirFryNoodleDish,
    bool $isSamosaDish,
    bool $isSweetPotatoCornmealCake,
  ): string {
    $cupLabel = 'cup' . (abs($liquidCups - 1.0) < 0.00001 ? '' : 's');
    if ($isRiceAndPeas && $usesInstantPot) {
      return 'Add the brown rice, canned kidney beans, coconut milk, and ' . $liquidAmount . ' ' . $cupLabel
        . ' water. Pressure Cook for 22 minutes, then let the rice rest before fluffing.';
    }

    if ($isSweetPotatoCornmealCake) {
      return $this->sweetPotatoCornmealCakeInstruction($ingredients, $usesInstantPot);
    }

    $preparedIngredients = $this->preparedIngredientPhrase($ingredients);
    $liquidPhrase = $this->cookingLiquidPhrase($ingredients, $liquidAmount, $cupLabel);
    $donenessCue = $this->donenessCue($ingredients);

    if ($isStirFryNoodleDish) {
      $noodles = $hasDryPasta ? $this->dryPastaName($ingredients) : 'noodles';
      if ($usesInstantPot && $hasDryPasta) {
        return 'Add ' . $this->dryPastaDisplay($ingredients) . ' and 2 cups water, Pressure Cook for 5 minutes until al dente, then drain if needed and Sauté with '
          . $preparedIngredients . ' until glossy and crisp-tender.';
      }
      if ($hasDryPasta) {
        return 'Cook ' . $noodles . ' according to package directions until al dente, then stir-fry with '
          . $preparedIngredients . ' until glossy and crisp-tender.';
      }
      return 'Stir-fry ' . $preparedIngredients . ' over high heat until crisp-tender, then toss with noodles until glossy.';
    }

    if ($isSamosaDish) {
      return 'Cook potatoes until fork-tender, mash lightly with ' . $preparedIngredients
            . ', fold into pastry triangles, then ' . $this->samosaCookingMethod($appliances) . ' until crisp and golden.';
    }

    if ($hasDryPasta) {
      $pasta = $this->dryPastaDisplay($ingredients);
      if ($usesInstantPot) {
        return 'Sauté ' . $preparedIngredients . ' until fragrant, add ' . $pasta . ' and 2 cups water, then Pressure Cook '
          . $this->dryPastaName($ingredients) . ' for 5 minutes until al dente.';
      }
      return 'Cook ' . $this->dryPastaName($ingredients) . ' according to package directions until al dente, then simmer with '
        . $preparedIngredients . ' until cohesive.';
    }

    if ($usesInstantPot && $grainCups > 0) {
      $grain = $this->dryGrainName($ingredients);
      return 'Add the ' . $grain . ' and ' . $liquidPhrase . ', then Pressure Cook the ' . $grain
        . (str_contains($grain, 'brown rice') ? ' on High for 22 minutes and let the pressure release naturally for 10 minutes. ' : ' until tender. ')
        . ($hasCannedLegumes
          ? 'Sauté canned beans and ' . $preparedIngredients . ' until hot.'
          : 'Sauté ' . $preparedIngredients . ' until hot.');
    }

    if ($usesInstantPot) {
      return $hasCannedLegumes
        ? 'Sauté canned beans, ' . $preparedIngredients . ', and ' . $liquidPhrase . ' until hot.'
        : 'Add ' . $preparedIngredients . ', ' . $liquidPhrase . '. Sauté until ' . $donenessCue . '.';
    }

    return $hasCannedLegumes
      ? 'Simmer canned beans and ' . $preparedIngredients . ' until hot.'
      : 'Simmer ' . $preparedIngredients . ' until ' . $donenessCue . '.';
  }

  /**
   * Builds a clear method for flour, cornmeal, and sweet potato.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   * @param bool $usesInstantPot
   *   TRUE when the request includes an Instant Pot-style appliance.
   */
  private function sweetPotatoCornmealCakeInstruction(array $ingredients, bool $usesInstantPot): string {
    $sweetPotato = $this->firstDisplayIngredient($ingredients, '/\bsweet potato(?:es)?\b/');
    $flour = $this->firstDisplayIngredient($ingredients, '/\bflour\b/');
    $cornmeal = $this->firstDisplayIngredient($ingredients, '/\bcornmeal\b/');
    if ($usesInstantPot) {
      return 'Put ' . $sweetPotato . ' and 1 cup water into the Instant Pot, lock the lid, and Pressure Cook the sweet potato on High for 2 minutes until fork-tender, then Quick Release and mash. Stir in '
        . $cornmeal . ' and ' . $flour . ' gradually to form a soft dough, shape into cakes, and Sauté 3-4 minutes per side until crisp.';
    }
    return 'Simmer ' . $sweetPotato . ' with 1 cup water, covered, for 10-12 minutes until fork-tender, then mash. Stir in '
      . $cornmeal . ' and ' . $flour . ' gradually to form a soft dough, shape into cakes, and pan-sear 3-4 minutes per side until crisp.';
  }

  /**
   * Finds the first ingredient matching a pattern for instruction text.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   * @param string $pattern
   *   Regular expression used to find a matching ingredient.
   */
  private function firstDisplayIngredient(array $ingredients, string $pattern): string {
    foreach ($ingredients as $ingredient) {
      if (preg_match($pattern, strtolower($ingredient)) === 1) {
        return $this->displayIngredient($ingredient);
      }
    }
    return 'the ingredient';
  }

  /**
   * Builds a concise phrase naming the ingredients used in the main step.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function preparedIngredientPhrase(array $ingredients): string {
    $items = [];
    foreach ($ingredients as $ingredient) {
      $name = $this->ingredientName($ingredient);
      if ($name === '' || $this->isCookingLiquid($name) || $this->isDryGrain($name) || $this->isDryPasta($name) || $this->isPackagedLegume($ingredient)) {
        continue;
      }
      $items[$name] = $this->displayIngredient($ingredient);
    }

    return $items === [] ? 'the remaining ingredients' : $this->joinIngredientList(array_values($items));
  }

  /**
   * Builds the measured cooking liquid phrase.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   * @param string $waterAmount
   *   Formatted water amount.
   * @param string $cupLabel
   *   Singular or plural cup label.
   */
  private function cookingLiquidPhrase(array $ingredients, string $waterAmount, string $cupLabel): string {
    foreach ($ingredients as $ingredient) {
      $name = $this->ingredientName($ingredient);
      if ($name === 'coconut milk') {
        return $this->displayIngredient($ingredient) . ', and ' . $waterAmount . ' ' . $cupLabel . ' water';
      }
    }
    return $waterAmount . ' ' . $cupLabel . ' water';
  }

  /**
   * Builds a doneness cue for the user's ingredients.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function donenessCue(array $ingredients): string {
    $text = strtolower(implode(' ', $ingredients));
    if (preg_match('/\bpotatoes?\b/', $text) === 1) {
      return 'potatoes are fork-tender';
    }
    if (preg_match('/\bcauliflower\b/', $text) === 1) {
      return 'cauliflower is fork-tender';
    }
    if (preg_match('/\begg[- ]?plant\b/', $text) === 1) {
      return 'eggplant is silky';
    }
    return 'everything is tender and cohesive';
  }

  /**
   * Returns ingredient text for instructions while keeping quantities.
   */
  private function displayIngredient(string $ingredient): string {
    $display = strtolower(trim($ingredient));
    $display = preg_replace('/\begg[- ]?plant\b/', 'eggplant', $display) ?? $display;
    // Drop trailing prep notes such as ", sliced" or ", drained and rinsed".
    $display = preg_replace('/,.*$/', '', $display) ?? $display;
    $display = preg_replace('/\b(?:diced|cubed|chopped|sliced|drained|rinsed)\b/', '', $display) ?? $display;
    return trim(preg_replace('/\s+/', ' ', $display) ?? $display);
  }

  /**
   * Returns a normalized ingredient name without leading quantities.
   */
  private function ingredientName(string $ingredient): string {
    $name = strtolower(trim($ingredient));
    $name = preg_replace('/^\s*(?:\d+\s+)?(?:\d+\/\d+|\d+(?:\.\d+)?)\s*(?:cups?|c|ounces?|oz|pounds?|lbs?|tablespoons?|tbsp|teaspoons?|tsp|cans?|cloves?)?\s+/', '', $name) ?? $name;
    $name = preg_replace('/^\s*(?:one|two|three|four)\s+(?:cups?|c|ounces?|oz|pounds?|lbs?|tablespoons?|tbsp|teaspoons?|tsp|cans?|cloves?)?\s+/', '', $name) ?? $name;
    $name = preg_replace('/,.*$/', '', $name) ?? $name;
    $name = preg_replace('/\b(?:diced|cubed|chopped|sliced|drained|rinsed)\b/', '', $name) ?? $name;
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    return str_replace('egg plant', 'eggplant', $name);
  }

  /**
   * Joins ingredient names into a readable list.
   *
   * @param array<int, string> $items
   *   Ingredient names.
   */
  private function joinIngredientList(array $items): string {
    if (count($items) <= 1) {
      return $items[0] ?? '';
    }
    if (count($items) === 2) {
      return $items[0] . ' and ' . $items[1];
    }
    $last = array_pop($items);
    return implode(', ', $items) . ', ' . $last;
  }

  /**
   * Checks whether an ingredient is liquid used for cooking.
   */
  private function isCookingLiquid(string $ingredient): bool {
    return preg_match('/\b(?:water|broth|stock|coconut milk|liquid)\b/', $ingredient) === 1;
  }

  /**
   * Checks whether an ingredient is a dry grain.
   */
  private function isDryGrain(string $ingredient): bool {
    return preg_match('/\b(?:rice|quinoa|oats?(?!\s+flour)|barley|farro|millet|bulgur|couscous|wheat berries)\b/', $ingredient) === 1;
  }

  /**
   * Checks whether an ingredient is uncooked dry pasta.
   */
  private function isDryPasta(string $ingredient): bool {
    return preg_match('/\b(?:spaghetti|pasta|macaroni|penne|rigatoni|rotini|linguine|fettuccine|farfalle|orzo|noodles?|shells)\b/', $ingredient) === 1
      && preg_match('/\b(?:cooked|prepared|leftover|fresh)\b/', $ingredient) !== 1;
  }

  /**
   * Checks whether an ingredient is a canned or packaged legume.
   */
  private function isPackagedLegume(string $ingredient): bool {
    $ingredient = strtolower($ingredient);
    return preg_match('/\b(?:can|canned|jar|carton)\b/', $ingredient) === 1
      && preg_match('/\b(?:beans?|kidney beans?|red beans?|black beans?|chickpeas?|lentils?|peas?)\b/', $ingredient) === 1;
  }

  /**
   * Estimates dry grain cups in the ingredient list.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   *
   * @return float
   *   Estimated dry grain cups.
   */
  private function estimateDryGrainCups(array $ingredients): float {
    $total = 0.0;
    foreach ($ingredients as $ingredient) {
      if (preg_match('/\b((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(?:cups?|c)\s+(?!(?:cooked|prepared|leftover)\b)[^,.;]{0,50}\b(?:rice|quinoa|oats?(?!\s+flour)|barley|farro|millet|bulgur|couscous|wheat berries)\b/', strtolower($ingredient), $matches) === 1) {
        $total += $this->amountToFloat($matches[1]);
      }
    }
    return $total;
  }

  /**
   * Checks whether the request includes uncooked dry pasta.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function hasDryPasta(array $ingredients): bool {
    foreach ($ingredients as $ingredient) {
      if ($this->isDryPasta(strtolower($ingredient))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the first dry pasta ingredient with its quantity.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function dryPastaDisplay(array $ingredients): string {
    foreach ($ingredients as $ingredient) {
      if ($this->isDryPasta(strtolower($ingredient))) {
        return $this->displayIngredient($ingredient);
      }
    }
    return 'dry pasta';
  }

  /**
   * Returns a dry pasta name for cooking instructions.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function dryPastaName(array $ingredients): string {
    foreach ($ingredients as $ingredient) {
      $name = $this->ingredientName($ingredient);
      if ($this->isDryPasta($name)) {
        return $name;
      }
    }
    return 'pasta';
  }

  /**
   * Returns a dry grain name for cooking instructions.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function dryGrainName(array $ingredients): string {
    foreach ($ingredients as $ingredient) {
      $name = $this->ingredientName($ingredient);
      if ($this->isDryGrain($name)) {
        return $name;
      }
    }
    return 'grains';
  }

  /**
   * Checks if the request includes canned or packaged legumes.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function hasCannedLegumes(array $ingredients): bool {
    foreach ($ingredients as $ingredient) {
      $ingredient = strtolower($ingredient);
      if (preg_match('/\b(?:can|canned|jar|carton)\b/', $ingredient) === 1
        && preg_match('/\b(?:beans?|kidney beans?|red beans?|black beans?|chickpeas?|lentils?|peas?)\b/', $ingredient) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks for the common rice and peas ingredient pattern.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines from the request.
   */
  private function isRiceAndPeas(array $ingredients): bool {
    $text = strtolower(implode(' ', $ingredients));
    return preg_match('/\bbrown rice\b/', $text) === 1
      && preg_match('/\b(?:can|canned)\b[^.]*\b(?:red kidney beans|kidney beans|red beans)\b/', $text) === 1
      && preg_match('/\bcoconut milk\b/', $text) === 1;
  }

  /**
   * Formats a cup amount for instruction text.
   */
  private function formatCupAmount(float $amount): string {
    if (abs($amount - round($amount)) < 0.00001) {
      return (string) (int) round($amount);
    }
    return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
  }

  /**
   * Converts a simple decimal or fractional amount to a float.
   */
  private function amountToFloat(string $amount): float {
    $amount = trim($amount);
    if (str_contains($amount, ' ')) {
      [$whole, $fraction] = explode(' ', $amount, 2);
      return (float) $whole + $this->amountToFloat($fraction);
    }
    if (str_contains($amount, '/')) {
      [$numerator, $denominator] = explode('/', $amount, 2);
      return (float) $numerator / max(1.0, (float) $denominator);
    }
    return (float) $amount;
  }

  /**
   * Returns the requested cuisine, defaulting to global.
   *
   * @param array<string, mixed> $request
   *   Generation request.
   */
  private function requestedCuisine(array $request): string {
    $cuisine = trim((string) ($request['cuisine_vibe'] ?? ''));
    if ($cuisine === '') {
      return 'Global';
    }
    return ucwords(strtolower($cuisine));
  }

}
