<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\fresco_ai\Service\AiRateLimiter;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\GenerationCoordinator;
use Drupal\fresco_recipe\Form\RecipeEditorForm;
use Drupal\fresco_recipe\Form\RecipeGeneratorForm;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the recipe generator form state handling.
 */
#[Group('fresco_recipe')]
final class RecipeGeneratorFormTest extends UnitTestCase {

  /**
   * Confirms rebuilt generator forms preserve user-entered values.
   */
  public function testBuildFormPreservesSubmittedValues(): void {
    $form = $this->form();

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturnCallback(static function (string|array $key, mixed $default = NULL): mixed {
      if (is_array($key)) {
        if (count($key) !== 1) {
          return $default;
        }
        $key = reset($key);
      }

      return match ($key) {
        'generator_mode' => 'restaurant',
        'menu_item' => 'Jerk bowl',
        'liked_notes' => 'Smoky and bright',
        'cuisine_vibe' => 'Caribbean',
        'avoid_ingredients' => 'No peanuts',
        'ingredient_policy' => 'use_only',
        'ingredients' => "rice\nbeans",
        'selected_staples' => ['black beans', 'rice'],
        'appliances' => ['Stovetop', 'Instant Pot Pro WiFi (6 qt)'],
        default => $default,
      };
    });
    $formState->method('get')->willReturnCallback(static fn (string $key): mixed => NULL);
    $formState->method('getUserInput')->willReturn([]);

    $build = $form->buildForm([], $formState);

    self::assertSame('restaurant', $build['generator_mode']['#default_value']);
    self::assertSame('Jerk bowl', $build['menu_item']['#default_value']);
    self::assertSame('Smoky and bright', $build['liked_notes']['#default_value']);
    self::assertSame('Caribbean', $build['cuisine_vibe']['#default_value']);
    self::assertSame('No peanuts', $build['avoid_ingredients']['#default_value']);
    self::assertSame('use_only', $build['ingredient_policy']['#default_value']);
    self::assertSame("rice\nbeans", $build['recipe_inputs']['ingredients']['#default_value']);
    self::assertSame(['black beans', 'rice'], $build['recipe_inputs']['pantry_staples']['selected_staples']['#default_value']);
    self::assertSame(['Stovetop', 'Instant Pot Pro WiFi (6 qt)'], $build['appliances']['#default_value']);
  }

  /**
   * Confirms current input wins over stale rebuild storage.
   */
  public function testBuildFormUsesCurrentInputBeforeStoredValues(): void {
    $form = $this->form();

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('get')->willReturnCallback(static function (string $key): mixed {
      return match ($key) {
        'ingredients' => "tofu\nblack beans",
        default => NULL,
      };
    });
    $formState->method('getValue')->willReturnCallback(static fn (string|array $key, mixed $default = NULL): mixed => $default);
    $formState->method('getUserInput')->willReturn([
      'recipe_inputs' => [
        'ingredients' => "tofu\nfresh ginger",
      ],
    ]);

    $build = $form->buildForm([], $formState);

    self::assertSame("tofu\nfresh ginger", $build['recipe_inputs']['ingredients']['#default_value']);
  }

  /**
   * Confirms regeneration is not blocked by a blank manual revision note.
   */
  public function testEditorRegenerateButtonDoesNotValidateRevisionLog(): void {
    $form = new RecipeEditorForm(
      $this->createMock(EntityTypeManagerInterface::class),
      new RecipeNodeMapper(new RecipeContractFactory()),
      new RecipeValidator([]),
      $this->generationCoordinator(),
      $this->createMock(AccountProxyInterface::class),
    );
    $form->setStringTranslation(new IdentityTranslation());
    $formState = $this->mutableFormState();

    $build = $form->buildForm([], $formState, $this->recipeNode());

    self::assertArrayHasKey('#limit_validation_errors', $build['actions']['regenerate']);
    self::assertNotContains(['revision_log'], $build['actions']['regenerate']['#limit_validation_errors']);
    self::assertContains(['title'], $build['actions']['regenerate']['#limit_validation_errors']);
  }

  /**
   * Confirms pantry staples merge when AJAX input is nested by wrappers.
   */
  public function testAddSelectedStaplesUsesNestedAjaxInput(): void {
    $form = $this->form();
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addStatus')->willReturnSelf();
    $form->setMessenger($messenger);

    $capturedInput = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('get')->willReturnCallback(static fn (string $key): mixed => NULL);
    $formState->method('getValue')->willReturnCallback(static fn (string|array $key, mixed $default = NULL): mixed => $default);
    $formState->method('getUserInput')->willReturn([
      'recipe_inputs' => [
        'ingredients' => 'tofu',
        'pantry_staples' => [
          'selected_staples' => [
            'black beans' => 'black beans',
            'brown rice' => 'brown rice',
            'red kidney beans' => '0',
          ],
        ],
      ],
    ]);
    $formState->method('set')->willReturnSelf();
    $formState->method('setValue')->willReturnSelf();
    $formState->method('setRebuild')->willReturnSelf();
    $formState->method('setUserInput')->willReturnCallback(
      static function (array $input) use (&$capturedInput, $formState): FormStateInterface {
        $capturedInput = $input;
        return $formState;
      },
    );

    $build = [];
    $form->addSelectedStaples($build, $formState);

    self::assertSame("tofu\nblack beans\nbrown rice", $capturedInput['ingredients']);
    self::assertSame(
      "tofu\nblack beans\nbrown rice",
      $capturedInput['recipe_inputs']['ingredients'],
    );
    self::assertSame([], $capturedInput['selected_staples']);
    self::assertSame([], $capturedInput['recipe_inputs']['pantry_staples']['selected_staples']);
    self::assertSame([], $capturedInput['pantry_staples']['selected_staples']);
  }

