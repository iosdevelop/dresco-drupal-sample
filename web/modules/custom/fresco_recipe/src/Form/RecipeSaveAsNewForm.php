<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a brand-new draft from an existing recipe.
 */
final class RecipeSaveAsNewForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RecipeNodeMapper $nodeMapper,
    protected RecipeValidator $recipeValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('fresco_recipe.node_mapper'),
      $container->get('fresco_recipe.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_recipe_save_as_new';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param \Drupal\node\NodeInterface|null $node
   *   Source recipe node.
   *
   * @return array<string, mixed>
   *   Save-as-new form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('A recipe node is required.');
    }

    $form_state->set('source_node_id', $node->id());
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Create a new draft copied from %title.', ['%title' => $node->label()]) . '</p>',
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New title'),
      '#default_value' => $this->t('Copy of %title', ['%title' => $node->label()]),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save as new recipe'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $this->loadRecipe((int) $form_state->get('source_node_id'));
    $recipe = $this->nodeMapper->fromNode($source);
    $values = [
      'type' => 'recipe',
      'title' => trim((string) $form_state->getValue('title')),
      'status' => 0,
      'uid' => (int) $this->currentUser()->id(),
      'field_recipe_description' => $recipe->description,
      'field_servings' => $recipe->servings,
      'field_prep_minutes' => $recipe->prepTimeMinutes,
      'field_cook_minutes' => $recipe->cookTimeMinutes,
      'field_cuisine' => $recipe->cuisine,
      'field_category' => $recipe->category,
      'field_ingredients' => array_map(
        static fn (array $ingredient): array => [
          'value' => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
        ],
        $recipe->ingredients,
      ),
      'field_instructions' => array_map(
        static fn (array $instruction): array => [
          'value' => (string) ($instruction['text'] ?? ''),
        ],
        $recipe->instructions,
      ),
      'field_appliance_settings' => array_map(
        static fn (array $setting): array => [
          'value' => implode(' | ', array_filter([
            (string) ($setting['appliance'] ?? ''),
            (string) ($setting['mode'] ?? ''),
            (string) ($setting['time'] ?? ''),
          ], static fn (string $part): bool => trim($part) !== '')),
        ],
        $recipe->applianceSettings,
      ),
      'field_nutrition' => json_encode($recipe->nutrition, JSON_THROW_ON_ERROR),
    ];

    $draft = $this->entityTypeManager->getStorage('node')->create($values);
    if ($this->recipeValidator->validate($this->nodeMapper->fromNode($draft)) !== []) {
      $this->messenger()->addError($this->t('The copied recipe did not validate.'));
      $form_state->setRebuild();
      return;
    }

    $draft->save();
    $this->messenger()->addStatus($this->t('Created %title as a new draft recipe.', ['%title' => $draft->label()]));
    $form_state->setRedirect('fresco_recipe.edit', ['node' => $draft->id()]);
  }

  /**
   * Loads a recipe node by ID.
   */
  private function loadRecipe(int $nodeId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'recipe') {
      throw new \RuntimeException('Recipe not found.');
    }
    return $node;
  }

}
