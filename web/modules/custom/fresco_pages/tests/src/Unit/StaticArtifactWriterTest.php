<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\StaticArtifactWriter;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests local static artifact export behavior.
 */
#[Group('fresco_pages')]
final class StaticArtifactWriterTest extends UnitTestCase {

  /**
   * Temporary export directory.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/fresco-pages-export-' . bin2hex(random_bytes(6));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->directory) && is_dir($this->directory)) {
      $this->deleteTree($this->directory);
    }
    parent::tearDown();
  }

  /**
   * Confirms write, unchanged, and tombstone counts.
   */
  public function testWrite(): void {
    $writer = new StaticArtifactWriter();
    $artifact = $this->artifact(['assets/recipes/old.svg']);
    mkdir($this->directory . '/assets/recipes', 0775, TRUE);
    file_put_contents($this->directory . '/assets/recipes/old.svg', '<svg>old</svg>');

    $summary = $writer->write($artifact, $this->directory, TRUE);

    self::assertSame(['written' => 2, 'unchanged' => 0, 'deleted' => 1], $summary);
    self::assertFileExists($this->directory . '/index.html');
    self::assertSame('<h1>Recipe</h1>', file_get_contents($this->directory . '/recipes/recipe/index.html'));
    self::assertFileDoesNotExist($this->directory . '/assets/recipes/old.svg');

    $summary = $writer->write($artifact, $this->directory);
    self::assertSame(['written' => 0, 'unchanged' => 2, 'deleted' => 0], $summary);
  }

  /**
   * Confirms changed files require an explicit overwrite.
   */
  public function testOverwriteIsRequiredForChangedFiles(): void {
    $writer = new StaticArtifactWriter();
    mkdir($this->directory, 0775, TRUE);
    file_put_contents($this->directory . '/index.html', 'different');

    $this->expectException(\RuntimeException::class);
    $writer->write($this->artifact(), $this->directory);
  }

  /**
   * Creates a test artifact.
   *
   * @param array<int, string> $deletedPaths
   *   Deleted paths.
   */
  private function artifact(array $deletedPaths = []): StaticArtifact {
    return new StaticArtifact(
      'recipe',
      'https://example.com/recipes/recipe/',
      [
        'index.html' => '<h1>Home</h1>',
        'recipes/recipe/index.html' => '<h1>Recipe</h1>',
      ],
      str_repeat('a', 64),
      $deletedPaths,
    );
  }

  /**
   * Deletes a test directory tree.
   */
  private function deleteTree(string $directory): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      if ($item->isDir()) {
        rmdir($item->getPathname());
      }
      else {
        unlink($item->getPathname());
      }
    }
    rmdir($directory);
  }

}
