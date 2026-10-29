<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

/**
 * Estimates food weight and spice amounts in ingredient lines.
 *
 * Salt, aromatics such as onion and garlic, and grains are not counted.
 */
final class Seasoning {

  /**
   * Minimum spice, in teaspoons per pound of main ingredients.
   */
  public const MIN_TSP_PER_POUND = 0.75;

  /**
   * Spice the fallback aims for, in teaspoons per pound.
   */
  public const TARGET_TSP_PER_POUND = 1.0;

  /**
   * Below this many pounds a recipe is too small to judge.
   */
  public const MIN_POUNDS = 1.0;

  /**
   * Leading amount and unit, such as "1 1/2 teaspoons" or "2 large".
   */
  private const AMOUNT = '/^\s*((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(?:\([^)]*\)\s*)?([a-z]+)?/';

  /**
   * Spices, dried herbs, and savory pastes that season a dish.
   */
  private const SEASONING = '/\b(?:cumin|paprika|coriander|turmeric|curry powder|garam masala|chil(?:i|e) (?:powder|flakes)|pepper flakes|cayenne|(?:black|white|ground) pepper|peppercorns|oregano|thyme|basil|rosemary|sage|marjoram|dill|tarragon|caraway|fennel seeds?|mustard (?:seeds?|powder)|allspice|cinnamon|nutmeg|ground cloves|ground ginger|ginger powder|garlic powder|onion powder|italian seasoning|herbes de provence|za\'?atar|sumac|berbere|jerk seasoning|five[- ]spice|cajun seasoning|creole seasoning|chipotle|ancho|nutritional yeast|curry paste|harissa|gochujang|miso|tamari|soy sauce|liquid aminos|coconut aminos|herbs?|spices?|seasoning)\b/';

  /**
   * Foods weighed as the bulk of the dish, with pounds for one medium item.
   */
  private const BULK = [
    'cabbage' => 2.0,
    'cauliflower' => 2.0,
    'butternut squash' => 2.0,
    'squash' => 1.5,
    'broccoli' => 1.0,
    'eggplant' => 1.0,
    'aubergine' => 1.0,
    'sweet potato' => 0.6,
    'potato' => 0.5,
    'zucchini' => 0.5,
    'plantain' => 0.5,
    'bell pepper' => 0.4,
    'corn' => 0.4,
    'tomato' => 0.3,
    'carrot' => 0.15,
    'mushroom' => 0.05,
    'okra' => 0.03,
    'tofu' => 0.875,
    'tempeh' => 0.5,
    'chickpea' => 0.0,
    'bean' => 0.0,
    'lentil' => 0.0,
    'pea' => 0.0,
    'spinach' => 0.0,
    'kale' => 0.0,
    'collard' => 0.0,
    'chard' => 0.0,
    'green' => 0.0,
  ];

  /**
   * Leafy greens, which weigh little per cup.
   */
  private const LEAFY = ['spinach', 'kale', 'collard', 'chard', 'green'];

  /**
   * Returns TRUE when a line is a spice, dried herb, or savory paste.
   */
  public static function isSeasoning(string $line): bool {
    return preg_match(self::SEASONING, strtolower($line)) === 1;
  }

  /**
   * Sums the estimated pounds of vegetables, legumes, and tofu.
   *
   * @param array<int, string> $lines
   *   Ingredient lines.
   */
  public static function pounds(array $lines): float {
    $total = 0.0;
    foreach ($lines as $line) {
      if (!self::isSeasoning($line)) {
        $total += self::linePounds(strtolower($line));
      }
    }
    return $total;
  }

  /**
   * Sums the measured teaspoons of spices, dried herbs, and savory pastes.
   *
   * @param array<int, string> $lines
   *   Ingredient lines.
   */
  public static function teaspoons(array $lines): float {
    $total = 0.0;
    foreach ($lines as $line) {
      if (self::isSeasoning($line)) {
        $total += self::lineTeaspoons(strtolower($line));
      }
    }
    return $total;
  }