  /**
   * Confirms pantry staples merge when AJAX input is nested by fieldset only.
   */
  public function testAddSelectedStaplesUsesFieldsetNestedAjaxInput(): void {
    $form = $this->form();
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addStatus')->willReturnSelf();
    $form->setMessenger($messenger);

    $capturedInput = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('get')->willReturnCallback(static fn (string $key): mixed => NULL);
    $formState->method('getValue')->willReturnCallback(static fn (string|array $key, mixed $default = NULL): mixed => $default);
    $formState->method('getUserInput')->willReturn([
      'ingredients' => 'tofu',
      'pantry_staples' => [
        'selected_staples' => [
          'black beans' => 'black beans',
          'brown rice' => 'brown rice',
          'red kidney beans' => '0',
        ],
      ],
    ]);
    $formState->method('set')->willReturnSelf();
    $formState->method('setValue')->willReturnSelf();
    $formState->method('setRebuild')->willReturnSelf();
    $formState->method('setUserInput')->willReturnCallback(
      static function (array $input) use (&$capturedInput, $formState): FormStateInterface {
        $capturedInput = $input;
        return $formState;
      },
    );

    $build = [];
    $form->addSelectedStaples($build, $formState);

    self::assertSame("tofu\nblack beans\nbrown rice", $capturedInput['ingredients']);
    self::assertSame([], $capturedInput['pantry_staples']['selected_staples']);
  }

  /**
   * Confirms generation feedback renders as an inline error list.
   */
  public function testBuildFormShowsGenerationFeedback(): void {
    $form = $this->form();

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('get')->willReturnCallback(static function (string $key): mixed {
      return match ($key) {
        'generation_feedback' => [
          'Recipe ingredients must remain vegan. Remove the animal product or identify an explicit vegan substitute.',
        ],
        default => NULL,
      };
    });
    $formState->method('getValue')->willReturnCallback(static fn (string|array $key, mixed $default = NULL): mixed => $default);
    $formState->method('getUserInput')->willReturn([]);

    $build = $form->buildForm([], $formState);

    self::assertSame('Recipe generation could not complete', (string) $build['generation_feedback']['#title']);
    self::assertSame([
      'Recipe ingredients must remain vegan. Remove the animal product or identify an explicit vegan substitute.',
    ], $build['generation_feedback']['#items']);
  }

  /**
   * Confirms a failed batch request is restored once with its feedback.
   */
  public function testBuildFormRestoresFailedRequest(): void {
    $form = $this->form([
      'values' => ['ingredients' => "1 cup quinoa\n2 cucumbers", 'appliances' => ['No-cook (prep only)']],
      'feedback' => ['Dry grains need cooking liquid.'],
    ]);
    $formState = (new FormState())->setUserInput([]);

    $build = $form->buildForm([], $formState);

    self::assertSame("1 cup quinoa\n2 cucumbers", $build['recipe_inputs']['ingredients']['#default_value']);
    self::assertSame(['No-cook (prep only)'], $build['appliances']['#default_value']);
    self::assertSame(['Dry grains need cooking liquid.'], $build['generation_feedback']['#items']);
  }

