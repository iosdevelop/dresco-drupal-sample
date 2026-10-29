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
 * Confirms an explicit full-site reconciliation repair.
 */
final class SiteRepairForm extends ConfirmFormBase {

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
    return 'fresco_pages_site_repair';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Queue a complete Dresco Pages repair?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The queue will rebuild every current recipe artifact, aggregate index, feed, manifest, and historical redirects. Preview mode remains local; GitHub mode creates one atomic repair commit.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('fresco_pages.reconciliation');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->publicationManager->repair();
    $this->messenger()->addStatus($this->t('Full-site repair queued.'));
    $form_state->setRedirect('fresco_pages.publications');
  }

}
