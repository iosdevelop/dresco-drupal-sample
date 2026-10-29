<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Checks appliance safety, grain ratios, dough hydration, and claims.
 */
final class TechnicalSafetyRule implements ValidationRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $results = [];
    $instructionText = strtolower(json_encode([
      $recipe->title,
      $recipe->description,
      $recipe->instructions,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $ingredientText = strtolower(json_encode($recipe->ingredients, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    if (preg_match('/\b(?:copycat|exact|official|secret recipe|same as)\b/', $instructionText) === 1) {
      $results[] = new ValidationResult(
        ruleId: 'restaurant.proprietary_claim',
        message: 'Restaurant-inspired recipes must be original home-kitchen versions.',
        component: 'description',
      );
    }

    $unmeasuredStaple = $this->firstUnmeasuredStructuralIngredient($recipe->ingredients);
    if ($unmeasuredStaple !== NULL) {
      $results[] = new ValidationResult(
        ruleId: 'ingredients.measured_staples',
        message: sprintf('Primary ingredient "%s" needs a usable quantity or package size.', $unmeasuredStaple),
        component: 'ingredients',
        remediation: 'Add amounts such as 1 cup, 8 ounces, 1 can, or 1 large diced item.',
      );
    }

    $usesPressure = preg_match('/\b(?:pressure cook|high pressure|manual pressure|steam)\b/', $instructionText) === 1;
    $hasMeasuredLiquid = preg_match('/\b(?:(?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|one|two)\s*(?:cups?|c)\s+(?:of\s+)?(?:water|broth|stock|liquid|coconut milk)\b/', $ingredientText . ' ' . $instructionText) === 1;
    if ($usesPressure && !$hasMeasuredLiquid) {
      $results[] = new ValidationResult(
        ruleId: 'appliance.pressure_liquid',
        message: 'Instant Pot pressure or steam instructions require measured cooking liquid.',
        component: 'instructions',
      );
    }

    if ($this->hasFlourCornmealAndRootVegetable($ingredientText)) {
      if (!$this->hasDefinedStarchOutcome($instructionText)) {
        $results[] = new ValidationResult(
          ruleId: 'starch.outcome',
          message: 'Flour, cornmeal, and root-vegetable recipes need a defined outcome such as cakes, dumplings, fritters, flatbread, porridge, stuffing, or stew with dumplings.',
          component: 'instructions',
        );
      }
      if (!$this->hasRootVegetableCookingMethod($instructionText)) {
        $results[] = new ValidationResult(
          ruleId: 'root_vegetable.cooking_method',
          message: 'Potatoes or sweet potatoes need a size cue, cooking method, time, and fork-tender doneness cue before they are mashed or combined with starches.',
          component: 'instructions',
        );
      }
    }

    if ($this->hasCornmealLiquidWithoutMethod($ingredientText, $instructionText)) {
      $results[] = new ValidationResult(
        ruleId: 'cornmeal.hydration_method',
        message: 'Cornmeal added with liquid must be stirred or whisked gradually, or mixed into a dough or batter before cooking.',
        component: 'instructions',
      );
    }

    if ($this->requiresSteamDonenessCue($ingredientText, $instructionText)) {
      $results[] = new ValidationResult(
        ruleId: 'appliance.steam_doneness',
        message: 'Steamed potato or cauliflower steps require a clear doneness cue such as fork-tender.',
        component: 'instructions',
      );
    }

    if ($this->usesAirFryer($instructionText) && !$this->hasAirFryerTimeAndTemperature($instructionText)) {
      $results[] = new ValidationResult(
        ruleId: 'appliance.air_fryer_time_temperature',
        message: 'Air-fryer instructions require both a cooking time and a temperature.',
        component: 'instructions',
      );
    }

    $grainCups = $this->measuredDryGrainCups($ingredientText);
    if ($grainCups > 0) {
      $liquidCups = $this->measuredLiquidCups($ingredientText . ' ' . $instructionText);
      if ($liquidCups < $grainCups) {
        $results[] = new ValidationResult(
          ruleId: 'grain.liquid_ratio',
          message: 'Dry grains need at least 1 cup of measured cooking liquid per cup of grain.',
          severity: 'warning',
          component: 'ingredients',
        );
      }
    }

    if ($this->hasDryPasta($ingredientText) && !$this->hasPastaCookingStep($instructionText, $hasMeasuredLiquid)) {
      $results[] = new ValidationResult(
        ruleId: 'pasta.cooking_method',
        message: 'Dry pasta requires boiling, simmering, package-direction cooking, or pressure cooking with measured liquid before it is sautéed or finished.',
        component: 'instructions',
      );
    }

    if (preg_match('/\b(?:dough|dumplings?|festival|flatbread|roti)\b/', $instructionText) === 1) {
      $flourCups = $this->measuredCups($ingredientText, 'flour');
      $waterCups = $this->measuredCups($ingredientText, 'water');
      if ($flourCups > 0 && $waterCups < $flourCups * 0.5) {
        $results[] = new ValidationResult(
          ruleId: 'dough.hydration',
          message: 'Dough recipes require at least one cup water per two cups flour.',
          component: 'ingredients',
        );
      }
    }

    if ($this->undercooksBrownRice($ingredientText, $recipe->instructions)) {
      $results[] = new ValidationResult(
        ruleId: 'grain.brown_rice_time',
        message: 'Uncooked brown rice needs at least 35 minutes of simmering on the stove.',
        component: 'instructions',
        remediation: 'Simmer the brown rice covered for 40-45 minutes, or pressure cook it on High for 22 minutes.',
      );
    }

    $badNutrition = $this->malformedNutrition($recipe->nutrition);
    if ($badNutrition !== []) {
      $results[] = new ValidationResult(
        ruleId: 'nutrition.format',
        message: sprintf('Nutrition values must be plain per-serving estimates; check %s.', implode(', ', $badNutrition)),
        component: 'nutrition',
        remediation: 'Use whole-number calories between 50 and 1500 and gram amounts such as "12g" for protein and fiber.',
      );
    }

    $unselectedAppliance = $this->firstUnselectedAppliance($recipe->applianceSettings, $instructionText);
    if ($unselectedAppliance !== NULL) {
      $results[] = new ValidationResult(
        ruleId: 'appliance.unselected',
        message: sprintf('Instructions mention %s, but it is not selected in appliance settings.', $unselectedAppliance),
        component: 'appliance_settings',
      );
    }
    return $results;
  }

  /**
   * Finds a structural ingredient missing a quantity or package size.
   *
   * @param array<int, array<string, mixed>> $ingredients
   *   Ingredient records.
   */
  private function firstUnmeasuredStructuralIngredient(array $ingredients): ?string {
    foreach ($ingredients as $ingredient) {
      $text = trim((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''));
      if ($text === '') {
        continue;
      }
      $normalized = strtolower($text);
      if (!$this->isStructuralIngredient($normalized)) {
        continue;
      }
      if (!$this->hasUsableQuantity($normalized)) {
        return $text;
      }
    }
    return NULL;
  }

  /**
   * Checks whether an ingredient usually defines recipe structure.
   */
  private function isStructuralIngredient(string $ingredient): bool {
    return preg_match('/\b(?:all-purpose flour|flour|cornmeal|rice|quinoa|spaghetti|pasta|macaroni|penne|noodles?|potatoes?|sweet potato(?:es)?|beans?|chickpeas?|lentils?|tofu)\b/', $ingredient) === 1;
  }

  /**
   * Checks whether an ingredient line includes a usable amount.
   */
  private function hasUsableQuantity(string $ingredient): bool {
    return preg_match('/^\s*(?:(?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|one|two|three|four|five|six|a|an)\b/', $ingredient) === 1
      || preg_match('/\b(?:cups?|c|ounces?|oz|pounds?|lbs?|tablespoons?|tbsp|teaspoons?|tsp|cans?|jars?|cartons?)\b/', $ingredient) === 1;
  }

  /**
   * Checks for flour/cornmeal/root vegetable combinations.
   */
  private function hasFlourCornmealAndRootVegetable(string $ingredientText): bool {
    return preg_match('/\bflour\b/', $ingredientText) === 1
      && preg_match('/\bcornmeal\b/', $ingredientText) === 1
      && preg_match('/\b(?:potatoes?|sweet potato(?:es)?)\b/', $ingredientText) === 1;
  }

  /**
   * Checks whether starch-heavy recipes define what the cook is making.
   */
  private function hasDefinedStarchOutcome(string $methodText): bool {
    return preg_match('/\b(?:dumplings?|fritters?|cakes?|patties|flatbreads?|roti|porridge|polenta|mash|stuffing|batter|dough|stew with dumplings?)\b/', $methodText) === 1;
  }

  /**
   * Checks whether root vegetables have a real cooking method and doneness.
   */
  private function hasRootVegetableCookingMethod(string $methodText): bool {
    $hasSizeCue = preg_match('/\b(?:diced|cubed|chopped|chunks?|wedges?|halved|sliced)\b/', $methodText) === 1;
    $hasMethod = preg_match('/\b(?:simmer|boil|steam|pressure cook|high pressure|bake|roast|microwave)\b/', $methodText) === 1;
    $hasTime = preg_match('/\b\d+\s*(?:to|-)?\s*\d*\s*(?:minutes?|mins?|min)\b/', $methodText) === 1;
    $hasDoneness = preg_match('/\b(?:fork[- ]tender|tender|soft|easily pierced|knife slides)\b/', $methodText) === 1;
    return $hasSizeCue && $hasMethod && $hasTime && $hasDoneness;
  }

  /**
   * Checks for cornmeal added to liquid without clump prevention.
   */
  private function hasCornmealLiquidWithoutMethod(string $ingredientText, string $methodText): bool {
    if (preg_match('/\bcornmeal\b/', $ingredientText) !== 1 || preg_match('/\b(?:water|broth|stock|liquid|coconut milk)\b/', $methodText) !== 1) {
      return FALSE;
    }
    return preg_match('/\b(?:gradually|slowly|whisk|stirring constantly|batter|dough|cakes?|patties|fritters?|dumplings?|polenta|porridge)\b/', $methodText) !== 1;
  }

  /**
   * Extracts the first cup measurement associated with a food term.
   */
  private function measuredCups(string $text, string $food): float {
    if (preg_match('/\b((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(cups?|c)\s+[^,.;]{0,50}\b' . preg_quote($food, '/') . '\b/', $text, $matches) !== 1) {
      return 0.0;
    }
    return $this->amountToFloat($matches[1]);
  }

  /**
   * Extracts measured cups of dry grains that need cooking liquid.
   */
  private function measuredDryGrainCups(string $text): float {
    $total = 0.0;
    $grains = 'rice|quinoa|oats?(?!\s+flour)|barley|farro|millet|bulgur|couscous|wheat berries';
    preg_match_all('/\b((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(cups?|c)\s+(?!(?:cooked|prepared|leftover)\b)[^,.;]{0,50}\b(?:' . $grains . ')\b/', $text, $matches);
    foreach ($matches[1] as $amount) {
      $total += $this->amountToFloat($amount);
    }
    return $total;
  }

  /**
   * Extracts measured cups of common cooking liquids.
   */
  private function measuredLiquidCups(string $text): float {
    $total = 0.0;
    preg_match_all('/\b((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(cups?|c)\s+(?:of\s+)?(?:water|broth|stock|coconut milk|liquid)\b/', $text, $matches);
    foreach ($matches[1] as $amount) {
      $total += $this->amountToFloat($amount);
    }
    return $total;
  }

  /**
   * Checks whether the ingredient list includes uncooked dry pasta.
   */
  private function hasDryPasta(string $text): bool {
    $pasta = 'spaghetti|pasta|macaroni|penne|rigatoni|rotini|linguine|fettuccine|farfalle|orzo|noodles?|shells';
    preg_match_all('/\b(?:' . $pasta . ')\b/', $text, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $match) {
      $offset = (int) $match[1];
      $prefix = substr($text, max(0, $offset - 48), min(48, $offset));
      if (preg_match('/\b(?:cooked|prepared|leftover|fresh)\b[^,.;\\]]*$/', $prefix) !== 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks whether instructions actually cook dry pasta.
   */
  private function hasPastaCookingStep(string $methodText, bool $hasMeasuredLiquid): bool {
    $pasta = 'spaghetti|pasta|macaroni|penne|rigatoni|rotini|linguine|fettuccine|farfalle|orzo|noodles?|shells';
    if (preg_match('/\b(?:cook|boil|simmer)\b[^.]*\b(?:' . $pasta . ')\b[^.]*\b(?:al dente|tender|package directions?|package instructions?|done)\b/', $methodText) === 1) {
      return TRUE;
    }
    if (preg_match('/\b(?:cook|boil|simmer)\b[^.]*\b(?:according to|per)\s+(?:the\s+)?package\b/', $methodText) === 1) {
      return TRUE;
    }
    return $hasMeasuredLiquid
      && preg_match('/\b(?:pressure cook|high pressure|manual pressure)\b/', $methodText) === 1
      && preg_match('/\b(?:' . $pasta . ')\b/', $methodText) === 1;
  }

  /**
   * Checks if steamed potato or cauliflower lacks a doneness cue.
   */
  private function requiresSteamDonenessCue(string $ingredientText, string $methodText): bool {
    $hasSteamVegetable = preg_match('/\b(?:potatoes?|cauliflower)\b/', $ingredientText) === 1
      && preg_match('/\b(?:steam|steaming|pressure cook|high pressure)\b/', $methodText) === 1;
    if (!$hasSteamVegetable) {
      return FALSE;
    }
    return preg_match('/\b(?:fork[- ]tender|tender|soft|easily pierced|knife slides)\b/', $methodText) !== 1;
  }

  /**
   * Checks if the recipe uses an air fryer.
   */
  private function usesAirFryer(string $methodText): bool {
    return preg_match('/\bair[- ]?fry(?:er|ing)?\b/', $methodText) === 1;
  }

  /**
   * Checks if air-fryer steps include time and temperature.
   */
  private function hasAirFryerTimeAndTemperature(string $methodText): bool {
    $hasTemperature = preg_match('/\b(?:\d{3}\s*(?:°|degrees?\s*)?(?:f|fahrenheit|c|celsius)|medium[- ]high|high heat)\b/', $methodText) === 1;
    $hasTime = preg_match('/\b\d+\s*(?:to|-)?\s*\d*\s*(?:minutes?|mins?|min)\b/', $methodText) === 1;
    return $hasTemperature && $hasTime;
  }

  /**
   * Finds the first explicitly used appliance missing from selected settings.
   *
   * @param array<int, array<string, mixed>> $applianceSettings
   *   Structured appliance settings.
   * @param string $methodText
   *   Normalized recipe method text.
   */
  private function firstUnselectedAppliance(array $applianceSettings, string $methodText): ?string {
    $selected = strtolower(json_encode($applianceSettings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $appliances = [
      'air fryer' => '/\bair[- ]?fry(?:er|ing)?\b/',
      'pressure cooker' => '/\b(?:instant pot|pressure cook|high pressure|manual pressure)\b/',
      'oven' => '/\b(?:oven|bake|roast)\b/',
      'blender' => '/\bblender\b/',
      'food processor' => '/\bfood processor\b/',
      'stovetop' => '/\b(?:saucepan|sauce pan|frying pan|stovetop|on the stove)\b/',
    ];
    $selectedAliases = [
      'air fryer' => '/\bair[- ]?fry(?:er)?\b/',
      'pressure cooker' => '/\b(?:instant pot|pressure cooker)\b/',
      'oven' => '/\boven\b/',
      'blender' => '/\bblender\b/',
      'food processor' => '/\bfood processor\b/',
      'stovetop' => '/\b(?:stove|stovetop|cooktop|range)\b/',
    ];
    foreach ($appliances as $label => $pattern) {
      if (preg_match($pattern, $methodText) === 1 && preg_match($selectedAliases[$label], $selected) !== 1) {
        return $label;
      }
    }
    return NULL;
  }

  /**
   * Checks for uncooked brown rice simmered for too short a time.
   *
   * @param string $ingredientText
   *   Normalized ingredient text.
   * @param array<int, array<string, mixed>> $instructions
   *   Instruction records.
   */
  private function undercooksBrownRice(string $ingredientText, array $instructions): bool {
    if (preg_match('/\bbrown rice\b/', $ingredientText) !== 1 || preg_match('/\b(?:cooked|leftover)\s+brown rice\b/', $ingredientText) === 1) {
      return FALSE;
    }
    foreach ($instructions as $instruction) {
      foreach (preg_split('/(?<=[.!?])\s+/', strtolower((string) ($instruction['text'] ?? ''))) ?: [] as $sentence) {
        if (preg_match('/\brice\b/', $sentence) !== 1 || preg_match('/\bsimmer/', $sentence) !== 1 || preg_match('/\b(?:pressure|rice cooker)\b/', $sentence) === 1) {
          continue;
        }
        preg_match_all('/\b(\d+)\s*(?:-|to)?\s*(\d+)?\s*(?:minutes?|mins?)\b/', $sentence, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
          if ((int) (($match[2] ?? '') !== '' ? $match[2] : $match[1]) < 35) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Lists nutrition keys whose values are not plain estimates.
   *
   * @param array<string, mixed> $nutrition
   *   Nutrition values.
   *
   * @return array<int, string>
   *   Malformed keys.
   */
  private function malformedNutrition(array $nutrition): array {
    $bad = [];
    if (array_key_exists('calories', $nutrition)) {
      $calories = trim((string) $nutrition['calories']);
      if (preg_match('/^\d+$/', $calories) !== 1 || (int) $calories < 50 || (int) $calories > 1500) {
        $bad[] = 'calories';
      }
    }
    foreach (['protein', 'fiber'] as $key) {
      if (array_key_exists($key, $nutrition) && preg_match('/^\d+(?:\.\d+)?\s*g$/i', trim((string) $nutrition[$key])) !== 1) {
        $bad[] = $key;
      }
    }
    return $bad;
  }

  /**
   * Converts a simple decimal or fractional amount to a float.
   */
  private function amountToFloat(string $amount): float {
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

}
