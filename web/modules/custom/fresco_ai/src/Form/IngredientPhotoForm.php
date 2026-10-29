<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\fresco_ai\Service\IngredientImageAnalyzer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides reviewed ingredient suggestions from a temporary image upload.
 */
final class IngredientPhotoForm extends FormBase {

  public function __construct(
    protected IngredientImageAnalyzer $imageAnalyzer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('fresco_ai.ingredient_image_analyzer'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.fresco_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_ai_ingredient_photo';
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
   *   Photo analysis or candidate review form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $candidates = $form_state->get('ingredient_candidates');
    if (is_array($candidates)) {
      return $this->buildReviewForm($form, $candidates);
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Upload a pantry or ingredient photo. Dresco will suggest only clearly visible items; you will review every suggestion before it reaches the generator.') . '</p>',
    ];
    $form['privacy'] = [
      '#type' => 'item',
      '#title' => $this->t('Privacy note'),
      '#markup' => $this->t('The configured image provider receives the image for analysis. Dresco deletes its temporary upload after the analysis attempt.'),
    ];
    $form['ingredient_photo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Ingredient photo'),
      '#upload_location' => 'temporary://fresco-ingredient-images',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'jpg jpeg png webp'],
        'FileSizeLimit' => ['fileLimit' => 8 * 1024 * 1024],
        'FileIsImage' => [],
      ],
      '#required' => TRUE,
      '#description' => $this->t('JPEG, PNG, or WebP; maximum 8 MB.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['analyze'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze photo'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Builds the explicit candidate review step.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param array<int, array{name: string, quantity: string|null, confidence: string}> $candidates
   *   Normalized ingredient candidates.
   *
   * @return array<string, mixed>
   *   Candidate review form.
   */
  private function buildReviewForm(array $form, array $candidates): array {
    $options = [];
    $selected = [];
    foreach ($candidates as $index => $candidate) {
      $label = $candidate['quantity'] === NULL
        ? $candidate['name']
        : $candidate['quantity'] . ' ' . $candidate['name'];
      $options[(string) $index] = $this->t('@ingredient — @confidence confidence', [
        '@ingredient' => $label,
        '@confidence' => $candidate['confidence'],
      ]);
      if ($candidate['confidence'] === 'high') {
        $selected[] = (string) $index;
      }
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Review the suggestions. Uncheck anything incorrect; low- and medium-confidence items start unchecked.') . '</p>',
    ];
    if ($options === []) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No ingredients were identified confidently enough to suggest.') . '</p>',
      ];
    }
    else {
      $form['selected_ingredients'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Suggested ingredients'),
        '#options' => $options,
        '#default_value' => $selected,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    if ($options !== []) {
      $form['actions']['use'] = [
        '#type' => 'submit',
        '#value' => $this->t('Use selected ingredients'),
        '#button_type' => 'primary',
        '#validate' => ['::validateSelectedIngredients'],
        '#submit' => ['::useSelectedIngredients'],
      ];
    }
    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze another photo'),
      '#submit' => ['::restart'],
      '#limit_validation_errors' => [],
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
    $fileIds = $form_state->getValue('ingredient_photo', []);
    $fileId = is_array($fileIds) ? reset($fileIds) : FALSE;
    $file = $fileId
      ? $this->entityTypeManager->getStorage('file')->load($fileId)
      : NULL;
    if (!$file instanceof FileInterface) {
      $form_state->setErrorByName('ingredient_photo', $this->t('The uploaded image could not be loaded.'));
      return;
    }
    $form_state->set('ingredient_photo_file', $file);
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
    $file = $form_state->get('ingredient_photo_file');
    if (!$file instanceof FileInterface) {
      return;
    }
    $form_state->set('ingredient_photo_file', NULL);

    try {
      $image = file_get_contents($file->getFileUri());
      if ($image === FALSE) {
        throw new \RuntimeException('The temporary image could not be read.');
      }
      $candidates = $this->imageAnalyzer->analyze($image, (string) $file->getMimeType());
      $form_state->set('ingredient_candidates', $candidates);
      $form_state->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Photo review form failed: {message}', [
        'message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('The photo could not be analyzed. Check the image provider settings and try again.'));
      $form_state->setRebuild();
    }
    finally {
      $file->delete();
    }
  }

  /**
   * Requires at least one selected candidate before leaving the review step.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function validateSelectedIngredients(array &$form, FormStateInterface $form_state): void {
    $ingredients = $this->selectedIngredientLines($form_state);
    if ($ingredients === []) {
      $form_state->setErrorByName('selected_ingredients', $this->t('Select at least one ingredient.'));
      return;
    }
    $form_state->set('selected_ingredient_lines', $ingredients);
  }

  /**
   * Sends explicitly selected candidates to the recipe generator.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function useSelectedIngredients(array &$form, FormStateInterface $form_state): void {
    $ingredients = (array) $form_state->get('selected_ingredient_lines');
    $form_state->setRedirect('fresco_recipe.generator', [], [
      'query' => ['ingredients' => implode("\n", $ingredients)],
    ]);
  }

  /**
   * Returns ingredient lines for the checked candidates.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array<int, string>
   *   Ingredient lines with quantities when known.
   */
  private function selectedIngredientLines(FormStateInterface $form_state): array {
    $candidates = $form_state->get('ingredient_candidates');
    $values = $form_state->getValue('selected_ingredients', []);
    // The first option's key is "0", so array_filter() would drop it.
    $selected = Checkboxes::getCheckedCheckboxes(is_array($values) ? $values : []);
    $ingredients = [];
    if (is_array($candidates)) {
      foreach ($selected as $index) {
        $candidate = $candidates[(int) $index] ?? NULL;
        if (!is_array($candidate) || !is_string($candidate['name'] ?? NULL)) {
          continue;
        }
        $quantity = is_string($candidate['quantity'] ?? NULL)
          ? $candidate['quantity'] . ' '
          : '';
        $ingredients[] = $quantity . $candidate['name'];
      }
    }
    return $ingredients;
  }

  /**
   * Resets the workflow to accept another upload.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function restart(array &$form, FormStateInterface $form_state): void {
    $form_state->set('ingredient_candidates', NULL);
    $form_state->setRebuild();
  }

}
