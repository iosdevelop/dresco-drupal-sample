<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Value;

/**
 * Immutable static publication artifact.
 */
final readonly class StaticArtifact {

  /**
   * Creates an immutable publication artifact.
   *
   * @param string $slug
   *   Stable recipe slug.
   * @param string $publicUrl
   *   Canonical public recipe URL.
   * @param array<string, string> $files
   *   Repository paths keyed to complete file contents.
   * @param string $contentHash
   *   SHA-256 hash of normalized files and deletions.
   * @param array<int, string> $deletedPaths
   *   Repository paths to remove from the public branch.
   */
  public function __construct(
    public string $slug,
    public string $publicUrl,
    public array $files,
    public string $contentHash,
    public array $deletedPaths = [],
  ) {}

  /**
   * Serializes the artifact for durable queue storage.
   */
  public function toJson(): string {
    return json_encode([
      'slug' => $this->slug,
      'public_url' => $this->publicUrl,
      'files' => $this->files,
      'deleted_paths' => $this->deletedPaths,
      'content_hash' => $this->contentHash,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Restores a validated artifact from queue storage.
   */
  public static function fromJson(string $json): self {
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['slug'] ?? NULL) ||
      !is_string($data['public_url'] ?? NULL) || !is_array($data['files'] ?? NULL) ||
      !is_string($data['content_hash'] ?? NULL)) {
      throw new \UnexpectedValueException('Stored publication artifact is invalid.');
    }
    $files = [];
    foreach ($data['files'] as $path => $contents) {
      if (!is_string($path) || !is_string($contents) || str_contains($path, '..') || str_starts_with($path, '/')) {
        throw new \UnexpectedValueException('Stored publication file is invalid.');
      }
      $files[$path] = $contents;
    }
    $deletedPaths = [];
    $hasDeletionMetadata = isset($data['deleted_paths']);
    if ($hasDeletionMetadata) {
      if (!is_array($data['deleted_paths'])) {
        throw new \UnexpectedValueException('Stored publication deletions are invalid.');
      }
      foreach ($data['deleted_paths'] as $path) {
        if (!is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
          throw new \UnexpectedValueException('Stored publication deletion path is invalid.');
        }
        $deletedPaths[] = $path;
      }
      $deletedPaths = array_values(array_unique($deletedPaths));
      sort($deletedPaths);
    }
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug']) !== 1 ||
      preg_match('/^[a-f0-9]{64}$/', $data['content_hash']) !== 1) {
      throw new \UnexpectedValueException('Stored publication metadata is invalid.');
    }
    ksort($files);
    $computedHash = $hasDeletionMetadata
      ? hash('sha256', json_encode([
        'files' => $files,
        'deleted_paths' => $deletedPaths,
      ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
      : hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!hash_equals($data['content_hash'], $computedHash)) {
      throw new \UnexpectedValueException('Stored publication artifact failed its integrity check.');
    }
    return new self($data['slug'], $data['public_url'], $files, $computedHash, $deletedPaths);
  }

}
