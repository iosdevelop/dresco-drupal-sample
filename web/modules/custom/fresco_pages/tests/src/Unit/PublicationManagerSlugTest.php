<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\fresco_pages\Service\PublicationManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests publication slug derivation for migrated recipe titles.
 */
#[Group('fresco_pages')]
final class PublicationManagerSlugTest extends UnitTestCase {

  /**
   * Confirms ingredient-count titles publish to clean canonical slugs.
   */
  public function testLeadingIngredientCountIsRemoved(): void {
    self::assertSame('eggplant-thai-stew', $this->slug('1 Eggplant Thai Stew'));
    self::assertSame('small-onion-pepperpot-soup', $this->slug('1 Small Onion Pepperpot Soup'));
  }

  /**
   * Confirms meaningful numeric recipe names are preserved.
   */
  public function testMeaningfulLeadingNumbersArePreserved(): void {
    self::assertSame('7-layer-dip', $this->slug('7 Layer Dip'));
    self::assertSame('1-pot-pasta', $this->slug('1 Pot Pasta'));
  }

  /**
   * Confirms preserved Pages URLs are normalized only for import artifacts.
   */
  public function testExistingPagesUrlCanBeNormalized(): void {
    self::assertSame(
      'eggplant-thai-stew',
      $this->slug('1 Eggplant Thai Stew', 'https://example.com/recipes/1-eggplant-thai-stew/'),
    );
    self::assertSame(
      '1-pot-pasta',
      $this->slug('1 Pot Pasta', 'https://example.com/recipes/1-pot-pasta/'),
    );
  }

  /**
   * Invokes the private slug helper with only the dependencies it uses.
   */
  private function slug(string $title, string $pagesUrl = ''): string {
    $field = new \stdClass();
    $field->value = $pagesUrl;
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->with('field_pages_url')->willReturn($field);
    $node->method('label')->willReturn($title);
    $node->method('id')->willReturn(36);

    $reflection = new \ReflectionClass(PublicationManager::class);
    $manager = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('transliteration')->setValue($manager, new class implements TransliterationInterface {

      /**
       * {@inheritdoc}
       */
      public function removeDiacritics($string) {
        return (string) $string;
      }

      /**
       * {@inheritdoc}
       */
      public function transliterate($string, $langcode = 'en', $unknown_character = '?', $max_length = NULL) {
        return (string) $string;
      }

    });

    return (string) $reflection->getMethod('slug')->invoke($manager, $node);
  }

}
