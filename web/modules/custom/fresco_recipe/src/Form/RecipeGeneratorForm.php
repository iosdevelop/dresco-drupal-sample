<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\fresco_ai\Batch\RecipeGeneratorBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a recipe draft through a configured generation provider.
 */
final class RecipeGeneratorForm extends FormBase {

  /**
   * Submitted values kept for a generation request, with their defaults.
   */
  private const REQUEST_KEYS = [
    'generator_mode' => 'ingredients',
    'menu_item' => '',
    'liked_notes' => '',
    'cuisine_vibe' => '',
    'avoid_ingredients' => '',
    'ingredient_policy' => 'suggest_missing',
    'ingredients' => '',
    'appliances' => [],
  ];

  /**
   * Constructs the generator form.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   Holds a failed request's values for the next page load.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('tempstore.private'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_recipe_generator';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array<string, mixed>
   *   Recipe generator form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->restoreFailedRequest($form_state);
    $mode = $this->formValue($form_state, 'generator_mode', 'ingredients');
    $menuItem = $this->formValue($form_state, 'menu_item', '');
    $likedNotes = $this->formValue($form_state, 'liked_notes', '');
    $cuisineVibe = $this->formValue($form_state, 'cuisine_vibe', '');
    $avoidIngredients = $this->formValue($form_state, 'avoid_ingredients', '');
    $ingredientPolicy = $this->formValue($form_state, 'ingredient_policy', 'suggest_missing');
    $ingredients = $this->formValue($form_state, 'ingredients', '');
    if ($ingredients === '') {
      $ingredients = (string) $this->getRequest()->query->get('ingredients', '');
    }
    $selectedStaples = array_values(array_filter(
      (array) $this->formValue($form_state, 'selected_staples', []),
      static fn (mixed $value): bool => (string) $value !== '0' && trim((string) $value) !== '',
    ));
    $appliances = array_values(array_filter((array) $this->formValue($form_state, 'appliances', ['Instant Pot Pro WiFi (6 qt)'])));

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Create a vegan recipe from ingredients you have or a restaurant-inspired idea. Generated recipes are saved as drafts for review.') . '</p>',
    ];
    $generationFeedback = $this->formValue($form_state, 'generation_feedback', []);
    if (is_array($generationFeedback) && $generationFeedback !== []) {
      $form['generation_feedback'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Recipe generation could not complete'),
        '#items' => array_values(array_map(static fn (mixed $message): string => (string) $message, $generationFeedback)),
        '#attributes' => ['class' => ['messages', 'messages--error']],
      ];
    }
    $form['photo'] = [
      '#type' => 'link',
      '#title' => $this->t('Identify ingredients from a photo'),
      '#url' => Url::fromRoute('fresco_ai.ingredient_photo'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['generator_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Generator mode'),
      '#options' => [
        'ingredients' => $this->t('Use my ingredients'),
        'restaurant' => $this->t('Restaurant-inspired'),
      ],
      '#default_value' => $mode,
      '#required' => TRUE,
    ];
    $form['menu_item'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Menu item or dish name'),
      '#description' => $this->t('Used as inspiration only; Dresco does not claim an exact or copycat recipe.'),
      '#default_value' => $menuItem,
      '#states' => [
        'visible' => [':input[name="generator_mode"]' => ['value' => 'restaurant']],
        'required' => [':input[name="generator_mode"]' => ['value' => 'restaurant']],
      ],
    ];
    $restaurantFields = [
      'liked_notes' => [
        '#title' => $this->t('What you liked'),
        '#description' => $this->t('Describe desired flavor, texture, and presentation.'),
      ],
      'cuisine_vibe' => [
        '#title' => $this->t('Cuisine or vibe'),
        '#description' => $this->t('For example Caribbean, Ethiopian, Indian, Thai, or vegan café comfort food.'),
      ],
      'avoid_ingredients' => [
        '#title' => $this->t('Avoid ingredients'),
        '#description' => $this->t('List allergies, dislikes, or ingredients to exclude.'),
      ],
    ];
    foreach ($restaurantFields as $name => $element) {
      $form[$name] = $element + [
        '#type' => 'textfield',
        '#default_value' => match ($name) {
          'liked_notes' => $likedNotes,
          'cuisine_vibe' => $cuisineVibe,
          'avoid_ingredients' => $avoidIngredients,
        },
        '#states' => [
          'visible' => [':input[name="generator_mode"]' => ['value' => 'restaurant']],
        ],
      ];
    }
    $form['ingredient_policy'] = [
      '#type' => 'radios',
      '#title' => $this->t('Ingredient handling'),
      '#options' => [
        'suggest_missing' => $this->t('Suggest minimal missing ingredients'),
        'use_only' => $this->t('Use only my ingredients'),
      ],
      '#default_value' => $ingredientPolicy,
      '#states' => [
        'visible' => [':input[name="generator_mode"]' => ['value' => 'restaurant']],
      ],
    ];
    $form['recipe_inputs'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'fresco-recipe-inputs'],
    ];
    $form['recipe_inputs']['ingredients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Available ingredients'),
      '#description' => $this->t('Enter one ingredient per line. Quantities may be included.'),
      '#rows' => 9,
      '#default_value' => $ingredients,
    ];
    $form['recipe_inputs']['pantry_staples'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pantry staples'),
      '#description' => $this->t('Tap the staples you want to use in this recipe, then add them.'),
    ];
    $form['recipe_inputs']['pantry_staples']['selected_staples'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Staples'),
      '#options' => array_combine($this->pantryStaples(), array_map(
        static fn (string $staple): string => ucwords($staple),
        $this->pantryStaples(),
      )),
      '#default_value' => $selectedStaples,
    ];
    $form['recipe_inputs']['pantry_staples']['actions'] = [
      '#type' => 'actions',
    ];
    $form['recipe_inputs']['pantry_staples']['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add selected staples'),
      '#submit' => ['::addSelectedStaples'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::refreshRecipeInputs',
        'wrapper' => 'fresco-recipe-inputs',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating ingredients...'),
        ],
      ],
    ];
    $form['appliances'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Available appliances'),
      '#options' => [
        'Instant Pot Pro WiFi (6 qt)' => $this->t('Instant Pot Pro WiFi (6 qt)'),
        'Emeril Lagasse Air Fryer Oven' => $this->t('Emeril Lagasse Air Fryer Oven'),
        'Standard Kitchen Oven' => $this->t('Standard Kitchen Oven'),
        'Stovetop' => $this->t('Stovetop'),
        'Blender' => $this->t('Blender'),
        'No-cook (prep only)' => $this->t('No-cook (prep only)'),
      ],
      '#default_value' => $appliances,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate draft'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $this->formValue($form_state, 'generator_mode', 'ingredients');
    $ingredients = $this->lines((string) $this->formValue($form_state, 'ingredients', ''));
    if ($mode === 'ingredients' && $ingredients === []) {
      $form_state->setErrorByName('ingredients', $this->t('Enter at least one ingredient.'));
    }
    if ($mode === 'restaurant' && trim((string) $this->formValue($form_state, 'menu_item', '')) === '') {
      $form_state->setErrorByName('menu_item', $this->t('Enter a menu item or dish name.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = [];
    foreach (self::REQUEST_KEYS as $key => $default) {
      $values[$key] = $this->formValue($form_state, $key, $default);
    }
    $values['appliances'] = array_values(array_filter((array) $values['appliances']));

    $request = [
      'mode' => $values['generator_mode'],
      'menu_item' => trim((string) $values['menu_item']),
      'liked_notes' => trim((string) $values['liked_notes']),
      'cuisine_vibe' => trim((string) $values['cuisine_vibe']),
      'avoid_ingredients' => trim((string) $values['avoid_ingredients']),
      'ingredient_policy' => $values['ingredient_policy'],
      'ingredients' => $this->lines((string) $values['ingredients']),
      'appliances' => $values['appliances'],
    ];

    // Generation can take minutes with a local model, so it runs as a batch
    // behind a progress page rather than inside this form submission.
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Generating your recipe'))
      ->setInitMessage($this->t('Drafting your recipe. With a local AI model this can take a few minutes; keep this page open.'))
      ->setProgressMessage($this->t('Drafting your recipe…'))
      ->setErrorMessage($this->t('Recipe generation stopped unexpectedly.'))
      ->addOperation([RecipeGeneratorBatch::class, 'generate'], [$request, $values])
      ->setFinishCallback([RecipeGeneratorBatch::class, 'finished']);
    batch_set($batch->toArray());
  }

  /**
   * Restores the values and feedback of a request whose generation failed.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  private function restoreFailedRequest(FormStateInterface $form_state): void {
    if ($form_state->isRebuilding() || $form_state->getUserInput() !== []) {
      return;
    }
    $store = $this->tempStoreFactory->get(RecipeGeneratorBatch::TEMPSTORE);
    $failed = $store->get('failed');
    if (!is_array($failed)) {
      return;
    }
    $store->delete('failed');
    foreach (array_intersect_key((array) ($failed['values'] ?? []), self::REQUEST_KEYS) as $key => $value) {
      $form_state->set($key, $value);
    }
    $form_state->set('generation_feedback', (array) ($failed['feedback'] ?? []));
  }

  /**
   * Adds selected pantry staples to the ingredients textarea.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function addSelectedStaples(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(
      (array) $this->formValue($form_state, 'selected_staples', []),
      static fn (mixed $value): bool => (string) $value !== '0' && trim((string) $value) !== '',
    ));
    if ($selected === []) {
      $this->messenger()->addStatus($this->t('Select a staple that is not already in the recipe.'));
      $form_state->setRebuild();
      return;
    }

    $ingredients = $this->lines($this->formValue($form_state, 'ingredients', ''));
    $merged = $this->mergeUniqueIngredients($ingredients, array_map('strval', $selected));
    $added = count($merged) - count($ingredients);

    $updatedIngredients = implode("\n", $merged);
    $form_state->set('ingredients', $updatedIngredients);
    $userInput = $form_state->getUserInput();
    $userInput['ingredients'] = $updatedIngredients;
    $userInput['selected_staples'] = [];
    if (!isset($userInput['recipe_inputs']) || !is_array($userInput['recipe_inputs'])) {
      $userInput['recipe_inputs'] = [];
    }
    $userInput['recipe_inputs']['ingredients'] = $updatedIngredients;
    if (!isset($userInput['recipe_inputs']['pantry_staples']) || !is_array($userInput['recipe_inputs']['pantry_staples'])) {
      $userInput['recipe_inputs']['pantry_staples'] = [];
    }
    $userInput['recipe_inputs']['pantry_staples']['selected_staples'] = [];
    if (!isset($userInput['pantry_staples']) || !is_array($userInput['pantry_staples'])) {
      $userInput['pantry_staples'] = [];
    }
    $userInput['pantry_staples']['selected_staples'] = [];
    $form_state->setUserInput($userInput);
    $form_state->setValue('ingredients', $updatedIngredients);
    $form_state->setValue(['recipe_inputs', 'ingredients'], $updatedIngredients);
    $form_state->setValue('selected_staples', []);
    $form_state->setValue(['recipe_inputs', 'pantry_staples', 'selected_staples'], []);
    $form_state->setValue(['pantry_staples', 'selected_staples'], []);
    $this->messenger()->addStatus($this->t('Added @count staple(s) to this recipe.', [
      '@count' => $added,
    ]));
    $form_state->setRebuild();
  }

  /**
   * Refreshes the ingredient/staple input section after adding staples.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array<string, mixed>
   *   The refreshed inputs wrapper.
   */
  public function refreshRecipeInputs(array &$form, FormStateInterface $form_state): array {
    return $form['recipe_inputs'];
  }

