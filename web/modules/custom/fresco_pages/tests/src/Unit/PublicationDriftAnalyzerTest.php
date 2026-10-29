<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\PublicationDriftAnalyzer;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests managed Git tree drift classification.
 */
#[Group('fresco_pages')]
final class PublicationDriftAnalyzerTest extends UnitTestCase {

  /**
   * Confirms every drift category is distinct.
   */
  public function testAnalyze(): void {
    $analyzer = new PublicationDriftAnalyzer();
    $site = new StaticArtifact(
      'rice-and-peas',
      'https://example.com/recipes/rice-and-peas/',
      [
        'index.html' => '<h1>Fresco</h1>',
        'recipes/rice-and-peas/index.html' => '<h1>Rice</h1>',
        'assets/recipes/rice-and-peas.svg' => '<svg></svg>',
      ],
      str_repeat('a', 64),
      ['assets/recipes/archived.svg'],
    );
    $remote = [
      'index.html' => $analyzer->gitBlobSha('<h1>Fresco</h1>'),
      'recipes/rice-and-peas/index.html' => $analyzer->gitBlobSha('<h1>Old Rice</h1>'),
      'recipes/old-recipe/index.html' => $analyzer->gitBlobSha('old'),
      'assets/recipes/archived.svg' => $analyzer->gitBlobSha('<svg>old</svg>'),
      'humans.txt' => $analyzer->gitBlobSha('not managed'),
    ];

    $summary = $analyzer->analyze($site, $remote);

    self::assertSame(1, $summary['remote_missing_count']);
    self::assertSame(['assets/recipes/rice-and-peas.svg'], $summary['remote_missing_sample']);
    self::assertSame(1, $summary['remote_changed_count']);
    self::assertSame(['recipes/rice-and-peas/index.html'], $summary['remote_changed_sample']);
    self::assertSame(1, $summary['remote_unexpected_count']);
    self::assertSame(['recipes/old-recipe/index.html'], $summary['remote_unexpected_sample']);
    self::assertSame(1, $summary['remote_deleted_present_count']);
    self::assertSame(['assets/recipes/archived.svg'], $summary['remote_deleted_present_sample']);
  }

}
