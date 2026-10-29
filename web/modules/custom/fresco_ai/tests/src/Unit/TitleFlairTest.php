<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Fallback\TitleFlair;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests evocative fallback titles.
 */
#[Group('fresco_ai')]
final class TitleFlairTest extends UnitTestCase {

  /**
   * Confirms titles gain a stable lead word and keep the dish word last.
   */
  public function testAddsStableLeadWordAndKeepsDishWord(): void {
    $title = TitleFlair::add('Black Bean Stew');

    self::assertSame($title, TitleFlair::add('Black Bean Stew'));
    self::assertStringEndsWith(' Black Bean Stew', $title);
    self::assertNotSame('Black Bean Stew', $title);
  }

  /**
   * Confirms the cuisine is dropped before the name exceeds five words.
   */
  public function testDropsCuisineToStayWithinFiveWords(): void {
    $title = TitleFlair::add('Indian Sweet Potato Cornmeal Cakes', 'Indian');

    self::assertStringEndsWith('Sweet Potato Cornmeal Cakes', $title);
    self::assertStringNotContainsString('Indian', $title);
    self::assertLessThanOrEqual(5, str_word_count($title, 0, '-'));
  }

  /**
   * Confirms titles that cannot fit five words are left unchanged.
   */
  public function testLeavesLongTitlesAlone(): void {
    self::assertSame('Steamed Sweet Potato with Garlic', TitleFlair::add('Steamed Sweet Potato with Garlic'));
  }

}
