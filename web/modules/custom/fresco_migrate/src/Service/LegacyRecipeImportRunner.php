<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Service;

use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;

/**
 * Runs the repeatable legacy recipe import.
 */
final class LegacyRecipeImportRunner {

  public function __construct(
    private readonly MigrationPluginManagerInterface $migrationManager,
  ) {}

  /**
   * Imports the legacy recipe source with visible counts.
   *
   * @return array<string, int|string>
   *   Import summary.
   */
  public function run(): array {
    $migration = $this->migration();
    $result = (new MigrateExecutable($migration, new MigrateMessage()))->import();
    return [
      'result' => $result,
      'source_count' => $migration->getSourcePlugin()->count(),
      'imported_count' => $migration->getIdMap()->importedCount(),
    ];
  }

  /**
   * Returns the current source and imported counts without importing.
   *
   * @return array<string, int>
   *   Read-only migration snapshot.
   */
  public function snapshot(): array {
    $migration = $this->migration();
    return [
      'source_count' => $migration->getSourcePlugin()->count(),
      'imported_count' => $migration->getIdMap()->importedCount(),
    ];
  }

  /**
   * Loads the Fresco recipe migration plugin.
   */
  private function migration(): MigrationInterface {
    $migration = $this->migrationManager->createInstance('fresco_legacy_recipes');
    if (!$migration instanceof MigrationInterface) {
      throw new \RuntimeException('The Fresco legacy recipe migration is unavailable.');
    }
    return $migration;
  }

}
