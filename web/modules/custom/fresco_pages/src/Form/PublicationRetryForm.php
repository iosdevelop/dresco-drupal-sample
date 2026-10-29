<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\fresco_pages\Service\PublicationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms requeuing a publication record.
 */
final class PublicationRetryForm extends ConfirmFormBase {

  /**
   * The publication being requeued.
   */
  protected ?Publication $publication = NULL;

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
    return 'fresco_pages_publication_retry';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Queue %label again?', [
      '%label' => $this->publication?->label() ?? '',
    ]);
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
   * @param \Drupal\fresco_pages\Entity\Publication|null $fresco_publication
   *   Publication record.
   *
   * @return array<string, mixed>
   *   Confirmation form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Publication $fresco_publication = NULL): array {
    if ($fresco_publication === NULL) {
      throw new \InvalidArgumentException('A publication record is required.');
    }
    $this->publication = $fresco_publication;
    return parent::buildForm($form, $form_state);
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
    if ($this->publication === NULL) {
      throw new \RuntimeException('Publication record was not loaded.');
    }
    $this->publicationManager->retry((int) $this->publication->id());
    $this->messenger()->addStatus($this->t('Publication queued again.'));
    $form_state->setRedirect('fresco_pages.publications');
  }

}
