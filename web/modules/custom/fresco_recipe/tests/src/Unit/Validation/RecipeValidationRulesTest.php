<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Unit\Validation;

use Drupal\fresco_recipe\Validation\Rule\FlavorStructureRule;
use Drupal\fresco_recipe\Validation\Rule\InstructionClarityRule;
use Drupal\fresco_recipe\Validation\Rule\RequiredSectionsRule;
use Drupal\fresco_recipe\Validation\Rule\SavoryLimitsRule;
use Drupal\fresco_recipe\Validation\Rule\SeasoningDensityRule;
use Drupal\fresco_recipe\Validation\Seasoning;
use Drupal\fresco_recipe\Validation\Rule\TechnicalSafetyRule;
use Drupal\fresco_recipe\Validation\Rule\VeganRule;
use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the foundational recipe validation rules.
 */
#[Group('fresco_recipe')]
final class RecipeValidationRulesTest extends UnitTestCase {

  /**
   * Confirms a balanced vegan recipe passes the foundational rules.
   */
  public function testBalancedRecipePasses(): void {
    $recipe = $this->recipe();
    self::assertSame([], (new RequiredSectionsRule())->validate($recipe));
    self::assertSame([], (new VeganRule())->validate($recipe));
    self::assertSame([], (new SavoryLimitsRule())->validate($recipe));
    self::assertSame([], (new FlavorStructureRule())->validate($recipe));
    self::assertSame([], (new TechnicalSafetyRule())->validate($recipe));
  }

