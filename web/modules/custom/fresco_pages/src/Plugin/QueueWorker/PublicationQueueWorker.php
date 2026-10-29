<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fresco_pages\Service\PublicationProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Publishes queued immutable recipe artifacts.
 */
#[QueueWorker(
  id: 'fresco_pages_publish',
  title: new TranslatableMarkup('Dresco Pages publications'),
  cron: ['time' => 30],
)]
final class PublicationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Creates a publication queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $pluginId
   *   Plugin ID.
   * @param mixed $pluginDefinition
   *   Plugin definition.
   * @param \Drupal\fresco_pages\Service\PublicationProcessor $processor
   *   Publication processor.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    private readonly PublicationProcessor $processor,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Service container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('fresco_pages.processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_numeric($data['publication_id'] ?? NULL)) {
      throw new \InvalidArgumentException('Publication queue data is invalid.');
    }
    $this->processor->process(
      (int) $data['publication_id'],
      ($data['full_site'] ?? FALSE) === TRUE,
    );
  }

}
