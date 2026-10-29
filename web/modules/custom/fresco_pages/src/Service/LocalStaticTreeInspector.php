<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

/**
 * Reads a local static-site directory as Git-style blob SHAs.
 */
final class LocalStaticTreeInspector {

  public function __construct(
    private readonly PublicationDriftAnalyzer $driftAnalyzer,
  ) {}

  /**
   * Lists local file blob SHAs keyed by repository-relative path.
   *
   * @return array<string, string>
   *   Git blob SHA keyed by relative path.
   */
  public function blobShas(string $directory): array {
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    if ($directory === '' || !is_dir($directory)) {
      throw new \InvalidArgumentException('A readable local static directory is required.');
    }
    $root = realpath($directory);
    if ($root === FALSE) {
      throw new \InvalidArgumentException('The local static directory could not be resolved.');
    }

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $item) {
      if (!$item->isFile()) {
        continue;
      }
      $path = $item->getPathname();
      $relative = substr($path, strlen($root) + 1);
      if ($relative === '') {
        continue;
      }
      $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException('Local static file could not be read: ' . $relative);
      }
      $files[$relative] = $this->driftAnalyzer->gitBlobSha($contents);
    }
    ksort($files);
    return $files;
  }

}
