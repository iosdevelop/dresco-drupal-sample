<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fresco_ai\Service\AiRequestProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued AI request checks.
 */
#[QueueWorker(
  id: 'fresco_ai_requests',
  title: new TranslatableMarkup('Dresco AI requests'),
  cron: ['time' => 15],
)]
final class AiRequestQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $pluginId
   *   Plugin ID.
   * @param mixed $pluginDefinition
   *   Plugin definition.
   * @param \Drupal\fresco_ai\Service\AiRequestProcessor $processor
   *   AI request processor.
   * @param \Psr\Log\LoggerInterface $logger
   *   AI logger channel.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    private readonly AiRequestProcessor $processor,
    private readonly LoggerInterface $logger,
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
      $container->get('fresco_ai.request_processor'),
      $container->get('logger.channel.fresco_ai'),
    );
  }

  /**
   * Processes a queued AI request payload.
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      throw new \InvalidArgumentException('AI queue data is invalid.');
    }
    try {
      $this->processor->process($data);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Queued AI request failed: {message}', [
        'message' => $exception->getMessage(),
      ]);
    }
  }

}
