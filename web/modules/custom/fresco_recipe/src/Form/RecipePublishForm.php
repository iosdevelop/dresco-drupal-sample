<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates and publishes a recipe revision.
 */
final class RecipePublishForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $frescoEntityTypeManager,
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
    return 'fresco_recipe_publish';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param \Drupal\node\NodeInterface|null $node
   *   Recipe being published.
   *
   * @return array<string, mixed>
   *   Publish confirmation form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('A recipe node is required.');
    }
    $form_state->set('recipe_node_id', $node->id());
    $results = $this->recipeValidator->validate($this->nodeMapper->fromNode($node));
    $form['summary'] = [
      '#markup' => '<p>' . $this->t('Publish %title after validating its recipe contract.', ['%title' => $node->label()]) . '</p>',
    ];
    if ($results !== []) {
      $form['errors'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Publication is blocked'),
        '#items' => array_map(static fn (ValidationResult $result): string => $result->message, $results),
        '#attributes' => ['class' => ['messages', 'messages--error']],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Publish recipe'),
      '#button_type' => 'primary',
      '#disabled' => $results !== [] || $node->isPublished(),
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
    $node = $this->frescoEntityTypeManager->getStorage('node')->load((int) $form_state->get('recipe_node_id'));
    if (!$node instanceof NodeInterface || $node->bundle() !== 'recipe') {
      throw new \RuntimeException('Recipe not found.');
    }
    if ($this->recipeValidator->validate($this->nodeMapper->fromNode($node)) !== []) {
      $this->messenger()->addError($this->t('The recipe no longer passes validation.'));
      $form_state->setRebuild();
      return;
    }
    $node->setPublished();
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUser()->id());
    $node->setRevisionLogMessage('Validated and published.');
    $node->save();
    $this->messenger()->addStatus($this->t('Published %title.', ['%title' => $node->label()]));
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

}