  /**
   * Scales measured spice lines up to the target for the food's weight.
   *
   * @param array<int, string> $lines
   *   Ingredient lines.
   *
   * @return array<int, string>
   *   Lines with larger spice amounts when the dish was under-seasoned.
   */
  public static function scaleToWeight(array $lines): array {
    $pounds = self::pounds($lines);
    $teaspoons = self::teaspoons($lines);
    if ($pounds < self::MIN_POUNDS || $teaspoons <= 0.0 || $teaspoons >= $pounds * self::TARGET_TSP_PER_POUND) {
      return $lines;
    }
    $scale = $pounds * self::TARGET_TSP_PER_POUND / $teaspoons;
    foreach ($lines as $index => $line) {
      $current = self::isSeasoning($line) ? self::lineTeaspoons(strtolower($line)) : 0.0;
      if ($current > 0.0) {
        // Round up to a quarter teaspoon so the total reaches the target.
        $lines[$index] = Sweetener::withTeaspoons($line, ceil($current * $scale * 4) / 4);
      }
    }
    return $lines;
  }

  /**
   * Returns the teaspoons of seasoning in one measured line.
   */
  private static function lineTeaspoons(string $line): float {
    if (preg_match(self::AMOUNT, $line, $matches) !== 1) {
      return 0.0;
    }
    $amount = self::amount($matches[1]);
    $unit = $matches[2] ?? '';
    $teaspoons = match (TRUE) {
      str_starts_with($unit, 'tsp') || str_starts_with($unit, 'teaspoon') => $amount,
      str_starts_with($unit, 'tbsp') || str_starts_with($unit, 'tablespoon') => $amount * 3.0,
      str_starts_with($unit, 'cup') => $amount * 48.0,
      default => 0.0,
    };
    // Fresh herbs carry about a third of the flavor of dried ones.
    return str_contains($line, 'fresh') ? $teaspoons / 3.0 : $teaspoons;
  }

  /**
   * Estimates the pounds of bulk food in one line, or zero for other foods.
   */
  private static function linePounds(string $line): float {
    $food = NULL;
    foreach (self::BULK as $name => $pounds) {
      if (preg_match('/\b' . $name . '(?:e?s)?\b/', $line) === 1) {
        $food = [$name, $pounds];
        break;
      }
    }
    if ($food === NULL || preg_match('/\b(?:powder|broth|stock|milk|sauce|paste|flour|pasta|tortillas?|chips|bread|crackers?|starch|onions?|scallions?|garlic|ginger|shallots?|leeks?)\b/', $line) === 1) {
      return 0.0;
    }
    if (preg_match(self::AMOUNT, $line, $matches) !== 1) {
      return 0.0;
    }
    $amount = self::amount($matches[1]);
    $unit = $matches[2] ?? '';
    return match (TRUE) {
      in_array($unit, ['lb', 'lbs', 'pound', 'pounds'], TRUE) => $amount,
      in_array($unit, ['oz', 'ounce', 'ounces'], TRUE) => $amount / 16.0,
      in_array($unit, ['g', 'gram', 'grams'], TRUE) => $amount / 454.0,
      in_array($unit, ['can', 'cans'], TRUE) => $amount * 0.94,
      in_array($unit, ['block', 'blocks', 'package', 'packages'], TRUE) => $amount * 0.875,
      str_starts_with($unit, 'cup') => $amount * (in_array($food[0], self::LEAFY, TRUE) ? 0.07 : (preg_match('/\b(?:dry|dried)\b/', $line) === 1 ? 1.0 : 0.3)),
      in_array($unit, ['tsp', 'teaspoon', 'teaspoons', 'tbsp', 'tablespoon', 'tablespoons', 'clove', 'cloves', 'pinch'], TRUE) => 0.0,
      $unit === 'large' => $amount * $food[1] * 1.5,
      $unit === 'small' => $amount * $food[1] * 0.6,
      default => $amount * $food[1],
    };
  }

  /**
   * Converts "1", "1.5", "1/2", or "1 1/2" to a float.
   */
  private static function amount(string $amount): float {
    $total = 0.0;
    foreach (preg_split('/\s+/', trim($amount)) ?: [] as $part) {
      if (str_contains($part, '/')) {
        [$numerator, $denominator] = array_map('floatval', explode('/', $part, 2));
        $total += $denominator > 0 ? $numerator / $denominator : 0.0;
      }
      else {
        $total += (float) $part;
      }
    }
    return $total;
  }

}