  /**
   * Confirms animal products are rejected and salt is left to taste.
   */
  public function testUnsafeIngredientsFail(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 cup chicken stock'],
      ['original_text' => '1 teaspoon salt'],
      ['original_text' => '2 scallions'],
      ['original_text' => '1 lime'],
    ]);
    self::assertSame('vegan.ingredients', (new VeganRule())->validate($recipe)[0]->ruleId);
    self::assertSame([], (new SavoryLimitsRule())->validate($recipe));
  }

  /**
   * Confirms eggplant is treated as a vegan ingredient.
   */
  public function testEggplantDoesNotTriggerVeganRule(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 cup eggplant'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ]);
    self::assertSame([], (new VeganRule())->validate($recipe));
  }

  /**
   * Confirms pressure liquid, grain ratios, hydration, and safety checks fail.
   */
  public function testTechnicalSafetyFailures(): void {
    $pressureRecipe = $this->recipe(NULL, [
      ['text' => 'Sauté garlic and thyme, then Pressure Cook until tender and finish with lime.'],
    ]);
    self::assertSame('appliance.pressure_liquid', (new TechnicalSafetyRule())->validate($pressureRecipe)[0]->ruleId);

    $grainRecipe = $this->recipe([
      ['original_text' => '2 cups brown rice'],
      ['original_text' => '1 cup water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Add the rice and water, simmer with thyme, and finish with lime.'],
    ]);
    $grainValidation = (new TechnicalSafetyRule())->validate($grainRecipe)[0];
    self::assertSame('grain.liquid_ratio', $grainValidation->ruleId);
    self::assertSame('Dry grains need at least 1 cup of measured cooking liquid per cup of grain.', $grainValidation->message);
    self::assertSame('warning', $grainValidation->severity);

    $doughRecipe = $this->recipe([
      ['original_text' => '2 cups flour'],
      ['original_text' => '1/2 cup water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Add 1 cup water to the inner pot, sauté garlic, knead the dough, bake until browned, and finish with lime.'],
    ]);
    self::assertSame('dough.hydration', (new TechnicalSafetyRule())->validate($doughRecipe)[0]->ruleId);

    $steamRecipe = $this->recipe([
      ['original_text' => '2 cups cauliflower florets'],
      ['original_text' => '1 cup water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Steam the cauliflower with garlic and thyme, then finish with lime.'],
    ]);
    self::assertSame('appliance.steam_doneness', (new TechnicalSafetyRule())->validate($steamRecipe)[0]->ruleId);

    $airFryerRecipe = $this->recipe(NULL, [
      ['text' => 'Air fry the chickpeas with garlic and thyme until crisp, then finish with lime.'],
    ]);
    self::assertSame('appliance.air_fryer_time_temperature', (new TechnicalSafetyRule())->validate($airFryerRecipe)[0]->ruleId);

    $unselectedApplianceRecipe = $this->recipe(NULL, [
      ['text' => 'Air fry the chickpeas with garlic and thyme at 375°F for 12 minutes until crisp, then finish with lime.'],
    ]);
    self::assertSame('appliance.unselected', (new TechnicalSafetyRule())->validate($unselectedApplianceRecipe)[0]->ruleId);
  }

  /**
   * Confirms dry grains pass when the cooking liquid is measured.
   */
  public function testMeasuredGrainRatioPasses(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 cup quinoa'],
      ['original_text' => '1 1/2 cups water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Simmer quinoa in measured water with thyme until tender, then finish with lime.'],
    ]);
    self::assertSame([], (new TechnicalSafetyRule())->validate($recipe));
  }

  /**
   * Confirms dry pasta cannot be treated as a sauté-only ingredient.
   */
  public function testDryPastaRequiresActualCookingMethod(): void {
    $rule = new TechnicalSafetyRule();
    $sauteOnlyRecipe = $this->recipe([
      ['original_text' => '8 ounces spaghetti'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Sauté garlic, thyme, and spaghetti with tomatoes until fragrant, then finish with lime.'],
    ]);
    self::assertContains(
      'pasta.cooking_method',
      array_map(static fn ($result): string => $result->ruleId, $rule->validate($sauteOnlyRecipe)),
    );

    $packageDirectionsRecipe = $this->recipe([
      ['original_text' => '8 ounces spaghetti'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Cook spaghetti according to package directions until al dente. Sauté garlic and thyme, then toss with the pasta and finish with lime.'],
    ]);
    self::assertNotContains(
      'pasta.cooking_method',
      array_map(static fn ($result): string => $result->ruleId, $rule->validate($packageDirectionsRecipe)),
    );

    $pressureCookRecipe = $this->recipe([
      ['original_text' => '8 ounces spaghetti'],
      ['original_text' => '2 cups water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Sauté garlic and thyme, add spaghetti and measured water, then Pressure Cook spaghetti until tender. Finish with lime.'],
    ]);
    self::assertNotContains(
      'pasta.cooking_method',
      array_map(static fn ($result): string => $result->ruleId, $rule->validate($pressureCookRecipe)),
    );

    $cookedPastaRecipe = $this->recipe([
      ['original_text' => '2 cups cooked spaghetti'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Sauté garlic and thyme, fold in cooked spaghetti until hot, then finish with lime.'],
    ]);
    self::assertNotContains(
      'pasta.cooking_method',
      array_map(static fn ($result): string => $result->ruleId, $rule->validate($cookedPastaRecipe)),
    );
  }

  /**
   * Confirms vague starch assemblies are rejected.
   */
  public function testUnmeasuredStarchAssemblyFails(): void {
    $recipe = $this->recipe([
      ['original_text' => 'all-purpose flour'],
      ['original_text' => 'cornmeal'],
      ['original_text' => 'Sweet potato'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Sauté garlic, scallions, thyme, and allspice until fragrant.'],
      ['text' => 'Add all-purpose flour, cornmeal, sweet potato, 1 cup water. Sauté until tender and cohesive.'],
      ['text' => 'Finish with fresh lime juice and herbs; adjust heat to taste.'],
    ]);

    $ruleIds = array_map(
      static fn ($result): string => $result->ruleId,
      (new TechnicalSafetyRule())->validate($recipe),
    );
    self::assertContains('ingredients.measured_staples', $ruleIds);
    self::assertContains('starch.outcome', $ruleIds);
    self::assertContains('root_vegetable.cooking_method', $ruleIds);
    self::assertContains('cornmeal.hydration_method', $ruleIds);
  }

  /**
   * Confirms measured sweet-potato cornmeal cakes pass technical validation.
   */
  public function testMeasuredSweetPotatoCornmealCakesPass(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 cup all-purpose flour'],
      ['original_text' => '1/2 cup cornmeal'],
      ['original_text' => '1 large sweet potato, peeled and diced'],
      ['original_text' => '1 cup water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Pressure Cook diced sweet potato with 1 cup water for 2 minutes until fork-tender, then mash.'],
      ['text' => 'Stir in cornmeal and all-purpose flour gradually to form a soft dough, shape into cakes, and Sauté 3-4 minutes per side until crisp.'],
      ['text' => 'Finish with fresh lime juice and herbs; adjust heat to taste.'],
    ]);

    self::assertSame([], (new TechnicalSafetyRule())->validate($recipe));
  }

  /**
   * Confirms appliance-specific safety checks pass with clear details.
   */
  public function testApplianceSafetyDetailsPass(): void {
    $steamRecipe = $this->recipe([
      ['original_text' => '2 cups cauliflower florets'],
      ['original_text' => '1 cup water'],
      ['original_text' => '2 cloves garlic'],
      ['original_text' => '1 teaspoon thyme'],
      ['original_text' => '1 lime'],
    ], [
      ['text' => 'Steam the cauliflower with garlic and thyme until fork-tender, then finish with lime.'],
    ]);
    self::assertSame([], (new TechnicalSafetyRule())->validate($steamRecipe));

    $airFryerRecipe = $this->recipe(
      ingredients: NULL,
      instructions: [
        ['text' => 'Air fry the chickpeas with garlic and thyme at 375°F for 12 minutes until crisp, then finish with lime.'],
      ],
      applianceSettings: [
        ['appliance' => 'Air Fryer', 'mode' => 'Air fry', 'time' => '12 minutes'],
      ],
    );
    self::assertSame([], (new TechnicalSafetyRule())->validate($airFryerRecipe));

    $selectedButUnusedAirFryerRecipe = $this->recipe(
      ingredients: [
        ['original_text' => '2 cups brown rice'],
        ['original_text' => '2 cups water'],
        ['original_text' => '1 can red kidney beans'],
        ['original_text' => '1/2 cup coconut milk'],
        ['original_text' => '2 scallions'],
        ['original_text' => '1 teaspoon thyme'],
        ['original_text' => '1 lime'],
      ],
      instructions: [
        ['text' => 'Pressure Cook the rice and beans with measured water until tender, then finish with lime.'],
      ],
      applianceSettings: [
        ['appliance' => 'Air Fryer', 'mode' => 'Air fry', 'time' => '12 minutes'],
        ['appliance' => 'Instant Pot', 'mode' => 'Pressure Cook', 'time' => '22 minutes'],
      ],
    );
    self::assertSame([], (new TechnicalSafetyRule())->validate($selectedButUnusedAirFryerRecipe));
  }

  /**
   * Confirms each missing flavor component gets its own actionable result.
   */
  public function testFlavorStructureNamesEachMissingPart(): void {
    $results = (new FlavorStructureRule())->validate($this->recipe(
      [['original_text' => '2 cups chickpeas'], ['original_text' => '1 cup water']],
      [['text' => 'Pressure cook chickpeas with 1 cup water for 10 minutes.']],
      [['appliance' => 'Instant Pot', 'mode' => 'Pressure Cook', 'time' => '10 minutes']],
    ));

    self::assertSame(
      ['flavor.aromatic', 'flavor.seasoning', 'flavor.brightness', 'flavor.technique'],
      array_map(static fn ($result): string => $result->ruleId, $results),
    );
    self::assertStringContainsString('missing brightness', $results[2]->message);
    self::assertStringContainsString('lime or lemon', (string) $results[2]->remediation);
  }

  /**
   * Confirms vague cooking steps without a named food are rejected.
   */
  public function testInstructionClarityFlagsUnnamedFoodAndVagueMethods(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 can chickpeas, drained and rinsed'],
      ['original_text' => '2 sweet onions, chopped'],
      ['original_text' => '1 large cabbage, shredded'],
    ], [
      ['text' => 'Heat the Instant Pot to High Pressure and add 3 cups water. Heat throughly in a separate pan using your favorite technique such as sautéing, roasting, or marinating, then add it to the pot with the chickpeas.'],
      ['text' => 'Place the cabbage on top. Seal and cook for 15 minutes on High Pressure.'],
    ]);

    $ruleIds = array_map(static fn ($result): string => $result->ruleId, (new InstructionClarityRule())->validate($recipe));
    self::assertSame(['instructions.vague_method', 'appliance.pressure_preheat', 'instructions.unnamed_food'], $ruleIds);
  }

  /**
   * Confirms valve-setting steps are rejected for the self-sealing Pro lid.
   */
  public function testInstructionClarityFlagsSealingValve(): void {
    $recipe = $this->recipe([
      ['original_text' => '2 potatoes, diced'],
    ], [
      ['text' => 'Add the potatoes and 1 cup water, lock the lid, and set the valve to Sealing.'],
      ['text' => 'Put the potatoes and 1 cup water into the Instant Pot, lock the lid, and pressure cook the potatoes on High for 8 minutes, then use Natural Release for 10 minutes before pressing the Venting button.'],
    ]);

    $results = (new InstructionClarityRule())->validate($recipe);
    self::assertSame(['appliance.sealing_valve'], array_map(static fn ($result): string => $result->ruleId, $results));
    self::assertStringContainsString('Step 1', $results[0]->message);
  }

  /**
   * Confirms named foods, pot contents, and oven preheats pass.
   */
  public function testInstructionClarityAllowsNamedFood(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 large cabbage, shredded'],
      ['original_text' => '2 potatoes, diced'],
    ], [
      ['text' => 'Heat the oven to 425°F. Roast the potato for 25 minutes until fork-tender.'],
      ['text' => 'Add the cabbage and 1 cup water, lock the lid, and pressure cook the cabbages on High for 3 minutes. Simmer the mixture for 2 minutes.'],
    ]);

    self::assertSame([], (new InstructionClarityRule())->validate($recipe));

    $dumplings = $this->recipe([
      ['original_text' => '2 cups all-purpose flour'],
      ['original_text' => '1 cup water'],
    ], [
      ['text' => 'Shape the flour and water into small rounds. Place the dumplings on the steam rack over 1 cup water, lock the lid, and pressure cook the dumplings on High for 4 minutes.'],
    ]);
    self::assertSame([], (new InstructionClarityRule())->validate($dumplings));
  }

  /**
   * Confirms Instant Pot steps must load the named food before the program.
   */
  public function testInstructionClarityFlagsUnloadedPot(): void {
    $recipe = $this->recipe([
      ['original_text' => '2 cups diced potatoes'],
      ['original_text' => '2 cups cauliflower florets'],
      ['original_text' => '1/2 cup water'],
    ], [
      ['text' => 'Add the main ingredients and 1/2 cup water. Scrape the inner pot clean, then lock the lid.'],
      ['text' => 'Pressure Cook on High for 5 minutes, then allow a 5-minute natural release before pressing the Venting button. The potatoes and cauliflower should be fork-tender.'],
      ['text' => 'Pour 1 cup water into the Instant Pot, lock the lid, and Steam on High for 3 minutes.'],
    ]);

    $results = array_values(array_filter(
      (new InstructionClarityRule())->validate($recipe),
      static fn ($result): bool => $result->ruleId === 'appliance.unloaded_pot',
    ));
    self::assertCount(2, $results);
    self::assertStringContainsString('Step 2', $results[0]->message);
    self::assertStringContainsString('Step 3', $results[1]->message);

    $loaded = $this->recipe([
      ['original_text' => '2 cups diced potatoes'],
      ['original_text' => '2 cups cauliflower florets'],
      ['original_text' => '1 cup water'],
    ], [
      ['text' => 'Put the potatoes, cauliflower, and 1 cup water into the Instant Pot, lock the lid, and Pressure Cook on High for 5 minutes, then Natural Release for 5 minutes until the potatoes are fork-tender.'],
      ['text' => 'Pour 1 cup water into the inner pot, place the cauliflower on the steam rack, lock the lid, and Steam the cauliflower on High for 3 minutes.'],
      ['text' => 'Steam the potatoes in a covered stovetop steamer for 12 minutes until fork-tender.'],
    ]);
    self::assertSame([], (new InstructionClarityRule())->validate($loaded));
  }

  /**
   * Confirms savory sugar is measured against the two-teaspoon total.
   */
  public function testSavorySugarLimitReadsFractions(): void {
    $allowed = $this->recipe([
      ['original_text' => '1 1/2 teaspoons salt'],
      ['original_text' => '1 1/2 teaspoons maple syrup'],
    ]);
    self::assertSame([], (new SavoryLimitsRule())->validate($allowed));

    $tooMuch = $this->recipe([['original_text' => '1 tablespoon sugar']]);
    self::assertSame('nutrition.added_sugar', (new SavoryLimitsRule())->validate($tooMuch)[0]->ruleId);
  }

  /**
   * Confirms a whole cabbage with one teaspoon of cumin is flagged as bland.
   */
  public function testSeasoningDensityFlagsUnderSeasonedCabbage(): void {
    $cabbage = [
      ['original_text' => '1 tablespoon olive oil'],
      ['original_text' => '2 sweet onions, chopped'],
      ['original_text' => '3 cloves garlic, minced'],
      ['original_text' => '1 teaspoon ground cumin'],
      ['original_text' => '1 cup water'],
      ['original_text' => '1 can chickpeas, drained and rinsed'],
      ['original_text' => '1/2 teaspoon salt'],
      ['original_text' => '1 large cabbage, cored and shredded'],
      ['original_text' => '2 tablespoons lemon juice'],
    ];
    $results = (new SeasoningDensityRule())->validate($this->recipe($cabbage));
    self::assertSame(['flavor.seasoning_density'], array_map(static fn ($result): string => $result->ruleId, $results));
    self::assertStringContainsString('3.9 pounds', $results[0]->message);
    self::assertStringContainsString('only 1 teaspoons', $results[0]->message);

    $seasoned = $cabbage;
    $seasoned[3] = ['original_text' => '1 tablespoon ground cumin'];
    $seasoned[] = ['original_text' => '1 teaspoon smoked paprika'];
    self::assertSame([], (new SeasoningDensityRule())->validate($this->recipe($seasoned)));
  }

  /**
   * Confirms small dishes, tortillas, and aromatics are not weighed as bulk.
   */
  public function testSeasoningWeighsOnlyMainFoods(): void {
    self::assertSame([], (new SeasoningDensityRule())->validate($this->recipe()));
    $tacos = ['1 can black beans', '8 corn tortillas', '1 red onion', '3 cloves garlic'];
    self::assertEqualsWithDelta(0.94, Seasoning::pounds($tacos), 0.001);
    $spices = ['1 tablespoon cumin', '1 teaspoon black pepper', '1 teaspoon salt'];
    self::assertEqualsWithDelta(4.0, Seasoning::teaspoons($spices), 0.001);
  }

  /**
   * Confirms under-seasoned spice lines scale up to the per-pound target.
   */
  public function testSeasoningScalesSpicesToWeight(): void {
    $lines = Seasoning::scaleToWeight(['2 lb potatoes', '1/2 tsp smoked paprika', '1/2 tsp black pepper']);
    self::assertSame(['2 lb potatoes', '1 tsp smoked paprika', '1 tsp black pepper'], $lines);

    $enough = ['1 lb potatoes', '1 tsp smoked paprika'];
    self::assertSame($enough, Seasoning::scaleToWeight($enough));
  }

  /**
   * Confirms the 7B model's coconut rice mistakes are caught.
   */
  public function testTechnicalSafetyCatchesNutritionStovetopAndRiceTime(): void {
    $base = $this->recipe([
      ['original_text' => '1 cup brown rice'],
      ['original_text' => '1 cup coconut milk'],
      ['original_text' => '1 cup water'],
    ], [
      ['text' => 'In a medium saucepan, bring the coconut milk and water to a boil. Add the brown rice, cover, and simmer for about 20 minutes until tender.'],
    ]);
    $recipe = new RecipeContract(
      title: $base->title,
      description: $base->description,
      servings: $base->servings,
      prepTimeMinutes: $base->prepTimeMinutes,
      cookTimeMinutes: $base->cookTimeMinutes,
      cuisine: $base->cuisine,
      category: $base->category,
      ingredients: $base->ingredients,
      instructions: $base->instructions,
      applianceSettings: $base->applianceSettings,
      nutrition: ['calories' => 300, 'protein' => 'github.com/example/calculated', 'fiber' => '4g'],
    );

    $ruleIds = array_map(static fn ($result): string => $result->ruleId, (new TechnicalSafetyRule())->validate($recipe));
    self::assertContains('grain.brown_rice_time', $ruleIds);
    self::assertContains('nutrition.format', $ruleIds);
    self::assertContains('appliance.unselected', $ruleIds);
  }

  /**
   * Confirms a staple that is rinsed but never added is caught.
   */
  public function testInstructionClarityFlagsStapleNeverAdded(): void {
    $recipe = $this->recipe([
      ['original_text' => '1 cup brown rice'],
      ['original_text' => '1 cup coconut milk'],
      ['original_text' => '1 cup water'],
    ], [
      ['text' => 'Rinse the brown rice under cold water. Drain well and set aside.'],
      ['text' => 'Pour the coconut milk and water into the inner pot, lock the lid, and pressure cook the coconut milk on High for 22 minutes.'],
      ['text' => 'Fluff the rice with a fork before serving.'],
    ]);

    $ruleIds = array_map(static fn ($result): string => $result->ruleId, (new InstructionClarityRule())->validate($recipe));
    self::assertSame(['instructions.ingredient_never_added'], $ruleIds);

    $squash = $this->recipe([
      ['original_text' => '2 large spaghetti squash, halved'],
    ], [
      ['text' => 'Place the squash halves on a trivet over 1 cup water, lock the lid, and pressure cook the squash on High for 7 minutes.'],
    ]);
    self::assertSame([], (new InstructionClarityRule())->validate($squash));
  }

  /**
   * Creates a complete recipe contract for validation.
   *
   * @param array<int, array<string, mixed>>|null $ingredients
   *   Optional replacement ingredients.
   * @param array<int, array<string, mixed>>|null $instructions
   *   Optional replacement instructions.
   * @param array<int, array<string, mixed>>|null $applianceSettings
   *   Optional replacement appliance settings.
   */
  private function recipe(?array $ingredients = NULL, ?array $instructions = NULL, ?array $applianceSettings = NULL): RecipeContract {
    return new RecipeContract(
      title: 'Chickpea Stew',
      description: 'A savory vegan stew.',
      servings: 4,
      prepTimeMinutes: 15,
      cookTimeMinutes: 30,
      cuisine: 'Caribbean',
      category: 'Vegan',
      ingredients: $ingredients ?? [
        ['original_text' => '2 cups chickpeas'],
        ['original_text' => '2 cloves garlic'],
        ['original_text' => '2 scallions'],
        ['original_text' => '1 teaspoon thyme'],
        ['original_text' => '1 lime'],
      ],
      instructions: $instructions ?? [
        ['text' => 'Sauté garlic and scallions, add 1 cup water, then simmer with thyme until tender. Finish with lime.'],
      ],
      applianceSettings: $applianceSettings ?? [
        ['appliance' => 'Instant Pot', 'mode' => 'Sauté', 'time' => '30 minutes'],
      ],
      nutrition: ['protein' => '12g'],
    );
  }

}
