<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\fresco_pages\Value\StaticArtifact;

/**
 * Compares expected managed static files with a remote Git tree.
 */
final class PublicationDriftAnalyzer {

  /**
   * Compares the expected managed static tree with the remote Git tree.
   *
   * @param \Drupal\fresco_pages\Value\StaticArtifact $site
   *   Expected complete static site artifact.
   * @param array<string, string> $remoteBlobs
   *   Remote Git blob SHAs keyed by repository path.
   *
   * @return array<string, int|string[]>
   *   Drift summary.
   */
  public function analyze(StaticArtifact $site, array $remoteBlobs): array {
    $expectedBlobs = [];
    foreach ($site->files as $path => $contents) {
      $expectedBlobs[$path] = $this->gitBlobSha($contents);
    }
    $expectedPaths = array_fill_keys(array_keys($expectedBlobs), TRUE);
    $deletedPaths = array_fill_keys($site->deletedPaths, TRUE);
    $managedRemote = [];
    foreach ($remoteBlobs as $path => $sha) {
      if ($this->isManagedPath($path)) {
        $managedRemote[$path] = $sha;
      }
    }

    $missing = array_values(array_diff(array_keys($expectedBlobs), array_keys($remoteBlobs)));
    sort($missing);
    $changed = [];
    foreach ($expectedBlobs as $path => $sha) {
      if (isset($remoteBlobs[$path]) && !hash_equals($sha, $remoteBlobs[$path])) {
        $changed[] = $path;
      }
    }
    sort($changed);
    $unexpected = array_values(array_diff(array_keys($managedRemote), array_keys($expectedPaths), array_keys($deletedPaths)));
    sort($unexpected);
    $deletedPresent = array_values(array_intersect(array_keys($remoteBlobs), array_keys($deletedPaths)));
    sort($deletedPresent);

    return [
      'remote_missing_count' => count($missing),
      'remote_missing_sample' => array_slice($missing, 0, 20),
      'remote_changed_count' => count($changed),
      'remote_changed_sample' => array_slice($changed, 0, 20),
      'remote_unexpected_count' => count($unexpected),
      'remote_unexpected_sample' => array_slice($unexpected, 0, 20),
      'remote_deleted_present_count' => count($deletedPresent),
      'remote_deleted_present_sample' => array_slice($deletedPresent, 0, 20),
    ];
  }

  /**
   * Computes the SHA Git stores for a blob with the given contents.
   */
  public function gitBlobSha(string $contents): string {
    return sha1('blob ' . strlen($contents) . "\0" . $contents);
  }

  /**
   * Returns whether a path is managed by Dresco static publication.
   */
  private function isManagedPath(string $path): bool {
    return in_array($path, [
      '.assetsignore',
      '.nojekyll',
      '_headers',
      '_redirects',
      'index.html',
      'robots.txt',
      'recipes.json',
      'sitemap.xml',
      'fresco-manifest.json',
    ], TRUE)
      || $path === 'family/index.html'
      || preg_match('#^(?:family/)?recipes/[a-z0-9]+(?:-[a-z0-9]+)*/index\.html$#', $path) === 1
      || preg_match('#^(?:family/)?assets/recipes/[a-z0-9]+(?:-[a-z0-9]+)*\.svg$#', $path) === 1;
  }

}
