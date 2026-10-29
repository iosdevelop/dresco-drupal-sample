<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Drush\Commands;

use Drupal\fresco_migrate\Service\LegacyRecipeParityReporter;
use Drupal\fresco_migrate\Service\LegacyRecipeImageMigrator;
use Drupal\fresco_migrate\Service\LegacyRecipeImportRunner;
use Drupal\fresco_migrate\Service\LegacyRecipeRehearsalReporter;
use Drupal\fresco_migrate\Service\LegacyRecipeRollbackReporter;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;

/**
 * Drush operations for Fresco legacy migrations.
 */
final class FrescoMigrateCommands extends DrushCommands {

  public function __construct(
    private readonly LegacyRecipeParityReporter $parityReporter,
    private readonly LegacyRecipeImageMigrator $imageMigrator,
    private readonly LegacyRecipeImportRunner $importRunner,
    private readonly LegacyRecipeRehearsalReporter $rehearsalReporter,
    private readonly LegacyRecipeRollbackReporter $rollbackReporter,
  ) {
    parent::__construct();
  }

  /**
   * Prints legacy recipe import parity as JSON.
   *
   * @param array<string, bool|int|string> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:migrate:parity')]
  #[CLI\Option(name: 'path', description: 'Path to the legacy Fresco SQLite database.')]
  #[CLI\Option(name: 'sample-limit', description: 'Maximum number of missing, extra, and mismatch samples to include.')]
  #[CLI\Option(name: 'fail-on-mismatch', description: 'Exit with failure when parity issues are detected.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:migrate:parity', description: 'Compare the default legacy SQLite database with imported Drupal recipes.')]
  #[CLI\Usage(name: 'drush fresco:migrate:parity --fail-on-mismatch', description: 'Fail with exit code 3 when imported recipes do not match the legacy source.')]
  public function parity(
    array $options = [
      'path' => '',
      'sample-limit' => 10,
      'fail-on-mismatch' => FALSE,
    ],
  ): int {
    $path = trim((string) $options['path']);
    if ($path === '') {
      $path = (string) (getenv('FRESCO_LEGACY_DB') ?: '/mnt/fresco-legacy/app.sqlite');
    }
    $report = $this->parityReporter->report($path, (int) $options['sample-limit']);
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (($report['parity'] ?? FALSE) !== TRUE && $options['fail-on-mismatch'] === TRUE) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Imports legacy recipe artwork into recipe media references.
   *
   * @param array<string, bool|int|string> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:migrate:images')]
  #[CLI\Option(name: 'path', description: 'Path to the legacy Fresco SQLite database.')]
  #[CLI\Option(name: 'overwrite', description: 'Replace existing recipe hero images when a legacy image is available.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:migrate:images', description: 'Import legacy recipe images into media entities and attach them to recipes.')]
  public function images(
    array $options = [
      'path' => '',
      'overwrite' => FALSE,
    ],
  ): int {
    $path = trim((string) $options['path']);
    if ($path === '') {
      $path = (string) (getenv('FRESCO_LEGACY_DB') ?: '/mnt/fresco-legacy/app.sqlite');
    }
    $summary = $this->imageMigrator->migrate($path, $options['overwrite'] === TRUE);
    $this->output()->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints a read-only cutover rehearsal report as JSON.
   *
   * @param array<string, bool|int|string> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:migrate:rehearsal')]
  #[CLI\Option(name: 'path', description: 'Path to the legacy Fresco SQLite database.')]
  #[CLI\Option(name: 'sample-limit', description: 'Maximum number of missing samples to include.')]
  #[CLI\Option(name: 'fail-on-issues', description: 'Exit with failure when cutover rehearsal reports any issues.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:migrate:rehearsal', description: 'Inspect parity and legacy image coverage before cutover.')]
  #[CLI\Usage(name: 'drush fresco:migrate:rehearsal --fail-on-issues', description: 'Fail with exit code 3 when rehearsal issues remain.')]
  public function rehearsal(
    array $options = [
      'path' => '',
      'sample-limit' => 10,
      'fail-on-issues' => FALSE,
    ],
  ): int {
    $path = trim((string) $options['path']);
    if ($path === '') {
      $path = (string) (getenv('FRESCO_LEGACY_DB') ?: '/mnt/fresco-legacy/app.sqlite');
    }
    $report = $this->rehearsalReporter->report($path, (int) $options['sample-limit']);
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (($report['ready_for_cutover'] ?? FALSE) !== TRUE && $options['fail-on-issues'] === TRUE) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints a read-only rollback rehearsal report as JSON.
   *
   * @param array<string, bool|int|string> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:migrate:rollback')]
  #[CLI\Option(name: 'sample-limit', description: 'Maximum number of missing provenance or URL samples to include.')]
  #[CLI\Option(name: 'fail-on-issues', description: 'Exit with failure when rollback rehearsal reports any issues.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:migrate:rollback', description: 'Inspect whether imported recipes still retain rollback provenance.')]
  #[CLI\Usage(name: 'drush fresco:migrate:rollback --fail-on-issues', description: 'Fail with exit code 3 when rollback readiness issues remain.')]
  public function rollback(
    array $options = [
      'sample-limit' => 10,
      'fail-on-issues' => FALSE,
    ],
  ): int {
    $report = $this->rollbackReporter->report((int) $options['sample-limit']);
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (($report['ready_for_rollback'] ?? FALSE) !== TRUE && $options['fail-on-issues'] === TRUE) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Runs the final incremental migration and returns the readiness report.
   *
   * @param array<string, bool|int|string> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:migrate:finalize')]
  #[CLI\Option(name: 'path', description: 'Path to the legacy Fresco SQLite database.')]
  #[CLI\Option(name: 'sample-limit', description: 'Maximum number of report samples to include.')]
  #[CLI\Option(name: 'fail-on-issues', description: 'Exit with failure when any readiness issue remains.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:migrate:finalize', description: 'Run the final import pass, legacy image migration, and readiness reports.')]
  #[CLI\Usage(name: 'drush fresco:migrate:finalize --fail-on-issues', description: 'Fail with exit code 3 when cutover or rollback readiness issues remain.')]
  public function finalize(
    array $options = [
      'path' => '',
      'sample-limit' => 10,
      'fail-on-issues' => FALSE,
    ],
  ): int {
    $path = trim((string) $options['path']);
    if ($path === '') {
      $path = (string) (getenv('FRESCO_LEGACY_DB') ?: '/mnt/fresco-legacy/app.sqlite');
    }

    $import = $this->importRunner->run();
    $images = $this->imageMigrator->migrate($path, FALSE);
    $rehearsal = $this->rehearsalReporter->report($path, (int) $options['sample-limit']);
    $rollback = $this->rollbackReporter->report((int) $options['sample-limit']);

    $summary = [
      'import' => $import,
      'images' => $images,
      'rehearsal' => $rehearsal,
      'rollback' => $rollback,
      'ready_for_cutover' => ($rehearsal['ready_for_cutover'] ?? FALSE) === TRUE,
      'ready_for_rollback' => ($rollback['ready_for_rollback'] ?? FALSE) === TRUE,
    ];
    $summary['ready_for_final_increment'] = $summary['ready_for_cutover'] === TRUE && $summary['ready_for_rollback'] === TRUE;

    $this->output()->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if ($summary['ready_for_final_increment'] !== TRUE && $options['fail-on-issues'] === TRUE) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    return self::EXIT_SUCCESS;
  }

}
