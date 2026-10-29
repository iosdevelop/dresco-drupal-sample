<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\node\NodeInterface;

/**
 * Creates idempotent immutable publication records and queue items.
 */
final class PublicationManager implements PagesPublisherInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly LockBackendInterface $lock,
    private readonly RecipeNodeMapper $nodeMapper,
    private readonly StaticRecipeRenderer $renderer,
    private readonly TransliterationInterface $transliteration,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GitHubGitClient $gitHubClient,
    private readonly SiteArtifactAssembler $siteAssembler,
    private readonly PublicationDriftAnalyzer $driftAnalyzer,
    private readonly RecipeValidator $validator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function queue(int $nodeId, int $revisionId): string {
    return $this->queueRevision($nodeId, $revisionId)['uuid'];
  }

  /**
   * Queues a recipe revision and reports whether a new record was created.
   *
   * @return array{uuid: string, created: bool}
   *   Publication UUID and creation status.
   */
  private function queueRevision(int $nodeId, int $revisionId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->loadRevision($revisionId);
    if (!$node instanceof NodeInterface || (int) $node->id() !== $nodeId || $node->bundle() !== 'recipe' || !$node->isPublished()) {
      throw new \InvalidArgumentException('Only published recipe revisions can be queued.');
    }

    // Validate the recipe before attempting to publish.
    $recipe = $this->nodeMapper->fromNode($node);
    $validationResults = $this->validator->validate($recipe);
    if ($validationResults !== []) {
      throw new \InvalidArgumentException('Recipe validation failed: ' . implode(', ', array_map(
        static fn ($result) => $result->ruleId . ': ' . $result->message,
        $validationResults,
      )));
    }

    $slug = $this->slug($node);
    $artifact = $this->renderer->render(
      $recipe,
      $slug,
      (string) $this->configFactory->get('fresco_pages.settings')->get('site_url'),
      $node->hasField('field_family_only') && (bool) $node->get('field_family_only')->value,
    );
    $lockName = 'fresco_pages:' . $nodeId . ':' . $revisionId;
    if (!$this->lock->acquire($lockName, 10.0)) {
      throw new \RuntimeException('This recipe revision is already being queued.');
    }
    try {
      $publicationStorage = $this->entityTypeManager->getStorage('fresco_publication');
      $existing = $publicationStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipe', $nodeId)
        ->condition('recipe_revision_id', $revisionId)
        ->condition('content_hash', $artifact->contentHash)
        ->range(0, 1)
        ->execute();
      if ($existing !== []) {
        $publication = $publicationStorage->load(reset($existing));
        if (!$publication instanceof Publication) {
          throw new \RuntimeException('Existing publication could not be loaded.');
        }
        return [
          'uuid' => $publication->uuid(),
          'created' => FALSE,
        ];
      }
      $publication = $publicationStorage->create([
        'label' => $node->label() . ' · revision ' . $revisionId,
        'recipe' => $nodeId,
        'recipe_revision_id' => $revisionId,
        'content_hash' => $artifact->contentHash,
        'slug' => $artifact->slug,
        'public_url' => $artifact->publicUrl,
        'correlation_id' => $this->correlationId($nodeId, $artifact->contentHash),
        'operation' => 'publish',
        'state' => 'queued',
        'attempts' => 0,
        'artifact' => $artifact->toJson(),
      ]);
      $publication->save();
      $this->queueFactory->get('fresco_pages_publish')->createItem([
        'publication_id' => (int) $publication->id(),
      ]);
      return [
        'uuid' => $publication->uuid(),
        'created' => TRUE,
      ];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Queues an existing failed or ready publication again.
   */
  public function retry(int $publicationId): void {
    $publication = $this->entityTypeManager->getStorage('fresco_publication')->load($publicationId);
    if (!$publication instanceof Publication) {
      throw new \InvalidArgumentException('Publication not found.');
    }
    $publication->set('state', 'queued');
    $publication->set('error', NULL);
    $publication->save();
    $this->queueFactory->get('fresco_pages_publish')->createItem([
      'publication_id' => $publicationId,
    ]);
  }

  /**
   * Queues a public archive/tombstone for the latest published recipe URL.
   */
  public function archive(int $nodeId): string {
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('Only recipe nodes can be archived from Pages.');
    }
    $source = $this->latestSuccessfulPublication($nodeId);
    if (!$source instanceof Publication) {
      throw new \RuntimeException('This recipe does not have a public Pages publication to archive.');
    }
    $slug = (string) $source->get('slug')->value;
    $artifact = $this->renderer->archive(
      $node->label(),
      $slug,
      (string) $this->configFactory->get('fresco_pages.settings')->get('site_url'),
      str_contains((string) $source->get('public_url')->value, '/family/recipes/'),
    );
    $lockName = 'fresco_pages:archive:' . $nodeId . ':' . $artifact->contentHash;
    if (!$this->lock->acquire($lockName, 10.0)) {
      throw new \RuntimeException('This recipe is already being archived.');
    }
    try {
      $publicationStorage = $this->entityTypeManager->getStorage('fresco_publication');
      $existing = $publicationStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipe', $nodeId)
        ->condition('operation', 'archive')
        ->condition('content_hash', $artifact->contentHash)
        ->range(0, 1)
        ->execute();
      if ($existing !== []) {
        $publication = $publicationStorage->load(reset($existing));
        if (!$publication instanceof Publication) {
          throw new \RuntimeException('Existing archive publication could not be loaded.');
        }
        return $publication->uuid();
      }

      $publication = $publicationStorage->create([
        'label' => 'Archive ' . $node->label(),
        'recipe' => $nodeId,
        'recipe_revision_id' => (int) $node->getRevisionId(),
        'content_hash' => $artifact->contentHash,
        'slug' => $artifact->slug,
        'public_url' => $artifact->publicUrl,
        'correlation_id' => $this->correlationId($nodeId, $artifact->contentHash),
        'operation' => 'archive',
        'state' => 'queued',
        'attempts' => 0,
        'artifact' => $artifact->toJson(),
      ]);
      $publication->save();
      $this->queueFactory->get('fresco_pages_publish')->createItem([
        'publication_id' => (int) $publication->id(),
        'full_site' => TRUE,
      ]);
      return $publication->uuid();
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Queues the latest published revision for every published recipe node.
   *
   * @return array<string, int|string[]>
   *   Seed summary with inspected, queued, current, skipped, and error details.
   */
  public function queueAllPublishedRecipes(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'recipe')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('title')
      ->execute();

    $summary = [
      'inspected' => count($ids),
      'queued' => 0,
      'current' => 0,
      'skipped' => 0,
      'errors' => [],
    ];

    foreach ($nodeStorage->loadMultiple($ids) as $node) {
      try {
        $result = $this->queueRevision((int) $node->id(), (int) $node->getRevisionId());
        if ($result['created']) {
          $summary['queued']++;
        }
        else {
          $summary['current']++;
        }
      }
      catch (\Throwable $exception) {
        $summary['skipped']++;
        $summary['errors'][] = $node->label() . ': ' . $exception->getMessage();
      }
    }

    return $summary;
  }

  /**
   * Moves every public URL to a new site URL.
   *
   * Recipe pages embed their canonical URL, so each published recipe and
   * each archive tombstone is rendered again under the new URL; the old
   * publication records stay as history.
   *
   * @return array<string, mixed>
   *   Seed summary plus the archived recipe IDs that were re-archived.
   */
  public function rebaseSiteUrl(string $siteUrl, bool $dryRun = FALSE): array {
    $siteUrl = rtrim($siteUrl, '/');
    if (filter_var($siteUrl, FILTER_VALIDATE_URL) === FALSE || !str_starts_with($siteUrl, 'https://')) {
      throw new \InvalidArgumentException('The new site URL must be an https:// URL.');
    }
    $config = $this->configFactory->getEditable('fresco_pages.settings');
    $archived = $this->recipesWhoseLatestEventIsArchive();
    if ($dryRun) {
      return ['from' => $config->get('site_url'), 'to' => $siteUrl, 'archived' => $archived];
    }
    $config->set('site_url', $siteUrl)->save();
    $summary = $this->queueAllPublishedRecipes();
    foreach ($archived as $nodeId) {
      $this->archive($nodeId);
    }
    return $summary + ['archived' => $archived];
  }

  /**
   * Lists recipes whose latest successful public event is an archive.
   *
   * @return array<int, int>
   *   Recipe node IDs.
   */
  private function recipesWhoseLatestEventIsArchive(): array {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['published', 'archived'], 'IN')
      ->sort('id', 'ASC')
      ->execute();
    $latest = [];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if ($publication instanceof Publication) {
        $latest[(int) $publication->get('recipe')->target_id] = (string) $publication->get('operation')->value;
      }
    }
    return array_keys(array_filter($latest, static fn (string $operation): bool => $operation === 'archive'));
  }

  /**
   * Requeues stale processing records after an interrupted worker.
   *
   * @return array{inspected: int, recovered: int}
   *   Recovery summary.
   */
  public function recoverStaleProcessing(int $olderThanSeconds = 1800): array {
    if ($olderThanSeconds < 60) {
      throw new \InvalidArgumentException('The stale processing threshold must be at least 60 seconds.');
    }
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'processing')
      ->condition('changed', time() - $olderThanSeconds, '<')
      ->execute();

    $summary = [
      'inspected' => count($ids),
      'recovered' => 0,
    ];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if (!$publication instanceof Publication) {
        continue;
      }
      $publication->set('state', 'queued');
      $publication->set('error', 'Recovered from stale processing state by operator command.');
      $publication->save();
      $this->queueFactory->get('fresco_pages_publish')->createItem([
        'publication_id' => (int) $publication->id(),
      ]);
      $summary['recovered']++;
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function reconcile(): array {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $summary = [];
    foreach (['queued', 'processing', 'ready', 'published', 'archived', 'failed'] as $state) {
      $summary[$state] = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', $state)
        ->count()
        ->execute();
    }
    try {
      $site = $this->siteAssembler->fullSite();
      $manifest = $site->files['fresco-manifest.json'];
      $summary['expected_manifest_hash'] = hash('sha256', $manifest);
      if ($this->configFactory->get('fresco_pages.settings')->get('transport') === 'github') {
        $summary['branch_head'] = $this->gitHubClient->branchHead();
        $remoteManifest = $this->gitHubClient->fileContents('fresco-manifest.json');
        $summary['remote_manifest_hash'] = hash('sha256', $remoteManifest);
        $drift = $this->driftAnalyzer->analyze($site, $this->gitHubClient->treeBlobShas());
        $summary += $drift;
        $summary['drift'] = !hash_equals($manifest, $remoteManifest)
          || $drift['remote_missing_count'] > 0
          || $drift['remote_changed_count'] > 0
          || $drift['remote_unexpected_count'] > 0
          || $drift['remote_deleted_present_count'] > 0;
      }
      else {
        $summary['drift'] = NULL;
        $summary['remote_missing_count'] = NULL;
        $summary['remote_changed_count'] = NULL;
        $summary['remote_unexpected_count'] = NULL;
        $summary['remote_deleted_present_count'] = NULL;
      }
    }
    catch (\Throwable $exception) {
      $summary['drift'] = TRUE;
      $summary['reconciliation_error'] = $exception->getMessage();
    }
    return $summary;
  }

  /**
   * Queues a complete expected-site repair using the newest valid record.
   */
  public function repair(): void {
    $this->siteAssembler->fullSite();
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['ready', 'published', 'archived'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $publication = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$publication instanceof Publication) {
      throw new \RuntimeException('No publication is available for full-site repair.');
    }
    $publication->set('state', 'queued');
    $publication->set('error', NULL);
    $publication->save();
    $this->queueFactory->get('fresco_pages_publish')->createItem([
      'publication_id' => (int) $publication->id(),
      'full_site' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function requiresArchiveBeforeDelete(int $nodeId): bool {
    $publication = $this->latestSuccessfulPublication($nodeId);
    if (!$publication instanceof Publication) {
      return FALSE;
    }
    $operation = (string) ($publication->get('operation')->value ?: 'publish');
    $state = (string) $publication->get('state')->value;
    return $operation !== 'archive' || $state !== 'archived';
  }

  /**
   * Preserves a stable existing Pages slug or derives one from the title.
   */
  private function slug(NodeInterface $node): string {
    $pagesUrl = trim((string) $node->get('field_pages_url')->value);
    $path = is_string(parse_url($pagesUrl, PHP_URL_PATH)) ? (string) parse_url($pagesUrl, PHP_URL_PATH) : '';
    if (preg_match('~/recipes/([a-z0-9]+(?:-[a-z0-9]+)*)/?$~', $path, $matches) === 1) {
      return $this->canonicalSlug((string) $matches[1], $node->label());
    }
    $title = $this->transliteration->transliterate($node->label());
    $title = $this->stripLeadingIngredientCount($title);
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    return $slug !== '' ? $slug : 'recipe-' . $node->id();
  }

  /**
   * Normalizes imported ingredient-count slugs while preserving true numbers.
   */
  private function canonicalSlug(string $slug, string $title): string {
    if (preg_match('/^1-([a-z0-9]+(?:-[a-z0-9]+)+)$/', $slug, $matches) !== 1) {
      return $slug;
    }
    return $this->titleStartsWithIngredientCount($title) ? $matches[1] : $slug;
  }

  /**
   * Removes singular ingredient counts accidentally migrated into titles.
   */
  private function stripLeadingIngredientCount(string $title): string {
    if (!$this->titleStartsWithIngredientCount($title)) {
      return $title;
    }
    return trim((string) preg_replace('/^(?:1|one)\s+/i', '', $title, 1));
  }

  /**
   * Detects titles imported from an ingredient line such as "1 Eggplant ...".
   */
  private function titleStartsWithIngredientCount(string $title): bool {
    $normalized = trim((string) preg_replace('/\s+/', ' ', $title));
    if (preg_match('/^(?:1|one)\s+([a-z][a-z\'-]*)\b/i', $normalized, $matches) !== 1) {
      return FALSE;
    }
    $firstWord = strtolower($matches[1]);
    if (in_array($firstWord, ['bowl', 'pan', 'pot', 'sheet', 'skillet', 'tray'], TRUE)) {
      return FALSE;
    }
    $wordCount = preg_match_all('/[a-z][a-z\'-]*/i', $normalized, $words);
    return $wordCount !== FALSE && $wordCount >= 3;
  }

  /**
   * Loads the newest successful publication or archive record for a recipe.
   */
  private function latestSuccessfulPublication(int $nodeId): ?Publication {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $nodeId)
      ->condition('state', ['ready', 'published', 'archived'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $publication = $ids === [] ? NULL : $storage->load(reset($ids));
    return $publication instanceof Publication ? $publication : NULL;
  }

  /**
   * Creates a short operator-facing trace ID for a publication record.
   */
  private function correlationId(int $nodeId, string $contentHash): string {
    return 'fp-' . $nodeId . '-' . substr($contentHash, 0, 12) . '-' . bin2hex(random_bytes(4));
  }

}
