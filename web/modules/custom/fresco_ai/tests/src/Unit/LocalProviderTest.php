<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Validation\Rule\FlavorStructureRule;
use Drupal\fresco_recipe\Validation\Rule\InstructionClarityRule;
use Drupal\fresco_recipe\Validation\Rule\RequiredSectionsRule;
use Drupal\fresco_recipe\Validation\Rule\SavoryLimitsRule;
use Drupal\fresco_recipe\Validation\Rule\SeasoningDensityRule;
use Drupal\fresco_recipe\Validation\Rule\TechnicalSafetyRule;
use Drupal\fresco_recipe\Validation\Rule\VeganRule;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the deterministic local provider.
 */
#[Group('fresco_ai')]
final class LocalProviderTest extends UnitTestCase {

  /**
   * Confirms fallback titles use clean ingredient names without quantities.
   */
  public function testFallbackTitleStripsQuantitiesAndPackaging(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['1 can black beans, drained and rinsed', '2 scallions', '1 lime'],
      'appliances' => ['Instant Pot'],
    ]);

    self::assertSame('Lantern Black Bean Stew', $recipe['title']);
    self::assertStringNotContainsString('1', (string) $recipe['title']);
    self::assertStringNotContainsString('Can', (string) $recipe['title']);
    self::assertStringNotContainsString('Global', (string) $recipe['title']);
  }

  /**
   * Confirms title selection skips support liquids.
   */
  public function testFallbackTitleSkipsCookingLiquid(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['1 cup water', '2 large sweet potatoes', '1 teaspoon thyme'],
      'appliances' => ['Instant Pot'],
    ]);

    self::assertSame('Golden Hour Sweet Potato Stew', $recipe['title']);
  }

  /**
   * Confirms dry grains receive enough measured liquid in the fallback recipe.
   */
  public function testDryGrainLiquidScalesWithIngredients(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['2 cups brown rice', '1 can red kidney beans', '2 scallions'],
      'appliances' => ['Instant Pot'],
    ]);

    self::assertStringContainsString('2 cups water', (string) ($recipe['instructions'][1]['text'] ?? ''));
  }

  /**
   * Confirms Caribbean rice and peas uses canned beans and brown-rice timing.
   */
  public function testCaribbeanRiceAndPeasUsesBrownRiceWaterAndInstantPotTiming(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => [
        '1 can red kidney beans',
        '2 cups brown rice',
        '1/2 cup coconut milk',
      ],
      'appliances' => ['Instant Pot Pro WiFi (6 qt)'],
    ]);

    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertSame('Caribbean Rice and Peas', $recipe['title']);
    self::assertStringContainsString('canned kidney beans', $instruction);
    self::assertStringContainsString('coconut milk', $instruction);
    self::assertStringContainsString('2 cups water', $instruction);
    self::assertStringContainsString('Pressure Cook for 22 minutes', $instruction);
    self::assertSame('22 minutes', $recipe['appliance_settings'][0]['time']);
  }

  /**
   * Confirms canned beans are heated through instead of treated as dry beans.
   */
  public function testCannedBeansAreNotPressureCookedLikeDryBeans(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['1 can red kidney beans', '2 scallions'],
      'appliances' => ['Instant Pot'],
    ]);

    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertStringContainsString('canned beans', $instruction);
    self::assertStringContainsString('Sauté', $instruction);
    self::assertStringNotContainsString('Pressure Cook', $instruction);
    self::assertSame('Sauté', $recipe['appliance_settings'][0]['mode']);
  }

  /**
   * Confirms dry pasta is actually cooked instead of only sautéed.
   */
  public function testDryPastaUsesCookingMethod(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['8 ounces spaghetti', '2 cloves garlic', '1 teaspoon thyme'],
      'appliances' => ['Instant Pot'],
    ]);

    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertStringContainsString('2 cups water', $instruction);
    self::assertStringContainsString('Pressure Cook spaghetti for 5 minutes', $instruction);
    self::assertStringContainsString('al dente', $instruction);
    self::assertSame('Sauté / Pressure Cook', $recipe['appliance_settings'][0]['mode']);
    self::assertSame('5 minutes', $recipe['appliance_settings'][0]['time']);
  }

  /**
   * Confirms fallback instructions name meaningful non-grain ingredients.
   */
  public function testFallbackInstructionsMentionSpecificIngredientsAndLiquids(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => [
        '1 eggplant',
        '2 potatoes',
        'tofu',
        '1/2 cup coconut milk',
      ],
      'appliances' => ['Instant Pot Pro WiFi (6 qt)'],
    ]);

    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertStringContainsString('1 eggplant', $instruction);
    self::assertStringContainsString('2 potatoes', $instruction);
    self::assertStringContainsString('tofu', $instruction);
    self::assertStringContainsString('1/2 cup coconut milk', $instruction);
    self::assertStringContainsString('fork-tender', $instruction);
    self::assertLessThanOrEqual(128, strlen($instruction));
    self::assertStringNotContainsString('prepared ingredients', $instruction);
  }

  /**
   * Confirms flour, cornmeal, and sweet potato become a defined dish.
   */
  public function testFlourCornmealAndSweetPotatoBecomeMeasuredCakes(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['all-purpose flour', 'cornmeal', 'Sweet potato'],
      'cuisine_vibe' => 'Indian',
      'appliances' => ['Instant Pot'],
    ]);

    $ingredientText = strtolower(json_encode($recipe['ingredients'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertSame('Ember Sweet Potato Cornmeal Cakes', $recipe['title']);
    self::assertStringContainsString('1 cup all-purpose flour', $ingredientText);
    self::assertStringContainsString('1/2 cup cornmeal', $ingredientText);
    self::assertStringContainsString('1 large sweet potato, peeled and diced', $ingredientText);
    self::assertStringStartsWith('Put 1 large sweet potato and 1 cup water into the Instant Pot', $instruction);
    self::assertStringContainsString('Pressure Cook the sweet potato on High for 2 minutes until fork-tender', $instruction);
    self::assertStringContainsString('Stir in 1/2 cup cornmeal and 1 cup all-purpose flour gradually', $instruction);
    self::assertStringContainsString('shape into cakes', $instruction);
    self::assertSame('Pressure Cook / Sauté', $recipe['appliance_settings'][0]['mode']);
    self::assertSame('2 minutes pressure, 6-8 minutes sauté', $recipe['appliance_settings'][0]['time']);
  }

  /**
   * Confirms the fallback provider honors requested cuisine.
   */
  public function testFallbackHonorsRequestedCuisine(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => ['eggplant', 'potatoes', 'tofu'],
      'cuisine_vibe' => 'ethiopian',
      'appliances' => ['Instant Pot'],
    ]);

    self::assertSame('Ethiopian', $recipe['cuisine']);
    self::assertStringContainsString('Ethiopian', (string) $recipe['title']);
    self::assertStringContainsString('Ethiopian-inspired', (string) $recipe['description']);
  }

  /**
   * Confirms restaurant dish names shape the recipe without becoming food.
   */
  public function testRestaurantDishNameIsInspirationInsteadOfIngredient(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'mode' => 'restaurant',
      'menu_item' => 'Thai Chowmein',
      'liked_notes' => 'Egg noodles, yellow onions, bell pepper, carrots, bean sprouts, green onions, special cooking sauce and a dash of sesame oil.',
      'cuisine_vibe' => 'Thai',
      'ingredient_policy' => 'suggest_missing',
      'ingredients' => ['Thai Chowmein'],
      'appliances' => ['Instant Pot Pro WiFi (6 qt)'],
    ]);

    $ingredientText = strtolower(json_encode($recipe['ingredients'], JSON_THROW_ON_ERROR));
    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertSame('Thai Chow Mein-Inspired Noodles', $recipe['title']);
    self::assertSame('Thai', $recipe['cuisine']);
    self::assertStringNotContainsString('thai chowmein', $ingredientText);
    self::assertStringContainsString('vegan egg-style chow mein noodles', $ingredientText);
    self::assertStringContainsString('yellow onions', $ingredientText);
    self::assertStringContainsString('Pressure Cook for 5 minutes', $instruction);
    self::assertStringContainsString('Sauté with', $instruction);
    self::assertStringContainsString('crisp-tender', $instruction);
    self::assertStringNotContainsString('thai chowmein', strtolower($instruction));
  }

  /**
   * Confirms known restaurant pastries can generate without ingredients.
   */
  public function testRestaurantSamosasUseKnownVeganFillingAndSelectedAppliance(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'mode' => 'restaurant',
      'menu_item' => 'Samosas',
      'liked_notes' => 'seasoned curry tomatoes and airfryed or bake',
      'cuisine_vibe' => 'Indian',
      'ingredient_policy' => 'suggest_missing',
      'ingredients' => [],
      'appliances' => ['Emeril Lagasse Air Fryer Oven'],
    ]);

    $ingredientText = strtolower(json_encode($recipe['ingredients'], JSON_THROW_ON_ERROR));
    $instruction = (string) ($recipe['instructions'][1]['text'] ?? '');
    self::assertSame('Samosas-Inspired Hand Pies', $recipe['title']);
    self::assertSame('Indian', $recipe['cuisine']);
    self::assertStringContainsString('vegan samosa wrappers or pastry sheets', $ingredientText);
    self::assertStringContainsString('potatoes', $ingredientText);
    self::assertStringContainsString('peas', $ingredientText);
    self::assertStringContainsString('tomatoes', $ingredientText);
    self::assertStringContainsString('curry powder', $ingredientText);
    self::assertStringNotContainsString('"samosas"', $ingredientText);
    self::assertStringContainsString('air-fry at 375°F for 10-12 minutes', $instruction);
    self::assertSame('Air Fry', $recipe['appliance_settings'][0]['mode']);
    self::assertSame('10-12 minutes at 375°F', $recipe['appliance_settings'][0]['time']);
  }

  /**
   * Confirms nutrition estimates vary with ingredient profile.
   */
  public function testNutritionEstimateVariesByRecipeIngredients(): void {
    $provider = new LocalProvider([], 'local', []);
    $riceAndBeans = $provider->generate([
      'ingredients' => ['2 cups brown rice', '1 can red kidney beans', '2 scallions'],
      'appliances' => ['Instant Pot'],
    ]);
    $noodles = $provider->generate([
      'ingredients' => ['8 ounces spaghetti', '2 cloves garlic', '1 teaspoon thyme'],
      'appliances' => ['Instant Pot'],
    ]);
    $samosas = $provider->generate([
      'mode' => 'restaurant',
      'menu_item' => 'Samosas',
      'cuisine_vibe' => 'Indian',
      'ingredient_policy' => 'suggest_missing',
      'ingredients' => [],
      'appliances' => ['Emeril Lagasse Air Fryer Oven'],
    ]);

    self::assertNotSame($riceAndBeans['nutrition'], $noodles['nutrition']);
    self::assertNotSame($noodles['nutrition'], $samosas['nutrition']);
    self::assertGreaterThan($noodles['nutrition']['calories'], $riceAndBeans['nutrition']['calories']);
    self::assertMatchesRegularExpression('/^\d+g$/', (string) $samosas['nutrition']['protein']);
    self::assertMatchesRegularExpression('/^\d+g$/', (string) $samosas['nutrition']['fiber']);
  }

  /**
   * Confirms measured pasta and counted vegan sausages are fully represented.
   */
  public function testNutritionEstimateUsesQuantitiesForSausagePastaRecipe(): void {
    $provider = new LocalProvider([], 'local', []);
    $recipe = $provider->generate([
      'ingredients' => [
        '3 vegan sausages',
        '3 cups Penne pasta',
        '1 onion',
        '2 cups mushrooms',
        '1 cup vegetable broth',
        '2 tablespoon tomato paste',
        '1 tablespoon soy sauce',
        '4 cloves garlic',
      ],
      'appliances' => ['Instant Pot Pro WiFi (6 qt)'],
    ]);

    // Includes the lemon and parsley the finishing step now lists.
    self::assertSame([
      'calories' => 390,
      'protein' => '22g',
      'fiber' => '5g',
    ], $recipe['nutrition']);
  }

  /**
   * Confirms no-cook requests produce a valid tossed salad with no heat.
   */
  public function testNoCookRequestProducesValidSalad(): void {
    $data = (new LocalProvider([], 'local', []))->generate([
      'ingredients' => ['2 cucumbers', '1 can chickpeas', '1 cup cherry tomatoes'],
      'appliances' => ['No-cook (prep only)'],
    ]);

    self::assertSame('Market Day Cucumber Salad', $data['title']);
    self::assertSame(0, $data['cook_time_minutes']);
    self::assertSame([['appliance' => 'No-cook (prep only)', 'mode' => 'Prep only', 'time' => '15 minutes']], $data['appliance_settings']);
    $method = strtolower(json_encode($data['instructions'], JSON_THROW_ON_ERROR));
    self::assertDoesNotMatchRegularExpression('/\b(?:simmer|saut|boil|bake|roast)/', $method);
    self::assertSame([], $this->validator()->validate((new RecipeContractFactory())->fromArray($data, [])));
  }

  /**
   * Confirms restaurant defaults are measured, unique, and cleanly listed.
   */
  public function testRestaurantNoodleDefaultsAreMeasuredAndUnique(): void {
    $data = (new LocalProvider([], 'local', []))->generate([
      'mode' => 'restaurant',
      'menu_item' => 'Pad Thai',
      'ingredients' => [],
      'appliances' => ['Stovetop'],
    ]);

    $lines = array_column($data['ingredients'], 'original_text');
    self::assertCount(1, preg_grep('/garlic/i', $lines));
    self::assertCount(1, preg_grep('/ginger/i', $lines));
    self::assertStringNotContainsString(',,', json_encode($data['instructions'], JSON_THROW_ON_ERROR));
    self::assertSame([], $this->validator()->validate((new RecipeContractFactory())->fromArray($data, [])));
  }

  /**
   * Builds the production validation rules.
   */
  private function validator(): RecipeValidator {
    return new RecipeValidator([
      new RequiredSectionsRule(),
      new VeganRule(),
      new SavoryLimitsRule(),
      new FlavorStructureRule(),
      new SeasoningDensityRule(),
      new TechnicalSafetyRule(),
      new InstructionClarityRule(),
    ]);
  }

}
