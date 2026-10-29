<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use GuzzleHttp\ClientInterface;

/**
 * Imports legacy recipe artwork into media entities.
 */
final class LegacyRecipeImageMigrator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly RecipeSvgBuilder $svgBuilder,
  ) {}

  /**
   * Imports legacy recipe image URLs into recipe media references.
   *
   * @return array<string, int>
   *   Migration counts.
   */
  public function migrate(string $path, bool $overwrite = FALSE): array {
    if ($path === '' || !is_readable($path)) {
      throw new \RuntimeException('Legacy Fresco database is not readable at ' . ($path ?: '[empty path]'));
    }

    $database = new \PDO('sqlite:' . $path, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $rows = $database->query("SELECT id, title, image_url FROM recipes WHERE COALESCE(image_url, '') <> '' ORDER BY id")->fetchAll();

    $imported = 0;
    $skipped = 0;
    $updated = 0;
    foreach ($rows as $row) {
      $legacyId = (int) $row['id'];
      $imageUrl = trim((string) $row['image_url']);
      $title = trim((string) $row['title']);
      $node = $this->loadRecipeNode($legacyId);
      if (!$node instanceof NodeInterface) {
        $skipped++;
        continue;
      }
      if ($node->hasField('field_hero_image') && !$node->get('field_hero_image')->isEmpty() && !$overwrite) {
        $skipped++;
        continue;
      }

      $mediaId = $this->createMediaFromUrl($imageUrl, $title ?: 'Recipe ' . $legacyId, $node);
      if ($mediaId === NULL) {
        $skipped++;
        continue;
      }

      $node->set('field_hero_image', [['target_id' => $mediaId]]);
      $node->save();
      $updated++;
      $imported++;
    }

    return [
      'inspected' => count($rows),
      'imported' => $imported,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  /**
   * Creates or reuses a media entity backed by a local file.
   */
  private function createMediaFromUrl(string $imageUrl, string $title, ?NodeInterface $node = NULL): ?int {
    $data = $this->fetchImageData($imageUrl, $title, $node);
    if ($data === NULL || $data === '') {
      return NULL;
    }

    $extension = $this->extensionFromUrl($imageUrl) ?: 'img';
    $directory = 'public://fresco/legacy-images';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $destination = sprintf('%s/%s.%s', $directory, hash('sha256', $imageUrl . "\n" . $data), $extension);
    if (file_put_contents($destination, $data) === FALSE) {
      return NULL;
    }
    $uri = $destination;

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
      'filename' => basename($uri),
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'recipe_image',
      'name' => $title,
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $title,
        'title' => $title,
      ],
    ]);
    $media->save();
    return (int) $media->id();
  }

  /**
   * Loads the Drupal recipe node for a legacy ID.
   */
  private function loadRecipeNode(int $legacyId): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'recipe')
      ->condition('field_legacy_id', $legacyId)
      ->execute();
    $node = $ids === [] ? NULL : $storage->load((int) reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Fetches image bytes from a local path or URL.
   */
  private function fetchImageData(string $imageUrl, string $title, ?NodeInterface $node = NULL): ?string {
    $localPath = $this->resolveLocalImagePath($imageUrl);
    if ($localPath !== NULL) {
      $data = @file_get_contents($localPath);
      return $data === FALSE ? NULL : $data;
    }

    if ($node instanceof NodeInterface && str_starts_with($imageUrl, '/generated/recipes/')) {
      return $this->svgBuilder->build($title, $this->ingredientsFromNode($node));
    }

    if (parse_url($imageUrl, PHP_URL_SCHEME) === 'http' || parse_url($imageUrl, PHP_URL_SCHEME) === 'https') {
      $response = $this->httpClient->request('GET', $imageUrl, ['http_errors' => TRUE]);
      return (string) $response->getBody();
    }

    return NULL;
  }

  /**
   * Determines a file extension from the source URL.
   */
  private function extensionFromUrl(string $imageUrl): string {
    $path = parse_url($imageUrl, PHP_URL_PATH);
    if ($path === '') {
      return '';
    }
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    return strtolower($extension);
  }

  /**
   * Resolves a legacy image URL to a local file path when possible.
   */
  private function resolveLocalImagePath(string $imageUrl): ?string {
    if (str_starts_with($imageUrl, 'file://')) {
      $path = substr($imageUrl, 7);
      return is_file($path) ? $path : NULL;
    }

    if (parse_url($imageUrl, PHP_URL_SCHEME) !== NULL) {
      return NULL;
    }

    if (is_file($imageUrl)) {
      return $imageUrl;
    }

    $legacyRoot = trim((string) getenv('FRESCO_LEGACY_APP_ROOT'));
    if ($legacyRoot !== '') {
      $candidate = rtrim($legacyRoot, '/') . '/' . ltrim($imageUrl, '/');
      if (is_file($candidate)) {
        return $candidate;
      }
    }

    if (basename($imageUrl) === 'default-recipe.svg') {
      $bundled = dirname(__DIR__, 2) . '/assets/default-recipe.svg';
      if (is_file($bundled)) {
        return $bundled;
      }
    }

    return NULL;
  }

  /**
   * Extracts display-ready ingredient text from an imported recipe node.
   *
   * @return array<int, string>
   *   Ingredient lines.
   */
  private function ingredientsFromNode(NodeInterface $node): array {
    if (!$node->hasField('field_ingredients') || $node->get('field_ingredients')->isEmpty()) {
      return [];
    }

    $ingredients = [];
    foreach ($node->get('field_ingredients')->getValue() as $item) {
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $ingredients[] = $value;
      }
    }
    return $ingredients;
  }

}
