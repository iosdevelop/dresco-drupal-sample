<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\fresco_recipe\Validation\Rule\SavoryLimitsRule;
use Drupal\fresco_recipe\Validation\Sweetener;

/**
 * Scales entered sweeteners down to Dresco's limit before generation.
 *
 * Models are told to preserve entered quantities, so an entered "1/2 cup
 * sugar" would otherwise conflict with the sweetener limit on every attempt.
 */
final class SweetenerLimiter {

  /**
   * Servings assumed for the budget; generated recipes default to four.
   */
  private const BUDGET_SERVINGS = 4;

  /**
   * Scales measured sweetener lines so their total fits the budget.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   *
   * @return array{ingredients: array<int, string>, swaps: array<int, array{from: string, to: string}>}
   *   Rewritten lines and the original-to-reduced pairs that changed.
   */
  public function limit(array $ingredients): array {
    $budget = SavoryLimitsRule::SWEET_TSP_PER_SERVING * self::BUDGET_SERVINGS;
    $measured = array_filter(array_map(
      static fn (string $line): ?float => Sweetener::teaspoons($line),
      $ingredients,
    ), static fn (?float $teaspoons): bool => $teaspoons !== NULL);
    $total = array_sum($measured);
    if ($total <= $budget + 0.0001) {
      return ['ingredients' => $ingredients, 'swaps' => []];
    }

    $scale = $budget / $total;
    $swaps = [];
    foreach ($measured as $index => $teaspoons) {
      // Round down to a quarter teaspoon so the scaled total stays in budget.
      $reduced = max(0.25, floor($teaspoons * $scale * 4) / 4);
      $line = Sweetener::withTeaspoons($ingredients[$index], $reduced);
      $swaps[] = ['from' => $ingredients[$index], 'to' => $line];
      $ingredients[$index] = $line;
    }
    return ['ingredients' => $ingredients, 'swaps' => $swaps];
  }

}