  /**
   * Splits trimmed non-empty lines.
   *
   * @return array<int, string>
   *   Input lines.
   */
  private function lines(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
  }

  /**
   * Merges ingredients while preserving order and removing duplicates.
   *
   * @param array<int, string> $ingredients
   *   Existing ingredients.
   * @param array<int, string> $staples
   *   Staples to append.
   *
   * @return array<int, string>
   *   Deduplicated ingredient lines.
   */
  private function mergeUniqueIngredients(array $ingredients, array $staples): array {
    $merged = $ingredients;
    $known = [];
    foreach ($merged as $ingredient) {
      $known[mb_strtolower($ingredient)] = TRUE;
    }
    foreach ($staples as $staple) {
      $staple = trim($staple);
      if ($staple === '') {
        continue;
      }
      $key = mb_strtolower($staple);
      if (!isset($known[$key])) {
        $known[$key] = TRUE;
        $merged[] = $staple;
      }
    }
    return $merged;
  }

  /**
   * Returns the pantry staples shown in the generator.
   *
   * @return array<int, string>
   *   Pantry staples.
   */
  private function pantryStaples(): array {
    return [
      'all-purpose flour',
      'black beans',
      'brown rice',
      'chickpeas',
      'cornmeal',
      'eggplant',
      'potatoes',
      'red bell pepper',
      'red kidney beans',
      'spaghetti',
      'sweet onions',
      'sweet potatoes',
      'tofu',
      'zucchini',
    ];
  }

