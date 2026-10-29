<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\node\NodeInterface;

/**
 * Assembles latest publications into a complete deterministic Pages site.
 */
final class SiteArtifactAssembler {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RecipeNodeMapper $nodeMapper,
    private readonly StaticSiteRenderer $siteRenderer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Adds aggregate files and optionally every current recipe artifact.
   */
  public function assemble(Publication $current, bool $fullSite = FALSE): StaticArtifact {
    $latest = $this->latestPublications($current);
    $currentArtifact = StaticArtifact::fromJson((string) $current->get('artifact')->value);
    $files = $fullSite ? [] : $currentArtifact->files;
    $deletedPaths = $fullSite ? [] : $currentArtifact->deletedPaths;
    $recipes = [];
    $redirects = [];
    $occupiedSlugs = [];
    $activeAssetPaths = [];
    foreach ($latest as $recipeId => $publication) {
      $artifact = StaticArtifact::fromJson((string) $publication->get('artifact')->value);
      if ($fullSite) {
        $files += $artifact->files;
        $deletedPaths = [...$deletedPaths, ...$artifact->deletedPaths];
      }
      $operation = (string) ($publication->get('operation')->value ?: 'publish');
      if ($operation === 'archive') {
        $occupiedSlugs[$artifact->slug] = TRUE;
        foreach ($this->historicalSlugs($recipeId) as $oldSlug) {
          if ($oldSlug !== $artifact->slug) {
            $redirects[$oldSlug] = $artifact->publicUrl;
            $deletedPaths[] = $this->recipeAssetPath($oldSlug);
          }
        }
        continue;
      }
      $descriptor = $this->descriptor($publication, $artifact);
      $recipes[] = $descriptor;
      $slug = (string) $descriptor['slug'];
      $family = (bool) $descriptor['family'];
      if (!$family) {
        $occupiedSlugs[$slug] = TRUE;
      }
      $activeAssetPaths[($family ? 'family/' : '') . $this->recipeAssetPath($slug)] = TRUE;
      foreach ($this->historicalPublications($recipeId) as [$oldSlug, $oldFamily]) {
        if (!$oldFamily && ($family || $oldSlug !== $slug)) {
          // A public URL that moved, or whose recipe became family-only,
          // becomes a content-free redirect so no recipe stays public.
          $redirects[$oldSlug] = (string) $descriptor['public_url'];
          $deletedPaths[] = $this->recipeAssetPath($oldSlug);
        }
        elseif ($oldFamily && (!$family || $oldSlug !== $slug)) {
          $deletedPaths[] = 'family/recipes/' . $oldSlug . '/index.html';
          $deletedPaths[] = 'family/' . $this->recipeAssetPath($oldSlug);
        }
      }
    }
    foreach (array_keys($occupiedSlugs) as $slug) {
      unset($redirects[$slug]);
    }
    $deletedPaths = array_values(array_diff($deletedPaths, array_keys($activeAssetPaths)));
    // Search indexing is off, so remove any sitemap an earlier build left.
    $deletedPaths[] = 'sitemap.xml';
    $siteUrl = (string) $this->configFactory->get('fresco_pages.settings')->get('site_url');
    $files = $this->siteRenderer->render($recipes, $redirects, $siteUrl) + $files;
    ksort($files);
    $deletedPaths = array_values(array_unique($deletedPaths));
    sort($deletedPaths);
    $hash = hash('sha256', json_encode([
      'files' => $files,
      'deleted_paths' => $deletedPaths,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return new StaticArtifact($currentArtifact->slug, $currentArtifact->publicUrl, $files, $hash, $deletedPaths);
  }

  /**
   * Builds the full expected site from the most recent eligible publication.
   */
  public function fullSite(): StaticArtifact {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['ready', 'published', 'archived'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $publication = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$publication instanceof Publication) {
      throw new \RuntimeException('No publication is ready for site assembly.');
    }
    return $this->assemble($publication, TRUE);
  }

  /**
   * Selects one latest eligible event per recipe, including the current job.
   *
   * @return array<int, \Drupal\fresco_pages\Entity\Publication>
   *   Latest publications keyed by recipe ID.
   */
  private function latestPublications(Publication $current): array {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['ready', 'published', 'archived', 'processing'], 'IN')
      ->sort('created', 'ASC')
      ->execute();
    $latest = [];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if ($publication instanceof Publication) {
        $latest[(int) $publication->get('recipe')->target_id] = $publication;
      }
    }
    $latest[(int) $current->get('recipe')->target_id] = $current;
    ksort($latest);
    return $latest;
  }

  /**
   * Builds a public descriptor from the exact queued recipe revision.
   *
   * @return array<string, int|string>
   *   Aggregate recipe descriptor.
   */
  private function descriptor(Publication $publication, StaticArtifact $artifact): array {
    $revisionId = (int) $publication->get('recipe_revision_id')->value;
    $node = $this->entityTypeManager->getStorage('node')->loadRevision($revisionId);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'recipe') {
      throw new \RuntimeException('A publication recipe revision is missing.');
    }
    $recipe = $this->nodeMapper->fromNode($node);
    $siteUrl = rtrim((string) $this->configFactory->get('fresco_pages.settings')->get('site_url'), '/');
    $family = str_contains($artifact->publicUrl, '/family/recipes/');
    return [
      'recipe_id' => (int) $node->id(),
      'revision_id' => $revisionId,
      'slug' => $artifact->slug,
      'title' => $recipe->title,
      'description' => $recipe->description,
      'cuisine' => $recipe->cuisine,
      'servings' => $recipe->servings,
      'content_hash' => (string) $publication->get('content_hash')->value,
      'public_url' => $artifact->publicUrl,
      'image_url' => $siteUrl . ($family ? '/family' : '') . '/assets/recipes/' . $artifact->slug . '.svg',
      'family' => (int) $family,
    ];
  }

  /**
   * Returns every prior successful slug for a recipe.
   *
   * @return array<int, string>
   *   Historical slugs.
   */
  private function historicalSlugs(int $recipeId): array {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $recipeId)
      ->condition('state', ['ready', 'published', 'archived', 'processing'], 'IN')
      ->execute();
    $slugs = [];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if ($publication instanceof Publication) {
        $slugs[] = (string) $publication->get('slug')->value;
      }
    }
    return array_values(array_unique($slugs));
  }

  /**
   * Returns every prior successful slug for a recipe and whether it was family.
   *
   * @return array<int, array{0: string, 1: bool}>
   *   Slug and family-only pairs.
   */
  private function historicalPublications(int $recipeId): array {
    $storage = $this->entityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $recipeId)
      ->condition('state', ['ready', 'published', 'archived', 'processing'], 'IN')
      ->execute();
    $pairs = [];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if ($publication instanceof Publication) {
        $family = str_contains((string) $publication->get('public_url')->value, '/family/recipes/');
        $slug = (string) $publication->get('slug')->value;
        $pairs[$slug . ($family ? ':family' : '')] = [$slug, $family];
      }
    }
    return array_values($pairs);
  }

  /**
   * Returns the generated recipe SVG path for a slug.
   */
  private function recipeAssetPath(string $slug): string {
    return 'assets/recipes/' . $slug . '.svg';
  }

}
