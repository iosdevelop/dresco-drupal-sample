<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

/**
 * Recognizes added-sweetener ingredient lines and measures them.
 */
final class Sweetener {

  /**
   * Added sweeteners; fruit and sweet potatoes are not added sugar.
   */
  private const PATTERN = '/\b(?:(?:brown|cane|coconut|powdered|confectioners\'?|granulated|raw|turbinado|white)\s+)?(?:sugar|agave(?:\s+(?:syrup|nectar))?|maple syrup|molasses|date syrup|brown rice syrup|golden syrup|corn syrup)\b/';

  /**
   * A leading amount and volume unit, such as "1 1/2 cups".
   */
  private const MEASURE = '/^\s*((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(teaspoons?|tsp|tablespoons?|tbsp|cups?)\b\s*/';

  /**
   * Returns the added sweetener in a line, in teaspoons, or NULL if none.
   *
   * An unmeasured sweetener ("maple syrup, to taste") counts as one teaspoon.
   */
  public static function teaspoons(string $line): ?float {
    $text = strtolower($line);
    if (preg_match('/\bsugar[- ](?:free|snap|pumpkin)\b|\bno added sugar\b/', $text) === 1 || preg_match(self::PATTERN, $text) !== 1) {
      return NULL;
    }
    if (preg_match(self::MEASURE, $text, $matches) !== 1) {
      return 1.0;
    }
    $amount = self::amountToFloat($matches[1]);
    return match (TRUE) {
      str_starts_with($matches[2], 'c') => $amount * 48.0,
      str_starts_with($matches[2], 'tb') || str_starts_with($matches[2], 'tablespoon') => $amount * 3.0,
      default => $amount,
    };
  }

  /**
   * Replaces a measured sweetener line's amount with a smaller one.
   *
   * @param string $line
   *   A line that teaspoons() measured.
   * @param float $teaspoons
   *   The new amount in teaspoons.
   */
  public static function withTeaspoons(string $line, float $teaspoons): string {
    $measure = $teaspoons >= 3.0 && fmod($teaspoons, 3.0) < 0.0001
      ? self::formatAmount($teaspoons / 3.0) . ' tbsp '
      : self::formatAmount($teaspoons) . ' tsp ';
    $rewritten = preg_replace(self::MEASURE, $measure, $line, 1, $count);
    return $count === 1 && $rewritten !== NULL ? $rewritten : $measure . $line;
  }

  /**
   * Converts "1", "1.5", "1/2", or "1 1/2" to a float.
   */
  private static function amountToFloat(string $amount): float {
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

  /**
   * Formats 1.5 as "1 1/2" and 0.5 as "1/2".
   */
  private static function formatAmount(float $amount): string {
    $whole = (int) floor($amount);
    $fraction = match ((int) round(($amount - $whole) * 4)) {
      1 => '1/4',
      2 => '1/2',
      3 => '3/4',
      default => '',
    };
    return trim(($whole > 0 ? $whole . ' ' : '') . $fraction) ?: '0';
  }

}