  /**
   * Returns a form value from current input, rebuild storage, or a default.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param string $key
   *   Value key.
   * @param mixed $default
   *   Default value.
   *
   * @return mixed
   *   Stored or submitted value.
   */
  private function formValue(FormStateInterface $form_state, string $key, mixed $default): mixed {
    $missing = new \stdClass();
    foreach ($this->inputPaths($key) as $path) {
      $value = $form_state->getValue($path, $missing);
      if ($value !== $missing) {
        return $value;
      }
    }

    $exists = FALSE;
    $value = $this->userInputValue($form_state, $key, $exists);
    if ($exists) {
      return $value;
    }

    $stored = $form_state->get($key);
    return $stored ?? $default;
  }

  /**
   * Returns a value from raw user input, including AJAX wrapper nesting.
   */
  private function userInputValue(FormStateInterface $form_state, string $key, bool &$exists): mixed {
    $userInput = $form_state->getUserInput();

    foreach ($this->inputPaths($key) as $path) {
      $value = $this->arrayValue($userInput, $path, $exists);
      if ($exists) {
        return $value;
      }
    }
    $exists = FALSE;
    return NULL;
  }

  /**
   * Returns possible raw input paths for generator fields.
   *
   * @return array<int, array<int, string>>
   *   Candidate value paths.
   */
  private function inputPaths(string $key): array {
    return [
      [$key],
      ['recipe_inputs', $key],
      ['pantry_staples', $key],
      ['recipe_inputs', 'pantry_staples', $key],
    ];
  }

  /**
   * Reads a nested array value.
   *
   * @param array<string, mixed> $source
   *   Source array.
   * @param array<int, string> $path
   *   Nested path.
   * @param bool $exists
   *   TRUE when the path exists.
   *
   * @return mixed
   *   Nested value or NULL.
   */
  private function arrayValue(array $source, array $path, bool &$exists): mixed {
    $value = $source;
    foreach ($path as $part) {
      if (!is_array($value) || !array_key_exists($part, $value)) {
        $exists = FALSE;
        return NULL;
      }
      $value = $value[$part];
    }
    $exists = TRUE;
    return $value;
  }

}
