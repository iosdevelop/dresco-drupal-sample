<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards recipe node deletion when a public Pages publication exists.
 */
final class RecipeDeletionGuard {

  public function __construct(
    private readonly PagesPublisherInterface $publisher,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks whether a recipe node can be deleted.
   */
  public function predelete(NodeInterface $entity): void {
    if ($entity->bundle() !== 'recipe') {
      return;
    }

    if ($this->publisher->requiresArchiveBeforeDelete((int) $entity->id())) {
      if ($this->currentUser->hasPermission('bypass fresco pages archive delete guard')) {
        $this->logger->warning('User {uid} bypassed the archive-before-delete guard for recipe {recipe}: {title}', [
          'uid' => $this->currentUser->id(),
          'recipe' => $entity->id(),
          'title' => $entity->label(),
        ]);
        return;
      }

      throw new EntityStorageException(sprintf(
        'Recipe "%s" has a public Dresco Pages publication. Archive it from Dresco publications before deleting the Drupal node.',
        $entity->label(),
      ));
    }
  }

}
