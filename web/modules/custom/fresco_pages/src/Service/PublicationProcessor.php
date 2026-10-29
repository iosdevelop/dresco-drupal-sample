<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\fresco_pages\Value\StaticArtifact;
use Psr\Log\LoggerInterface;

/**
 * Processes publication records with bounded retry and visible failure state.
 */
final class PublicationProcessor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly PublicationTransport $transport,
    private readonly SiteArtifactAssembler $siteAssembler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes one durable publication record.
   */
  public function process(int $publicationId, bool $fullSite = FALSE): void {
    $lockName = 'fresco_pages:process:' . $publicationId;
    if (!$this->lock->acquire($lockName, 120.0)) {
      throw new RequeueException('Publication is already processing.');
    }
    try {
      $publication = $this->entityTypeManager->getStorage('fresco_publication')->load($publicationId);
      if (!$publication instanceof Publication || in_array($publication->get('state')->value, ['published', 'archived'], TRUE)) {
        return;
      }
      $correlationId = (string) $publication->get('correlation_id')->value;
      $attempts = (int) $publication->get('attempts')->value + 1;
      $publication->set('attempts', $attempts);
      $publication->set('state', 'processing');
      $publication->set('error', NULL);
      $publication->save();
      $this->logger->info('Dresco publication {id} started with correlation {correlation} on attempt {attempt}.', [
        'id' => $publicationId,
        'correlation' => $correlationId,
        'attempt' => $attempts,
      ]);
      try {
        $artifact = StaticArtifact::fromJson((string) $publication->get('artifact')->value);
        if (!hash_equals((string) $publication->get('content_hash')->value, $artifact->contentHash)) {
          throw new \UnexpectedValueException('Publication artifact hash does not match its record.');
        }
        $siteArtifact = $this->siteAssembler->assemble($publication, $fullSite);
        $transportId = $this->transport->publish(
          $siteArtifact,
          'Publish ' . $publication->label() . ' [' . $correlationId . ']',
        );
        $publication->set('commit_sha', $transportId);
        $operation = (string) ($publication->get('operation')->value ?: 'publish');
        $publication->set('state', $operation === 'archive' ? 'archived' : ($this->transport->isLive() ? 'published' : 'ready'));
        $publication->save();
        $this->logger->info('Dresco publication {id} completed with correlation {correlation} and transport {transport}.', [
          'id' => $publicationId,
          'correlation' => $correlationId,
          'transport' => $transportId,
        ]);
      }
      catch (\Throwable $exception) {
        $publication->set('state', 'failed');
        $publication->set('error', mb_strimwidth($exception->getMessage(), 0, 4000, '…'));
        $publication->save();
        $this->logger->error('Dresco publication {id} failed with correlation {correlation} on attempt {attempt}: {message}', [
          'id' => $publicationId,
          'correlation' => $correlationId,
          'attempt' => $attempts,
          'message' => $exception->getMessage(),
        ]);
        if ($attempts < 3) {
          throw new RequeueException('Publication will be retried.');
        }
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

}
