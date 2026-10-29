<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Service\VeganSubstituter;
use Drupal\fresco_recipe\Validation\Rule\VeganRule;
use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests vegan substitution of entered ingredient lines.
 */
#[Group('fresco_ai')]
final class VeganSubstituterTest extends UnitTestCase {

  /**
   * Provides entered lines and their expected vegan rewrite.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Input line and expected output line.
   */
  public static function lines(): array {
    return [
      'chicken keeps quantity' => ['1 lb boneless skinless chicken breasts, cubed', '1 lb extra-firm tofu, cubed'],
      'eggs outside baking' => ['2 large eggs', '7 oz firm tofu, crumbled'],
      'single egg outside baking' => ['1 egg', '4 oz firm tofu, crumbled'],
      'plain milk' => ['1 cup milk', '1 cup oat milk'],
      'whole milk' => ['1 cup whole milk', '1 cup oat milk'],
      'named cheese' => ['1 cup shredded cheddar cheese', '1 cup shredded vegan cheddar'],
      'butter' => ['2 tbsp unsalted butter', '2 tbsp unsalted vegan butter'],
      'honey' => ['1 tbsp honey', '1 tbsp maple syrup'],
      'broth' => ['4 cups chicken broth', '4 cups vegetable broth'],
      'fish sauce' => ['1 tbsp fish sauce', '1 tbsp vegan fish sauce'],
      'heavy cream' => ['1/2 cup heavy cream', '1/2 cup coconut cream'],
      'ice cream' => ['2 cups ice cream', '2 cups vegan ice cream'],
      'cream cheese' => ['8 oz cream cheese', '8 oz vegan cream cheese'],
      'ground beef' => ['1 lb ground beef', '1 lb plant-based ground'],
      'shrimp' => ['12 oz shrimp', '12 oz king oyster mushrooms'],
      'egg noodles' => ['8 oz egg noodles', '8 oz vegan egg-style noodles'],
    ];
  }

  /**
   * Confirms each animal product is replaced while the line stays usable.
   */
  #[DataProvider('lines')]
  public function testSubstitutesAnimalProducts(string $line, string $expected): void {
    $result = (new VeganSubstituter())->substitute([$line]);
    self::assertSame([$expected], $result['ingredients']);
    self::assertSame([['from' => $line, 'to' => $expected]], $result['swaps']);
    self::assertSame([], (new VeganRule())->validate($this->recipe($expected)), $expected . ' should pass the vegan rule.');
  }

  /**
   * Confirms eggs become flax eggs when the ingredients are for baking.
   */
  public function testEggsBecomeFlaxEggsWhenBaking(): void {
    $result = (new VeganSubstituter())->substitute(['2 cups flour', '2 large eggs', '2 egg whites', '1 egg yolk']);

    self::assertSame([
      '2 cups flour',
      '2 large flax eggs (1 tbsp ground flaxseed + 3 tbsp water each)',
      '2 aquafaba (3 tbsp chickpea liquid each)',
      '1 flax egg (1 tbsp ground flaxseed + 3 tbsp water)',
    ], $result['ingredients']);
    self::assertCount(3, $result['swaps']);
  }

  /**
   * Provides lines that are already vegan and must not change.
   *
   * @return array<int, array{0: string}>
   *   Already-vegan input line.
   */
  public static function veganLines(): array {
    return [
      ['1 can coconut milk'],
      ['1 cup unsweetened almond milk'],
      ['2 tbsp peanut butter'],
      ['1 can butter beans, drained'],
      ['1 medium butternut squash, cubed'],
      ['1 large eggplant, diced'],
      ['1 cup vegan cheddar'],
      ['2 tbsp vegan butter'],
      ['1/2 tsp cream of tartar'],
      ['4 cups vegetable broth'],
      ['3 vegan sausages, sliced'],
    ];
  }

  /**
   * Confirms already-vegan lines pass through unchanged with no swaps.
   */
  #[DataProvider('veganLines')]
  public function testLeavesVeganLinesAlone(string $line): void {
    $result = (new VeganSubstituter())->substitute([$line]);
    self::assertSame([$line], $result['ingredients']);
    self::assertSame([], $result['swaps']);
  }

  /**
   * Builds a minimal recipe containing one ingredient line.
   */
  private function recipe(string $line): RecipeContract {
    return new RecipeContract(
      title: 'Test',
      description: 'Test',
      servings: 4,
      prepTimeMinutes: 10,
      cookTimeMinutes: 10,
      cuisine: 'Global',
      category: 'Vegan',
      ingredients: [['original_text' => $line]],
      instructions: [['text' => 'Cook.']],
      applianceSettings: [['appliance' => 'Stovetop', 'mode' => 'Cook', 'time' => '10 minutes']],
      nutrition: [],
    );
  }

}
