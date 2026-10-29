<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Drush\Commands;

use Drupal\fresco_pages\Service\GitHubPreflight;
use Drupal\fresco_pages\Service\LocalStaticTreeInspector;
use Drupal\fresco_pages\Service\PublicationDriftAnalyzer;
use Drupal\fresco_pages\Service\PublicationManager;
use Drupal\fresco_pages\Service\SiteArtifactAssembler;
use Drupal\fresco_pages\Service\StaticArtifactWriter;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;

/**
 * Drush operations for Dresco Pages publication.
 */
final class FrescoPagesCommands extends DrushCommands {

  public function __construct(
    private readonly PublicationManager $publicationManager,
    private readonly SiteArtifactAssembler $siteAssembler,
    private readonly StaticArtifactWriter $artifactWriter,
    private readonly LocalStaticTreeInspector $localTreeInspector,
    private readonly PublicationDriftAnalyzer $driftAnalyzer,
    private readonly GitHubPreflight $gitHubPreflight,
  ) {
    parent::__construct();
  }

  /**
   * Queues latest published revisions for every published recipe.
   */
  #[CLI\Command(name: 'fresco:pages:seed')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:seed', description: 'Seed publication records after import or migration.')]
  public function seed(): void {
    $summary = $this->publicationManager->queueAllPublishedRecipes();
    $this->logger()?->success(dt('Inspected @inspected recipes: @queued queued, @current current, @skipped skipped.', [
      '@inspected' => $summary['inspected'],
      '@queued' => $summary['queued'],
      '@current' => $summary['current'],
      '@skipped' => $summary['skipped'],
    ]));
    foreach ($summary['errors'] as $error) {
      $this->logger()?->error((string) $error);
    }
  }

  /**
   * Prints publication reconciliation data as JSON.
   */
  #[CLI\Command(name: 'fresco:pages:reconcile')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:reconcile', description: 'Inspect local publication state and remote drift.')]
  public function reconcile(): void {
    $summary = $this->publicationManager->reconcile();
    $this->output()->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Checks GitHub Pages live-readiness without writing to GitHub.
   */
  #[CLI\Command(name: 'fresco:pages:github-preflight')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:github-preflight', description: 'Check GitHub App settings and read access without publishing.')]
  public function githubPreflight(): void {
    $this->output()->writeln(json_encode($this->gitHubPreflight->check(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Exports the complete expected static site to a local directory.
   *
   * @param string $directory
   *   Destination directory.
   * @param array<string, bool> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:pages:export')]
  #[CLI\Argument(name: 'directory', description: 'Destination directory for the static site bundle.')]
  #[CLI\Option(name: 'overwrite', description: 'Overwrite changed files and remove tombstoned files inside the destination.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:export ../fresco-pages-preview', description: 'Export the expected static site locally.')]
  public function export(string $directory, array $options = ['overwrite' => FALSE]): void {
    $summary = $this->artifactWriter->write(
      $this->siteAssembler->fullSite(),
      $directory,
      $options['overwrite'] === TRUE,
    );
    $this->logger()?->success(dt('Exported static site to @directory: @written written, @unchanged unchanged, @deleted deleted.', [
      '@directory' => $directory,
      '@written' => $summary['written'],
      '@unchanged' => $summary['unchanged'],
      '@deleted' => $summary['deleted'],
    ]));
  }

  /**
   * Compares the expected static site with a local export directory.
   *
   * @param string $directory
   *   Local static site directory to inspect.
   * @param array<string, bool> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:pages:diff-local')]
  #[CLI\Argument(name: 'directory', description: 'Local static site directory to inspect.')]
  #[CLI\Option(name: 'fail-on-drift', description: 'Exit with failure when drift is detected.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:diff-local ../fresco-pages-preview', description: 'Compare expected Pages files with a local export.')]
  #[CLI\Usage(name: 'drush fresco:pages:diff-local ../fresco-pages-preview --fail-on-drift', description: 'Compare expected Pages files and fail with exit code 3 when drift is detected.')]
  public function diffLocal(string $directory, array $options = ['fail-on-drift' => FALSE]): int {
    $summary = $this->driftAnalyzer->analyze(
      $this->siteAssembler->fullSite(),
      $this->localTreeInspector->blobShas($directory),
    );
    $summary['drift'] = $summary['remote_missing_count'] > 0
      || $summary['remote_changed_count'] > 0
      || $summary['remote_unexpected_count'] > 0
      || $summary['remote_deleted_present_count'] > 0;
    $this->output()->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if ($summary['drift'] === TRUE && $options['fail-on-drift'] === TRUE) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Queues a complete site repair publication.
   */
  #[CLI\Command(name: 'fresco:pages:repair')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:repair', description: 'Queue a full-site repair publication.')]
  public function repair(): void {
    $this->publicationManager->repair();
    $this->logger()?->success(dt('Full-site repair queued.'));
  }

  /**
   * Moves every public recipe URL to a new site URL.
   *
   * @param string $url
   *   New canonical site URL.
   * @param array<string, bool> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:pages:rebase-site-url')]
  #[CLI\Argument(name: 'url', description: 'New canonical site URL, such as https://recipes.example.com.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving or queueing.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:rebase-site-url https://recipes.example.com --dry-run', description: 'Preview moving recipe URLs to the custom domain.')]
  public function rebaseSiteUrl(string $url, array $options = ['dry-run' => FALSE]): void {
    $summary = $this->publicationManager->rebaseSiteUrl($url, (bool) $options['dry-run']);
    if ($options['dry-run']) {
      $this->output()->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      return;
    }
    $this->logger()?->success(dt('Site URL is now @url: @queued recipes queued, @archived archives re-rendered. Run the publish queue to deploy.', [
      '@url' => $url,
      '@queued' => $summary['queued'],
      '@archived' => count($summary['archived']),
    ]));
    foreach ($summary['errors'] as $error) {
      $this->logger()?->error((string) $error);
    }
  }

  /**
   * Requeues stale processing records after an interrupted worker.
   *
   * @param array<string, int> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:pages:recover-stale')]
  #[CLI\Option(name: 'older-than', description: 'Minimum processing age in seconds before a record is recovered.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:recover-stale --older-than=1800', description: 'Recover processing records older than 30 minutes.')]
  public function recoverStale(array $options = ['older-than' => 1800]): void {
    $summary = $this->publicationManager->recoverStaleProcessing((int) $options['older-than']);
    $this->logger()?->success(dt('Inspected @inspected stale processing records; @recovered requeued.', [
      '@inspected' => $summary['inspected'],
      '@recovered' => $summary['recovered'],
    ]));
  }

  /**
   * Queues an existing publication record again.
   */
  #[CLI\Command(name: 'fresco:pages:retry')]
  #[CLI\Argument(name: 'publication_id', description: 'Dresco publication entity ID.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:retry 12', description: 'Queue publication record 12 again.')]
  public function retry(int $publication_id): void {
    $this->publicationManager->retry($publication_id);
    $this->logger()?->success(dt('Publication @id queued again.', [
      '@id' => $publication_id,
    ]));
  }

  /**
   * Queues an archive/tombstone operation for a recipe.
   */
  #[CLI\Command(name: 'fresco:pages:archive')]
  #[CLI\Argument(name: 'node_id', description: 'Recipe node ID to archive from public Pages.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:pages:archive 5', description: 'Archive recipe node 5 from public Pages.')]
  public function archive(int $node_id): void {
    $uuid = $this->publicationManager->archive($node_id);
    $this->logger()?->success(dt('Archive publication queued for recipe @id as @uuid.', [
      '@id' => $node_id,
      '@uuid' => $uuid,
    ]));
  }

}
