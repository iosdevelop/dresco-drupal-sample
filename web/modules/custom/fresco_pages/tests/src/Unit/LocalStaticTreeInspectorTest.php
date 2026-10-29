<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\LocalStaticTreeInspector;
use Drupal\fresco_pages\Service\PublicationDriftAnalyzer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests local static directory inspection.
 */
#[Group('fresco_pages')]
final class LocalStaticTreeInspectorTest extends UnitTestCase {

  /**
   * Temporary directory.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/fresco-tree-inspect-' . bin2hex(random_bytes(6));
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
   * Confirms nested local files are reported with Git blob SHAs.
   */
  public function testBlobShas(): void {
    $analyzer = new PublicationDriftAnalyzer();
    mkdir($this->directory . '/recipes/rice', 0775, TRUE);
    file_put_contents($this->directory . '/index.html', 'home');
    file_put_contents($this->directory . '/recipes/rice/index.html', 'recipe');

    $files = (new LocalStaticTreeInspector($analyzer))->blobShas($this->directory);

    self::assertSame([
      'index.html' => $analyzer->gitBlobSha('home'),
      'recipes/rice/index.html' => $analyzer->gitBlobSha('recipe'),
    ], $files);
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
