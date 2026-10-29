<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds read-only rollback rehearsal reports for migrated recipes.
 */
final class LegacyRecipeRollbackReporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Reports whether migrated recipes still retain rollback provenance.
   *
   * @return array<string, mixed>
   *   JSON-serializable rollback report.
   */
  public function report(int $sampleLimit = 10): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'recipe')
      ->exists('field_legacy_id')
      ->execute();

    $missingProvenance = [];
    $missingPagesUrl = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $legacyId = (int) $node->get('field_legacy_id')->value;
      $generationContext = json_decode((string) $node->get('field_generation_context')->value, TRUE);
      $hasLegacyContext = is_array($generationContext)
        && (int) ($generationContext['legacy_id'] ?? 0) === $legacyId
        && trim((string) ($generationContext['legacy_slug'] ?? '')) !== '';
      $wasPublishedLegacyRecipe = $hasLegacyContext
        && trim((string) ($generationContext['published_at'] ?? '')) !== '';
      if (!$hasLegacyContext) {
        $missingProvenance[] = [
          'legacy_id' => $legacyId,
          'node_id' => (int) $node->id(),
          'title' => $node->label(),
        ];
      }
      if ($wasPublishedLegacyRecipe && trim((string) $node->get('field_pages_url')->value) === '') {
        $missingPagesUrl[] = [
          'legacy_id' => $legacyId,
          'node_id' => (int) $node->id(),
          'title' => $node->label(),
        ];
      }
    }

    $issues = $missingProvenance !== [] || $missingPagesUrl !== [];
    return [
      'imported_count' => count($ids),
      'missing_provenance_count' => count($missingProvenance),
      'missing_provenance_sample' => array_slice($missingProvenance, 0, $sampleLimit),
      'missing_pages_url_count' => count($missingPagesUrl),
      'missing_pages_url_sample' => array_slice($missingPagesUrl, 0, $sampleLimit),
      'ready_for_rollback' => !$issues,
    ];
  }

}
