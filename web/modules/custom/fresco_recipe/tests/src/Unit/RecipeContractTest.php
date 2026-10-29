<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Unit;

use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the provider-independent recipe contract.
 */
#[Group('fresco_recipe')]
final class RecipeContractTest extends UnitTestCase {

  /**
   * Confirms serialization remains stable for providers and publishers.
   */
  public function testSerialization(): void {
    $recipe = new RecipeContract(
      title: 'Caribbean Chickpea Stew',
      description: 'A bright savory stew.',
      servings: 4,
      prepTimeMinutes: 15,
      cookTimeMinutes: 30,
      cuisine: 'Caribbean',
      category: 'Vegan',
      ingredients: [['food' => 'chickpeas']],
      instructions: [['text' => 'Simmer until tender.']],
      applianceSettings: [],
      nutrition: ['protein' => '12g'],
    );

    self::assertSame('1.0', $recipe->toArray()['schema_version']);
    self::assertSame('Caribbean Chickpea Stew', $recipe->toArray()['title']);
  }

  /**
   * Confirms diet labels are dropped from titles.
   */
  public function testFactoryDropsDietLabelsFromTitle(): void {
    $factory = new RecipeContractFactory();
    $titles = [
      'Vegetarian Instant Pot Stew' => 'Instant Pot Stew',
      'Home-Style Vegan Vegetarian Pho' => 'Home-Style Pho',
      'Plant-Based Dumplings' => 'Dumplings',
      'Vegan' => 'Vegan',
    ];
    foreach ($titles as $title => $expected) {
      $recipe = $factory->fromArray([
        'title' => $title,
        'description' => 'A stew.',
        'servings' => 4,
        'prep_time_minutes' => 10,
      ]);
      self::assertSame($expected, $recipe->title);
    }
  }

  /**
   * Confirms canned bean hints survive provider normalization.
   */
  public function testFactoryPreservesCannedBeanForm(): void {
    $factory = new RecipeContractFactory();
    $recipe = $factory->fromArray([
      'title' => 'Bean Stew',
      'description' => 'A stew.',
      'servings' => 4,
      'prep_time_minutes' => 10,
      'cook_time_minutes' => 20,
      'ingredients' => [
        ['food' => 'red kidney beans', 'original_text' => 'red kidney beans'],
      ],
      'instructions' => [],
      'appliance_settings' => [],
      'nutrition' => ['protein' => '10g'],
    ], ['1 can red kidney beans']);

    self::assertSame('1 can red kidney beans', $recipe->ingredients[0]['food']);
    self::assertSame('1 can red kidney beans', $recipe->ingredients[0]['original_text']);
  }

  /**
   * Confirms simplified bean names still inherit canned state from hints.
   */
  public function testFactoryPreservesCannedBeanFormWithSimplifiedProviderOutput(): void {
    $factory = new RecipeContractFactory();
    $recipe = $factory->fromArray([
      'title' => 'Bean Stew',
      'description' => 'A stew.',
      'servings' => 4,
      'prep_time_minutes' => 10,
      'cook_time_minutes' => 20,
      'ingredients' => [
        ['food' => 'red beans', 'original_text' => 'red beans'],
      ],
      'instructions' => [],
      'appliance_settings' => [],
      'nutrition' => ['protein' => '10g'],
    ], ['1 can red kidney beans']);

    self::assertSame('1 can red kidney beans', $recipe->ingredients[0]['food']);
    self::assertSame('1 can red kidney beans', $recipe->ingredients[0]['original_text']);
  }

  /**
   * Confirms bare provider lines are rebuilt to name their food.
   */
  public function testFactoryCompletesIngredientLinesMissingTheFood(): void {
    $recipe = (new RecipeContractFactory())->fromArray([
      'title' => 'Cucumber Salad',
      'description' => 'A salad.',
      'servings' => 4,
      'prep_time_minutes' => 10,
      'cook_time_minutes' => 0,
      'ingredients' => [
        ['food' => 'salt', 'original_text' => 'To taste'],
        ['food' => 'olive oil', 'original_text' => '2 tbsp'],
        ['food' => 'cherry tomatoes', 'original_text' => '1 cup cherry tomatoes, halved'],
        ['food' => 'black pepper', 'original_text' => ''],
      ],
      'instructions' => [['text' => 'Toss.']],
      'appliance_settings' => [['appliance' => 'No-cook (prep only)', 'mode' => 'Prep only', 'time' => '10 minutes']],
    ]);

    self::assertSame(
      ['salt, to taste', '2 tbsp olive oil', '1 cup cherry tomatoes, halved', 'black pepper'],
      array_column($recipe->ingredients, 'original_text'),
    );
  }

}
