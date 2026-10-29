<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Service\SweetenerLimiter;
use Drupal\fresco_recipe\Validation\Sweetener;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests sweetener measurement and request-side limiting.
 */
#[Group('fresco_ai')]
final class SweetenerLimiterTest extends UnitTestCase {

  /**
   * Confirms sweetener lines are measured and non-sweeteners are ignored.
   */
  public function testMeasuresSweetenerLines(): void {
    self::assertSame(24.0, Sweetener::teaspoons('1/2 cup sugar'));
    self::assertSame(4.5, Sweetener::teaspoons('1 1/2 tbsp maple syrup'));
    self::assertSame(1.0, Sweetener::teaspoons('agave, to taste'));
    self::assertNull(Sweetener::teaspoons('2 cups sugar snap peas'));
    self::assertNull(Sweetener::teaspoons('1 large sweet potato'));
    self::assertNull(Sweetener::teaspoons('3 ripe bananas'));
  }

  /**
   * Confirms an oversized entered sweetener is cut to the budget and reported.
   */
  public function testScalesOversizedSweetenerToBudget(): void {
    $result = (new SweetenerLimiter())->limit(['3 ripe bananas', '1/2 cup sugar', '2 cups flour']);

    self::assertSame(['3 ripe bananas', '4 tbsp sugar', '2 cups flour'], $result['ingredients']);
    self::assertSame([['from' => '1/2 cup sugar', 'to' => '4 tbsp sugar']], $result['swaps']);
  }

  /**
   * Confirms several sweeteners are scaled proportionally within the budget.
   */
  public function testScalesSeveralSweetenersProportionally(): void {
    $result = (new SweetenerLimiter())->limit(['1/2 cup brown sugar', '2 tbsp maple syrup']);

    self::assertSame(['9 1/2 tsp brown sugar', '2 1/4 tsp maple syrup'], $result['ingredients']);
    self::assertLessThanOrEqual(12.0, array_sum(array_map([Sweetener::class, 'teaspoons'], $result['ingredients'])));
  }

  /**
   * Confirms in-budget sweeteners are left untouched.
   */
  public function testLeavesInBudgetSweetenerAlone(): void {
    $lines = ['1 tbsp maple syrup', '2 cups sugar snap peas'];
    $result = (new SweetenerLimiter())->limit($lines);

    self::assertSame($lines, $result['ingredients']);
    self::assertSame([], $result['swaps']);
  }

}