  /**
   * Builds a private tempstore factory holding an optional failed request.
   *
   * @param array<string, mixed>|null $failedRequest
   *   The stored failed request, or NULL for none.
   */
  private function tempStoreFactory(?array $failedRequest): PrivateTempStoreFactory {
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturn($failedRequest);
    $store->expects($failedRequest === NULL ? self::never() : self::once())->method('delete')->with('failed');
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);
    return $factory;
  }

  /**
   * Builds the recipe generator form under test.
   *
   * @param array<string, mixed>|null $failedRequest
   *   A failed request waiting in the private tempstore, if any.
   */
  private function form(?array $failedRequest = NULL): RecipeGeneratorForm {
    $form = new RecipeGeneratorForm($this->tempStoreFactory($failedRequest));
    $form->setStringTranslation(new IdentityTranslation());
    $requestStack = new RequestStack();
    $requestStack->push(new Request());
    $form->setRequestStack($requestStack);
    return $form;
  }

  /**
   * Builds a minimal generation coordinator.
   */
  private function generationCoordinator(?RecipeValidator $recipeValidator = NULL): GenerationCoordinator {
    $recipeValidator ??= new RecipeValidator([]);
    $manager = $this->createMock(DefaultPluginManager::class);
    $manager->method('createInstance')->willReturn(new LocalProvider([], 'local', []));

    return new GenerationCoordinator(
      $manager,
      $this->configFactory(['provider' => 'local']),
      new RecipeContractFactory(),
      $recipeValidator,
      new AiRateLimiter($this->state([]), $this->configFactory(['request_rate_limit_per_hour' => 20])),
      new AiUsageLedger($this->state([]), $this->configFactory(['audit_retention_days' => 30])),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a config factory mock for the provided key/value pairs.
   *
   * @param array<string, mixed> $values
   *   Configuration values.
   */
  private function configFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * Builds a small in-memory state store.
   *
   * @param array<string, mixed> $values
   *   Initial state values.
   */
  private function state(array $values): StateInterface {
    return new TestState($values);
  }

  /**
   * Builds a mutable form state mock for render-array inspection.
   */
  private function mutableFormState(): FormStateInterface {
    /** @var array<string, mixed> $values */
    $values = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('get')->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);
    $formState->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use (&$values, $formState): FormStateInterface {
        $values[$key] = $value;
        return $formState;
      },
    );
    $formState->method('getValue')->willReturnCallback(static fn (string|array $key, mixed $default = NULL): mixed => $default);
    $formState->method('getUserInput')->willReturn([]);
    return $formState;
  }

  /**
   * Builds a recipe node stub for editor form rendering.
   */
  private function recipeNode(): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('bundle')->willReturn('recipe');
    $node->method('label')->willReturn('Coconut Callaloo');
    $node->method('get')->willReturnCallback(static function (string $field): object {
      return match ($field) {
        'field_nutrition' => new TestFieldList([['value' => '{"calories":410}']]),
        'field_recipe_description' => new TestFieldList([['value' => 'Savory island greens']]),
        'field_servings' => new TestFieldList([['value' => '4']]),
        'field_prep_minutes' => new TestFieldList([['value' => '15']]),
        'field_cook_minutes' => new TestFieldList([['value' => '30']]),
        'field_cuisine' => new TestFieldList([['value' => 'Caribbean']]),
        'field_category' => new TestFieldList([['value' => 'Vegan']]),
        'field_ingredients' => new TestFieldList([
          ['value' => '1 bunch callaloo'],
          ['value' => '1 cup coconut milk'],
        ]),
        'field_instructions' => new TestFieldList([
          ['value' => 'Sauté aromatics.'],
          ['value' => 'Simmer until tender.'],
        ]),
        'field_appliance_settings' => new TestFieldList([
          ['value' => 'Instant Pot | Sauté | 15 minutes'],
        ]),
        default => throw new \InvalidArgumentException('Unexpected field ' . $field),
      };
    });
    return $node;
  }

}

/**
 * Small in-memory state store for this unit test.
 */
final class TestState implements StateInterface {

  /**
   * Creates the in-memory state store.
   *
   * @param array<string, mixed> $values
   *   Initial values.
   */
  public function __construct(private array $values = []) {}

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->values[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, string> $keys
   *   State keys.
   *
   * @return array<string, mixed>
   *   State values keyed by state name.
   */
  public function getMultiple(array $keys): array {
    $result = [];
    foreach ($keys as $key) {
      $result[$key] = $this->get((string) $key);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): void {
    $this->values[$key] = $value;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $data
   *   State values keyed by state name.
   */
  public function setMultiple(array $data): void {
    foreach ($data as $key => $value) {
      $this->set((string) $key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): void {
    unset($this->values[$key]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, string> $keys
   *   State keys.
   */
  public function deleteMultiple(array $keys): void {
    foreach ($keys as $key) {
      $this->delete((string) $key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {}

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    if (!array_key_exists($key, $this->values)) {
      return NULL;
    }

    return [
      'value' => $this->values[$key],
      'original' => NULL,
    ];
  }

}

/**
 * Identity string translation stub.
 */
final class IdentityTranslation implements TranslationInterface {

  /**
   * {@inheritdoc}
   *
   * @param string $string
   *   String to translate.
   * @param array<string, mixed> $args
   *   Placeholder arguments.
   * @param array<string, mixed> $options
   *   Translation options.
   */
  public function translate($string, array $args = [], array $options = []): TranslatableMarkup {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return new TranslatableMarkup($string, $args, $options, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function translateString(TranslatableMarkup $translated_string): string {
    return (string) $translated_string->getUntranslatedString();
  }

  /**
   * {@inheritdoc}
   *
   * @param int $count
   *   Item count.
   * @param string $singular
   *   Singular text.
   * @param string $plural
   *   Plural text.
   * @param array<string, mixed> $args
   *   Placeholder arguments.
   * @param array<string, mixed> $options
   *   Translation options.
   */
  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []): PluralTranslatableMarkup {
    return new PluralTranslatableMarkup($count, $singular, $plural, $args, $options, $this);
  }

}

/**
 * Minimal field list stub with scalar value access.
 */
final class TestFieldList {

  /**
   * First field value.
   */
  public string $value;

  /**
   * Creates the field list stub.
   *
   * @param array<int, array<string, mixed>> $values
   *   Field values.
   */
  public function __construct(private array $values) {
    $this->value = (string) ($values[0]['value'] ?? '');
  }

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
