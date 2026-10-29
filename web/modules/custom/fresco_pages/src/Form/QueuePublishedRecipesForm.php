<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\fresco_pages\Service\PublicationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms seeding publication records for all published recipe nodes.
 */
final class QueuePublishedRecipesForm extends ConfirmFormBase {

  public function __construct(
    protected PublicationManager $publicationManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('fresco_pages.publisher'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_pages_queue_published_recipes';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Queue all currently published recipes?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This creates missing publication records for the latest published recipe revisions, then lets the queue build their static artifacts. Existing matching records are reused, so the operation is safe to run after migrations or imports.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('fresco_pages.publications');
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
    $summary = $this->publicationManager->queueAllPublishedRecipes();
    $this->messenger()->addStatus($this->t('Inspected @inspected published recipes; @queued newly queued, @current already current, @skipped skipped.', [
      '@inspected' => $summary['inspected'],
      '@queued' => $summary['queued'],
      '@current' => $summary['current'],
      '@skipped' => $summary['skipped'],
    ]));
    foreach ($summary['errors'] as $error) {
      $this->messenger()->addError($error);
    }
    $form_state->setRedirect('fresco_pages.publications');
  }

}
