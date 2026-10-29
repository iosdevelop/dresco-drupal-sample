<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\fresco_ai\Eval\RecipeQualityChecker;
use Drupal\fresco_ai\Fallback\DishContext;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\fresco_ai\Service\SweetenerLimiter;
use Drupal\fresco_ai\Service\VeganSubstituter;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fallback dish templates against the evaluation cases.
 */
#[Group('fresco_ai')]
final class FallbackDishComposerTest extends UnitTestCase {

  /**
   * Provides every ingredient-mode evaluation case.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   Generator requests keyed by case name.
   */
  public static function evalCases(): array {
    $cases = Yaml::decode((string) file_get_contents(__DIR__ . '/../../../eval/cases.yml'));
    $requests = [];
    foreach ($cases as $name => $case) {
      if (($case['request']['mode'] ?? '') !== 'restaurant') {
        $requests[$name] = [$case['request']];
      }
    }
    return $requests;
  }

  /**
   * Confirms each fallback recipe is valid and its method is coherent.
   *
   * @param array<string, mixed> $request
   *   Generator request.
   */
  #[DataProvider('evalCases')]
  public function testFallbackRecipeIsValidAndCoherent(array $request): void {
    // Mirror the coordinator's request preparation.
    $request['ingredients'] = (new SweetenerLimiter())->limit(
      (new VeganSubstituter())->substitute($request['ingredients'])['ingredients'],
    )['ingredients'];
    $recipe = (new RecipeContractFactory())->fromArray((new LocalProvider([], 'local', []))->generate($request), []);

    $errors = array_filter(
      (new RecipeValidator([
        new RequiredSectionsRule(),
        new VeganRule(),
        new SavoryLimitsRule(),
        new FlavorStructureRule(),
        new SeasoningDensityRule(),
        new TechnicalSafetyRule(),
        new InstructionClarityRule(),
      ]))->validate($recipe),
      static fn ($result): bool => $result->severity === 'error',
    );
    self::assertSame([], array_map(static fn ($result): string => $result->message, $errors));

    $checker = new RecipeQualityChecker();
    self::assertSame([], $checker->unlistedFoods($recipe), 'The method names foods missing from the ingredient list.');
    self::assertSame([], (array) $checker->methodProblems($request, $recipe));
  }

  /**
   * Confirms flavor items are listed once and listed aromatics are reused.
   */
  public function testDishContextAddsFlavorOnlyWhenMissing(): void {
    $profile = [
      'aromatics' => [['1 yellow onion, diced', 'onion'], ['3 cloves garlic, minced', 'garlic']],
      'spices' => [['1 tsp ground cumin', 'cumin']],
      'bright' => ['1 lime, juiced', 'lime juice'],
      'herb' => ['1/4 cup fresh cilantro, chopped', 'cilantro'],
    ];
    $dish = new DishContext(['2 cloves garlic', '1 lemon', '1 can black beans, drained'], $profile, 'Global');

    self::assertSame(['garlic'], $dish->aromatic(2));
    self::assertSame('garlic', $dish->garlic());
    self::assertSame('lemon juice', $dish->bright());
    self::assertSame(['cumin'], $dish->spice(1));
    self::assertSame('cilantro', $dish->herb());
    self::assertSame(
      [
        '2 cloves garlic',
        '1 lemon',
        '1 can black beans, drained',
        '1 tsp ground cumin',
        '1/4 cup fresh cilantro, chopped',
      ],
      $dish->lines(),
    );
    self::assertSame('black beans', DishContext::shortName('1 can black beans, drained'));
    self::assertSame('broccoli', DishContext::shortName('1 broccoli crown'));
  }

}
