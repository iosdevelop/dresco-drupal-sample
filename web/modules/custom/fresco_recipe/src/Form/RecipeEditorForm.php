<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\fresco_ai\Exception\RecipeGenerationException;
use Drupal\fresco_ai\Service\GenerationCoordinator;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides revision-safe editing for Dresco recipe fields.
 */
final class RecipeEditorForm extends FormBase {

  /**
   * Loads recipe nodes for editing.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected ?EntityTypeManagerInterface $frescoEntityTypeManager = NULL;

  /**
   * Maps nodes to and from recipe value objects.
   *
   * @var \Drupal\fresco_recipe\Mapper\RecipeNodeMapper
   */
  protected RecipeNodeMapper $nodeMapper;

  /**
   * Validates recipe revisions before saving.
   *
   * @var \Drupal\fresco_recipe\Validation\RecipeValidator
   */
  protected RecipeValidator $recipeValidator;

  /**
   * Regenerates recipe drafts from edited inputs.
   *
   * @var \Drupal\fresco_ai\Service\GenerationCoordinator
   */
  protected GenerationCoordinator $generationCoordinator;

  /**
   * Tracks the current editor for revision authorship.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUserAccount;

  public function __construct(
    EntityTypeManagerInterface $frescoEntityTypeManager,
    RecipeNodeMapper $nodeMapper,
    RecipeValidator $recipeValidator,
    GenerationCoordinator $generationCoordinator,
    AccountProxyInterface $currentUserAccount,
  ) {
    $this->frescoEntityTypeManager = $frescoEntityTypeManager;
    $this->nodeMapper = $nodeMapper;
    $this->recipeValidator = $recipeValidator;
    $this->generationCoordinator = $generationCoordinator;
    $this->currentUserAccount = $currentUserAccount;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('fresco_recipe.node_mapper'),
      $container->get('fresco_recipe.validator'),
      $container->get('fresco_ai.generation'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_recipe_editor';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param \Drupal\node\NodeInterface|null $node
   *   Recipe being edited.
   *
   * @return array<string, mixed>
   *   Recipe editor form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('A recipe node is required.');
    }
    $nutrition = json_decode((string) $node->get('field_nutrition')->value, TRUE);
    $nutrition = is_array($nutrition) ? $nutrition : [];
    $form_state->set('recipe_node_id', $node->id());
    if ($form_state->get('ingredients_count') === NULL) {
      $form_state->set('ingredients_count', max(1, count($node->get('field_ingredients')->getValue())));
    }
    if ($form_state->get('instructions_count') === NULL) {
      $form_state->set('instructions_count', max(1, count($node->get('field_instructions')->getValue())));
    }
    if ($form_state->get('appliance_settings_count') === NULL) {
      $form_state->set('appliance_settings_count', max(1, count($node->get('field_appliance_settings')->getValue())));
    }

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $node->label(),
      '#required' => TRUE,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $node->get('field_recipe_description')->value,
      '#required' => TRUE,
      '#rows' => 3,
    ];
    foreach ([
      'servings' => [$this->t('Servings'), 'field_servings'],
      'prep_minutes' => [$this->t('Preparation minutes'), 'field_prep_minutes'],
      'cook_minutes' => [$this->t('Cooking minutes'), 'field_cook_minutes'],
    ] as $name => [$label, $fieldName]) {
      $form[$name] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $node->get($fieldName)->value,
        '#min' => 1,
        '#required' => TRUE,
      ];
    }
    $form['cuisine'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cuisine'),
      '#default_value' => $node->get('field_cuisine')->value,
      '#required' => TRUE,
    ];
    $form['category'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category'),
      '#default_value' => $node->get('field_category')->value,
      '#required' => TRUE,
    ];
    if ($node->hasField('field_family_only')) {
      $form['family_only'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Family only'),
        '#description' => $this->t('Publish under /family/, which only signed-in family members can open. Family recipes stay out of the public index and feed.'),
        '#default_value' => (bool) $node->get('field_family_only')->value,
      ];
    }
    $form['ingredients'] = $this->buildRepeaterSection(
      $form_state,
      'ingredients',
      $this->t('Ingredients'),
      $this->t('Enter one complete ingredient per row.'),
      array_column($node->get('field_ingredients')->getValue(), 'value'),
      $this->t('Ingredient'),
    );
    $form['instructions'] = $this->buildRepeaterSection(
      $form_state,
      'instructions',
      $this->t('Instructions'),
      $this->t('Enter one complete step per row.'),
      array_column($node->get('field_instructions')->getValue(), 'value'),
      $this->t('Step'),
    );
    $form['appliance_settings'] = $this->buildRepeaterSection(
      $form_state,
      'appliance_settings',
      $this->t('Appliance settings'),
      $this->t('Use Appliance | Mode | Time, one setting per row.'),
      array_column($node->get('field_appliance_settings')->getValue(), 'value'),
      $this->t('Appliance setting'),
    );
    $form['nutrition'] = [
      '#type' => 'details',
      '#title' => $this->t('Nutrition estimate'),
      '#open' => TRUE,
    ];
    foreach ([
      'calories' => $this->t('Calories'),
      'protein' => $this->t('Protein'),
      'fiber' => $this->t('Fiber'),
    ] as $key => $label) {
      $form['nutrition'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $nutrition[$key] ?? '',
      ];
    }
    $form['revision_log'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revision note'),
      '#description' => $this->t('Briefly describe what changed.'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save new revision'),
      '#button_type' => 'primary',
    ];
    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate from edits'),
      '#submit' => ['::regenerateRecipe'],
      '#limit_validation_errors' => [
        ['title'],
        ['description'],
        ['servings'],
        ['prep_minutes'],
        ['cook_minutes'],
        ['cuisine'],
        ['category'],
        ['ingredients'],
        ['instructions'],
        ['appliance_settings'],
        ['nutrition'],
      ],
      '#button_type' => 'secondary',
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadRecipe((int) $form_state->get('recipe_node_id'));
    $this->applyEditedValues($node, $form_state);

    $results = $this->recipeValidator->validate($this->nodeMapper->fromNode($node));

    $this->prepareNewRevision($node, trim((string) $form_state->getValue('revision_log')));
    $node->save();
    $this->messenger()->addStatus($this->t('Saved a new revision of %title.', ['%title' => $node->label()]));
    if ($results !== []) {
      $this->messenger()->addWarning($this->t('This revision was saved, but it is not publish-ready yet. Review the validation messages before publishing.'));
      foreach ($results as $result) {
        $this->messenger()->addWarning($result->message . ($result->remediation ? ' ' . $result->remediation : ''));
      }
    }
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Regenerates recipe details from the current edited values.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function regenerateRecipe(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadRecipe((int) $form_state->get('recipe_node_id'));
    $this->applyEditedValues($node, $form_state);
    $request = $this->generationRequest($node, $form_state);

    try {
      $result = $this->generationCoordinator->generate($request);
    }
    catch (RecipeGenerationException $exception) {
      foreach ($exception->getValidationResults() as $validationResult) {
        $this->messenger()->addError($validationResult->message . ($validationResult->remediation ? ' ' . $validationResult->remediation : ''));
      }
      $form_state->setRebuild();
      return;
    }
    catch (\RuntimeException $exception) {
      $this->messenger()->addError($this->t('Recipe regeneration could not complete: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild();
      return;
    }

    $recipe = $result->recipe;
    $node->setTitle($recipe->title);
    $node->set('field_recipe_description', $recipe->description);
    $node->set('field_servings', $recipe->servings);
    $node->set('field_prep_minutes', $recipe->prepTimeMinutes);
    $node->set('field_cook_minutes', $recipe->cookTimeMinutes);
    $node->set('field_cuisine', $recipe->cuisine);
    $node->set('field_category', $recipe->category);
    $node->set('field_ingredients', array_map(
      static fn (array $ingredient): array => [
        'value' => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
      ],
      $recipe->ingredients,
    ));
    $node->set('field_instructions', array_map(
      static fn (array $instruction): array => ['value' => (string) ($instruction['text'] ?? '')],
      $recipe->instructions,
    ));
    $node->set('field_appliance_settings', array_map(
      static fn (array $setting): array => [
        'value' => implode(' | ', array_filter([
          (string) ($setting['appliance'] ?? ''),
          (string) ($setting['mode'] ?? ''),
          (string) ($setting['time'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')),
      ],
      $recipe->applianceSettings,
    ));
    $node->set('field_nutrition', json_encode($recipe->nutrition, JSON_THROW_ON_ERROR));
    $node->set('field_generation_context', json_encode($request + [
      'fallback_reason' => $result->fallbackReason,
      'regenerated_from_revision' => TRUE,
    ], JSON_THROW_ON_ERROR));

    $results = $this->recipeValidator->validate($this->nodeMapper->fromNode($node));
    $this->prepareNewRevision($node, trim((string) $form_state->getValue('revision_log')) ?: 'Regenerated recipe from editor edits.');
    $node->save();
    $this->messenger()->addStatus($this->t('Regenerated %title as a new revision.', ['%title' => $node->label()]));
    if ($results !== []) {
      foreach ($results as $validationResult) {
        $this->messenger()->addWarning($validationResult->message . ($validationResult->remediation ? ' ' . $validationResult->remediation : ''));
      }
    }
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Adds one row to a repeatable section.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function addRepeaterRow(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $key = (string) ($trigger['#fresco_repeater_key'] ?? '');
    if ($key === '') {
      return;
    }
    $form_state->set($key . '_values', $this->repeaterValues($form_state, $key));
    $form_state->set($key . '_count', (int) $form_state->get($key . '_count') + 1);
    $form_state->setRebuild();
  }

  /**
   * Removes one row from a repeatable section.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function removeRepeaterRow(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $key = (string) ($trigger['#fresco_repeater_key'] ?? '');
    if ($key === '') {
      return;
    }
    $count = max(1, (int) $form_state->get($key . '_count') - 1);
    $form_state->set($key . '_count', $count);
    $values = $this->repeaterValues($form_state, $key);
    $form_state->set($key . '_values', array_slice($values, 0, $count));
    $form_state->setValue([$key, 'items'], array_slice($values, 0, $count));
    $form_state->setRebuild();
  }

  /**
   * Loads a recipe node by ID.
   */
  private function loadRecipe(int $nodeId): NodeInterface {
    $node = $this->entityTypeManager()->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'recipe') {
      throw new \RuntimeException('Recipe not found.');
    }
    return $node;
  }

  /**
   * Gets the entity type manager, restoring it defensively if needed.
   *
   * Forms are serialized during rebuilds, so this guards against a stale
   * uninitialized service property after wakeup.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    if ($this->frescoEntityTypeManager === NULL) {
      throw new \RuntimeException('The recipe editor entity type manager is unavailable.');
    }
    return $this->frescoEntityTypeManager;
  }

  /**
   * Applies manually edited form values to the recipe node.
   */
  private function applyEditedValues(NodeInterface $node, FormStateInterface $form_state): void {
    $node->setTitle(trim((string) $form_state->getValue('title')));
    $node->set('field_recipe_description', trim((string) $form_state->getValue('description')));
    $node->set('field_servings', (int) $form_state->getValue('servings'));
    $node->set('field_prep_minutes', (int) $form_state->getValue('prep_minutes'));
    $node->set('field_cook_minutes', (int) $form_state->getValue('cook_minutes'));
    $node->set('field_cuisine', trim((string) $form_state->getValue('cuisine')));
    $node->set('field_category', trim((string) $form_state->getValue('category')));
    if ($node->hasField('field_family_only')) {
      $node->set('field_family_only', (bool) $form_state->getValue('family_only'));
    }
    $node->set('field_ingredients', $this->structuredFieldValues($this->repeaterValues($form_state, 'ingredients')));
    $node->set('field_instructions', $this->structuredFieldValues($this->repeaterValues($form_state, 'instructions')));
    $node->set('field_appliance_settings', $this->structuredFieldValues($this->repeaterValues($form_state, 'appliance_settings')));
    $node->set('field_nutrition', json_encode([
      'calories' => trim((string) $form_state->getValue('calories')),
      'protein' => trim((string) $form_state->getValue('protein')),
      'fiber' => trim((string) $form_state->getValue('fiber')),
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Builds a generation request from the current editor values.
   *
   * @return array<string, mixed>
   *   Generation request.
   */
  private function generationRequest(NodeInterface $node, FormStateInterface $form_state): array {
    return [
      'mode' => 'ingredients',
      'menu_item' => $node->label(),
      'liked_notes' => trim((string) $form_state->getValue('description')),
      'cuisine_vibe' => trim((string) $form_state->getValue('cuisine')),
      'avoid_ingredients' => '',
      'ingredient_policy' => 'suggest_missing',
      'ingredients' => array_values(array_map(
        static fn (mixed $value): string => trim((string) $value),
        $this->repeaterValues($form_state, 'ingredients'),
      )),
      'appliances' => $this->appliancesFromSettings($this->repeaterValues($form_state, 'appliance_settings')),
    ];
  }

  /**
   * Extracts appliance names from editor appliance-setting rows.
   *
   * @param array<int, mixed> $settings
   *   Appliance setting rows.
   *
   * @return array<int, string>
   *   Appliance names.
   */
  private function appliancesFromSettings(array $settings): array {
    $appliances = [];
    foreach ($settings as $setting) {
      $parts = array_map('trim', explode('|', (string) $setting));
      if ($parts[0] !== '') {
        $appliances[] = $parts[0];
      }
    }
    return array_values(array_unique($appliances));
  }

  /**
   * Marks the node as a new revision with the current editor as author.
   */
  private function prepareNewRevision(NodeInterface $node, string $revisionLog): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUserAccount->id());
    $node->setRevisionLogMessage($revisionLog);
  }

  /**
   * Builds a repeatable row section for structured recipe content.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param string $key
   *   Section key.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   Section title.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   Section helper text.
   * @param array<int, string> $defaults
   *   Existing values.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $placeholder
   *   Input placeholder.
   *
   * @return array<string, mixed>
   *   Render array for the section.
   */
  private function buildRepeaterSection(FormStateInterface $form_state, string $key, $title, $description, array $defaults, $placeholder): array {
    $count = max(1, (int) $form_state->get($key . '_count'));
    $form = [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      '#description' => $description,
      '#tree' => TRUE,
    ];
    $values = $this->repeaterValues($form_state, $key, $defaults);
    for ($i = 0; $i < $count; $i++) {
      $form['items'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('@label @number', [
          '@label' => (string) $placeholder,
          '@number' => (string) ($i + 1),
        ]),
        '#default_value' => (string) ($values[$i] ?? $defaults[$i] ?? ''),
        '#maxlength' => 2048,
        '#required' => TRUE,
      ];
    }
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another @label', ['@label' => (string) $placeholder]),
      '#submit' => ['::addRepeaterRow'],
      '#limit_validation_errors' => [],
      '#name' => $key . '_add',
      '#fresco_repeater_key' => $key,
    ];
    $form['actions']['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove last @label', ['@label' => (string) $placeholder]),
      '#submit' => ['::removeRepeaterRow'],
      '#limit_validation_errors' => [],
      '#name' => $key . '_remove',
      '#fresco_repeater_key' => $key,
      '#disabled' => $count <= 1,
    ];
    return $form;
  }

  /**
   * Converts structured repeater values to field values.
   *
   * @param array<int, mixed> $values
   *   Repeater values.
   *
   * @return array<int, array{value: string}>
   *   Field values.
   */
  private function structuredFieldValues(array $values): array {
    $lines = [];
    foreach ($values as $value) {
      if (is_array($value)) {
        $value = $value['value'] ?? reset($value);
      }
      $line = trim((string) $value);
      if ($line !== '') {
        $lines[] = ['value' => $line];
      }
    }
    return $lines;
  }

  /**
   * Returns repeater values from user input or processed form state.
   *
   * Raw user input is preferred so quantities and other exact text survive
   * Drupal rebuilds without being normalized away.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param string $key
   *   Repeater key.
   * @param array<int, string> $defaults
   *   Default values when no input is present.
   *
   * @return array<int, mixed>
   *   Repeater row values.
   */
  private function repeaterValues(FormStateInterface $form_state, string $key, array $defaults = []): array {
    $userInput = $form_state->getUserInput();
    $input = $userInput[$key]['items'] ?? NULL;
    if (is_array($input)) {
      return array_values($input);
    }

    $values = $form_state->getValue([$key, 'items']);
    if (is_array($values)) {
      return array_values($values);
    }

    $stored = $form_state->get($key . '_values');
    if (is_array($stored)) {
      return array_values($stored);
    }

    return $defaults;
  }

}
