<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Unit;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\fresco_recipe\Controller\RecipeExportController;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests recipe export formats.
 */
final class RecipeExportControllerTest extends UnitTestCase {

  /**
   * Confirms PDF export returns a binary PDF response.
   */
  public function testPdfExportResponse(): void {
    $controller = new RecipeExportController(
      new RecipeNodeMapper(new RecipeContractFactory()),
      $this->transliteration(),
      new RecipeSvgBuilder(),
    );

    $node = $this->recipeNode();
    $response = $controller->export($node, 'pdf');

    self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    self::assertStringContainsString('.pdf"', $response->headers->get('Content-Disposition'));
    self::assertStringStartsWith('%PDF-1.4', $response->getContent());
  }

  /**
   * Confirms long instructions wrap within portrait pages and paginate.
   */
  public function testPdfExportWrapsLongInstructions(): void {
    $controller = new RecipeExportController(
      new RecipeNodeMapper(new RecipeContractFactory()),
      $this->transliteration(),
      new RecipeSvgBuilder(),
    );

    $longStep = str_repeat('Simmer the beans with garlic and thyme until tender. ', 120);
    $pdf = (string) $controller->export($this->recipeNode([['value' => $longStep]]), 'pdf')->getContent();

    self::assertStringNotContainsString('612 792] /Rotate', $pdf);
    self::assertMatchesRegularExpression('/\/Count [2-9]/', $pdf);
    preg_match_all('/\((.*?)\) Tj/', $pdf, $matches);
    self::assertNotEmpty($matches[1]);
    self::assertLessThanOrEqual(90, max(array_map('strlen', $matches[1])));
  }

  /**
   * Builds a transliteration stub.
   */
  private function transliteration(): TransliterationInterface {
    $transliteration = $this->createMock(TransliterationInterface::class);
    $transliteration->method('removeDiacritics')->willReturnCallback(static fn (string $string): string => $string);
    $transliteration->method('transliterate')->willReturnCallback(static fn (string $string): string => $string);
    return $transliteration;
  }

  /**
   * Builds a recipe node stub.
   *
   * @param array<int, array<string, string>>|null $instructions
   *   Optional instruction field values.
   */
  private function recipeNode(?array $instructions = NULL): NodeInterface {
    $instructions ??= [
      ['value' => 'Sauté aromatics.'],
      ['value' => 'Simmer until tender.'],
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('recipe');
    $node->method('label')->willReturn('Coconut Callaloo');
    $node->method('get')->willReturnCallback(static function (string $field) use ($instructions): object {
      return match ($field) {
        'field_nutrition' => new FakeFieldItem('{"calories":410}'),
        'field_recipe_description' => new FakeFieldItem('Savory island greens'),
        'field_servings' => new FakeFieldItem('4'),
        'field_prep_minutes' => new FakeFieldItem('15'),
        'field_cook_minutes' => new FakeFieldItem('30'),
        'field_cuisine' => new FakeFieldItem('Caribbean'),
        'field_category' => new FakeFieldItem('Vegan'),
        'field_ingredients' => new FakeFieldList([
          ['value' => '1 bunch callaloo'],
          ['value' => '1 cup coconut milk'],
        ]),
        'field_instructions' => new FakeFieldList($instructions),
        'field_appliance_settings' => new FakeFieldList([
          ['value' => 'Instant Pot|Sauté|15 minutes'],
        ]),
        default => throw new \InvalidArgumentException('Unexpected field ' . $field),
      };
    });
    return $node;
  }

}

/**
 * Minimal field item stub with a scalar value.
 */
final class FakeFieldItem {

  public function __construct(public string $value) {}

}

/**
 * Minimal field list stub with a Drupal-style getValue() method.
 */
final class FakeFieldList {

  /**
   * Creates the field list stub.
   *
   * @param array<int, array<string, mixed>> $values
   *   Field values.
   */
  public function __construct(private array $values) {}

  /**
   * Returns stored field values.
   *
   * @return array<int, array<string, mixed>>
   *   Field values.
   */
  public function getValue(): array {
    return $this->values;
  }

}
