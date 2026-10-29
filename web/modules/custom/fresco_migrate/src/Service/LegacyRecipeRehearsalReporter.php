<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds read-only cutover rehearsal reports for legacy migrations.
 */
final class LegacyRecipeRehearsalReporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LegacyRecipeParityReporter $parityReporter,
  ) {}

  /**
   * Reports whether a legacy migration is ready for cutover.
   *
   * @return array<string, mixed>
   *   JSON-serializable rehearsal report.
   */
  public function report(string $path, int $sampleLimit = 10): array {
    $parity = $this->parityReporter->report($path, $sampleLimit);
    if (($parity['source_readable'] ?? FALSE) !== TRUE) {
      return [
        'source' => $path,
        'source_readable' => FALSE,
        'parity' => $parity,
        'image_coverage' => [
          'legacy_with_images' => 0,
          'imported_with_images' => 0,
          'missing_media_count' => 0,
          'missing_media_sample' => [],
        ],
        'ready_for_cutover' => FALSE,
      ];
    }

    $sourceImageIds = [];
    $database = new \PDO('sqlite:' . $path, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    foreach ($database->query("SELECT id, title, image_url FROM recipes WHERE COALESCE(image_url, '') <> '' ORDER BY id")->fetchAll() as $recipe) {
      $sourceImageIds[(int) $recipe['id']] = [
        'legacy_id' => (int) $recipe['id'],
        'title' => (string) $recipe['title'],
        'image_url' => (string) $recipe['image_url'],
      ];
    }

    $importedImageIds = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'recipe')
      ->exists('field_legacy_id')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      $legacyId = (int) $node->get('field_legacy_id')->value;
      if ($legacyId < 1) {
        continue;
      }
      if ($node->hasField('field_hero_image') && !$node->get('field_hero_image')->isEmpty()) {
        $importedImageIds[$legacyId] = TRUE;
      }
    }

    $missingMedia = [];
    foreach ($sourceImageIds as $legacyId => $source) {
      if (!isset($importedImageIds[$legacyId])) {
        $missingMedia[] = [
          'legacy_id' => $legacyId,
          'title' => $source['title'],
        ];
      }
    }

    $imageCoverage = [
      'legacy_with_images' => count($sourceImageIds),
      'imported_with_images' => count($importedImageIds),
      'missing_media_count' => count($missingMedia),
      'missing_media_sample' => array_slice($missingMedia, 0, $sampleLimit),
    ];

    return [
      'source' => $path,
      'source_readable' => TRUE,
      'parity' => $parity,
      'image_coverage' => $imageCoverage,
      'ready_for_cutover' => ($parity['parity'] ?? FALSE) === TRUE && $imageCoverage['missing_media_count'] === 0,
    ];
  }

}
