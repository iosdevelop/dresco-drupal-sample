<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\fresco_migrate\Service\LegacyRecipeImportRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs the repeatable legacy recipe migration with visible counts.
 */
final class LegacyRecipeImportForm extends FormBase {

  public function __construct(
    protected LegacyRecipeImportRunner $importRunner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('fresco_migrate.legacy_recipe_import_runner'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_legacy_recipe_import';
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
   *   Import form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $snapshot = $this->importRunner->snapshot();
    $path = (string) (getenv('FRESCO_LEGACY_DB') ?: '/mnt/fresco-legacy/app.sqlite');
    $form['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Migration status'),
      '#items' => [
        $this->t('Source: @path', ['@path' => $path]),
        $this->t('Source recipes: @count', ['@count' => $snapshot['source_count']]),
        $this->t('Imported recipes: @count', ['@count' => $snapshot['imported_count']]),
      ],
    ];
    $form['warning'] = [
      '#markup' => '<p>' . $this->t('The source database is mounted read-only. Re-running this import updates changed mapped records without duplicating them.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import legacy recipes'),
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
    $summary = $this->importRunner->run();
    if ((int) $summary['result'] !== MigrationInterface::RESULT_COMPLETED) {
      $this->messenger()->addError($this->t('The legacy recipe import did not complete successfully. Review the migration log.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Legacy recipe import completed. @count recipes are mapped.', [
      '@count' => $summary['imported_count'],
    ]));
    $form_state->setRebuild();
  }

}
