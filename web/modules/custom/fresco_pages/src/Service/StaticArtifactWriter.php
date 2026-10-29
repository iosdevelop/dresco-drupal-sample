<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\fresco_pages\Value\StaticArtifact;

/**
 * Writes static artifacts to a local directory for release rehearsal.
 */
final class StaticArtifactWriter {

  /**
   * Writes artifact files to a destination directory.
   *
   * @return array{written: int, unchanged: int, deleted: int}
   *   Export summary.
   */
  public function write(StaticArtifact $artifact, string $directory, bool $overwrite = FALSE): array {
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    if ($directory === '') {
      throw new \InvalidArgumentException('An export directory is required.');
    }
    if (is_file($directory)) {
      throw new \InvalidArgumentException('The export destination is a file.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException('The export directory could not be created.');
    }

    $summary = [
      'written' => 0,
      'unchanged' => 0,
      'deleted' => 0,
    ];

    foreach ($artifact->files as $path => $contents) {
      $target = $this->targetPath($directory, $path);
      $parent = dirname($target);
      if (!is_dir($parent) && !mkdir($parent, 0775, TRUE) && !is_dir($parent)) {
        throw new \RuntimeException('An export subdirectory could not be created.');
      }
      if (is_file($target)) {
        $existing = file_get_contents($target);
        if ($existing === $contents) {
          $summary['unchanged']++;
          continue;
        }
        if (!$overwrite) {
          throw new \RuntimeException('Export file already exists with different contents: ' . $path);
        }
      }
      if (file_put_contents($target, $contents) === FALSE) {
        throw new \RuntimeException('Export file could not be written: ' . $path);
      }
      $summary['written']++;
    }

    foreach ($artifact->deletedPaths as $path) {
      $target = $this->targetPath($directory, $path);
      if (is_file($target)) {
        if (!$overwrite) {
          throw new \RuntimeException('Export tombstone path exists; rerun with overwrite to remove it: ' . $path);
        }
        if (!unlink($target)) {
          throw new \RuntimeException('Export tombstone path could not be removed: ' . $path);
        }
        $summary['deleted']++;
      }
    }

    return $summary;
  }

  /**
   * Builds a destination path and rejects path traversal.
   */
  private function targetPath(string $directory, string $path): string {
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
      throw new \UnexpectedValueException('Artifact path is unsafe: ' . $path);
    }
    return $directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
  }

}
