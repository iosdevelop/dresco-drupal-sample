<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

/**
 * Publishes immutable recipe revisions to the Pages repository.
 */
interface PagesPublisherInterface {

  /**
   * Queues a recipe revision for idempotent publication.
   */
  public function queue(int $nodeId, int $revisionId): string;

  /**
   * Reconciles Drupal publication records with the GitHub branch.
   *
   * @return array<string, mixed>
   *   Reconciliation summary.
   */
  public function reconcile(): array;

  /**
   * Returns whether a recipe must be archived before hard deletion.
   */
  public function requiresArchiveBeforeDelete(int $nodeId): bool;

}
