<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Eval\RecipeQualityChecker;
use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the evaluation quality checks.
 */
#[Group('fresco_ai')]
final class RecipeQualityCheckerTest extends UnitTestCase {

  /**
   * The smoothie request that the fallback once turned into a stew.
   *
   * @var array<string, mixed>
   */
  private const SMOOTHIE_REQUEST = [
    'ingredients' => ['1 banana', '1 cup frozen berries', '1 cup oat milk'],
    'appliances' => ['Blender'],
  ];

  /**
   * Confirms a real smoothie passes every check.
   */
  public function testGoodSmoothiePasses(): void {
    $ingredients = ['1 banana', '1 cup frozen berries', '1 cup oat milk', '1/2 tsp vanilla'];
    $recipe = $this->recipe('Berry Banana Smoothie', $ingredients, [
      'Add the banana, frozen berries, oat milk, and vanilla to the blender.',
      'Blend on high for 45 seconds until smooth.',
    ]);

    self::assertSame([
      'uses_ingredients' => [],
      'no_unlisted' => [],
      'method' => [],
      'title' => [],
    ], (new RecipeQualityChecker())->check(self::SMOOTHIE_REQUEST, $recipe, '/smoothie/i'));
  }

  /**
   * Confirms the "Banana Stew" fallback fails each check it should.
   */
  public function testBananaStewFailsQualityChecks(): void {
    $recipe = $this->recipe('Banana Stew', self::SMOOTHIE_REQUEST['ingredients'], [
      'Sauté garlic, scallions, thyme, and allspice until fragrant.',
      'Simmer 1 banana and 1 cup frozen berries until tender and cohesive.',
      'Finish with fresh lime juice and herbs; adjust heat to taste.',
    ]);

    $checks = (new RecipeQualityChecker())->check(self::SMOOTHIE_REQUEST, $recipe, '/smoothie/i');

    self::assertSame(['milk'], $checks['uses_ingredients']);
    self::assertSame(['garlic', 'scallion', 'thyme', 'allspice', 'lime'], $checks['no_unlisted']);
    self::assertSame(['method uses none of the selected appliances (Blender)'], $checks['method']);
    self::assertCount(1, (array) $checks['title']);
  }

  /**
   * Confirms a no-cook recipe that applies heat is flagged.
   */
  public function testNoCookRecipeWithHeatIsFlagged(): void {
    $request = ['ingredients' => ['2 cucumbers'], 'appliances' => ['No-cook (prep only)']];
    $recipe = $this->recipe('Cucumber Salad', ['2 cucumbers'], ['Toss the cucumbers, then sauté briefly.']);

    self::assertSame(['no-cook recipe uses heat ("sauté")'], (new RecipeQualityChecker())->methodProblems($request, $recipe));
  }

  /**
   * Confirms checks that do not apply report NULL.
   */
  public function testInapplicableChecksAreNull(): void {
    $checks = (new RecipeQualityChecker())->check(
      ['mode' => 'restaurant', 'ingredients' => [], 'appliances' => ['Campfire']],
      $this->recipe('Pad Thai Noodles', ['8 oz rice noodles'], ['Cook the rice noodles.']),
    );

    self::assertNull($checks['uses_ingredients']);
    self::assertNull($checks['method']);
    self::assertNull($checks['title']);
  }

  /**
   * Confirms head nouns ignore quantities, packaging, and prep notes.
   */
  public function testHeadNoun(): void {
    self::assertSame('bean', RecipeQualityChecker::headNoun('1 can black beans, drained and rinsed'));
    self::assertSame('banana', RecipeQualityChecker::headNoun('3 ripe bananas'));
    self::assertSame('berry', RecipeQualityChecker::headNoun('1 cup frozen berries'));
    self::assertSame('tomato', RecipeQualityChecker::headNoun('2 large tomatoes'));
    self::assertSame('garlic', RecipeQualityChecker::headNoun('3 cloves garlic'));
    self::assertSame('hummus', RecipeQualityChecker::headNoun('1/2 cup hummus'));
    self::assertSame('broccoli', RecipeQualityChecker::headNoun('1 broccoli crown'));
    self::assertSame('cauliflower', RecipeQualityChecker::headNoun('1 head cauliflower'));
  }

  /**
   * Builds a recipe from titles, ingredient lines, and instruction texts.
   *
   * @param string $title
   *   Recipe title.
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   * @param array<int, string> $instructions
   *   Instruction texts.
   */
  private function recipe(string $title, array $ingredients, array $instructions): RecipeContract {
    return new RecipeContract(
      title: $title,
      description: 'Test recipe.',
      servings: 2,
      prepTimeMinutes: 5,
      cookTimeMinutes: 0,
      cuisine: 'Global',
      category: 'Vegan',
      ingredients: array_map(static fn (string $line): array => ['original_text' => $line], $ingredients),
      instructions: array_map(static fn (string $text): array => ['text' => $text], $instructions),
      applianceSettings: [['appliance' => 'Blender', 'mode' => 'Blend', 'time' => '1 minute']],
      nutrition: [],
    );
  }

}
