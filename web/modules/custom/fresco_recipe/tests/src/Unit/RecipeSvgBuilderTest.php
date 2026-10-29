<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Unit;

use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests deterministic and safe recipe SVG artwork.
 */
#[Group('fresco_recipe')]
final class RecipeSvgBuilderTest extends UnitTestCase {

  /**
   * Confirms artwork is deterministic, accessible, and escapes recipe titles.
   */
  public function testSafeDeterministicArtwork(): void {
    $builder = new RecipeSvgBuilder();
    $ingredients = ['1 cup rice', '1 Scotch bonnet pepper'];
    $svg = $builder->build('Rice & <Pepper>', $ingredients);

    self::assertSame($svg, $builder->build('Rice & <Pepper>', $ingredients));
    self::assertStringContainsString('aria-labelledby="title desc"', $svg);
    self::assertStringContainsString('Rice &amp; &lt;Pepper&gt;', $svg);
    self::assertStringNotContainsString('<Pepper>', $svg);
    self::assertStringContainsString('#e24b36', $svg);
  }

}
