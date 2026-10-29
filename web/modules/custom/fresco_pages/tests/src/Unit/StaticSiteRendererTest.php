<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\StaticSiteRenderer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests deterministic aggregate site files and redirects.
 */
#[Group('fresco_pages')]
final class StaticSiteRendererTest extends UnitTestCase {

  /**
   * Confirms index, feed, sitemap, manifest, and redirect behavior.
   */
  public function testAggregateFiles(): void {
    $renderer = new StaticSiteRenderer();
    $recipes = [
      $this->recipe(2, 'zesty-stew', 'Zesty <Stew>'),
      $this->recipe(1, 'ackee-bowl', 'Ackee Bowl'),
    ];
    $redirects = [
      'old-stew' => 'https://example.com/recipes/zesty-stew/',
      'ackee-bowl' => 'https://example.com/recipes/ackee-bowl/',
    ];
    $files = $renderer->render($recipes, $redirects, 'https://example.com');

    self::assertArrayHasKey('.nojekyll', $files);
    self::assertArrayHasKey('recipes.json', $files);
    self::assertArrayNotHasKey('sitemap.xml', $files);
    self::assertArrayHasKey('fresco-manifest.json', $files);
    self::assertArrayHasKey('recipes/old-stew/index.html', $files);
    self::assertArrayNotHasKey('recipes/ackee-bowl/index.html', $files);
    self::assertStringContainsString('Zesty &lt;Stew&gt;', $files['index.html']);
    self::assertStringContainsString('rel="canonical" href="https://example.com/recipes/zesty-stew/"', $files['recipes/old-stew/index.html']);
    self::assertLessThan(
      strpos($files['index.html'], 'Zesty &lt;Stew&gt;'),
      strpos($files['index.html'], 'Ackee Bowl'),
    );

    $feed = json_decode($files['recipes.json'], TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('Ackee Bowl', $feed[0]['title']);
    $manifest = json_decode($files['fresco-manifest.json'], TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(1, $manifest['recipes'][0]['recipe_id']);
    self::assertSame($files, $renderer->render($recipes, $redirects, 'https://example.com'));
  }

  /**
   * Confirms protection files keep search, AI crawlers, and internals out.
   */
  public function testProtectionFiles(): void {
    $renderer = new StaticSiteRenderer();
    $files = $renderer->render(
      [$this->recipe(1, 'zesty-stew', 'Zesty Stew')],
      [
        'old-stew' => 'https://recipes.example.com/recipes/zesty-stew/',
        'zesty-stew' => 'https://recipes.example.com/recipes/zesty-stew/',
      ],
      'https://recipes.example.com',
    );

    self::assertStringContainsString("User-agent: GPTBot\nDisallow: /", $files['robots.txt']);
    self::assertStringContainsString("User-agent: ClaudeBot\nDisallow: /", $files['robots.txt']);
    self::assertStringEndsWith("User-agent: *\nContent-Signal: search=no, ai-train=no, ai-input=no\nDisallow: /\n", $files['robots.txt']);
    self::assertStringNotContainsString('Sitemap:', $files['robots.txt']);
    self::assertSame("/recipes/old-stew/ /recipes/zesty-stew/ 301\n", $files['_redirects']);
    self::assertStringContainsString("/*\n  X-Robots-Tag: noindex, nofollow, noai, noimageai\n", $files['_headers']);
    self::assertStringContainsString("fresco-manifest.json\n", $files['.assetsignore']);
    self::assertStringContainsString('<meta name="robots" content="noindex, nofollow, noai, noimageai">', $files['index.html']);
  }

  /**
   * Confirms imported ingredient-count slugs get clean compatibility URLs.
   */
  public function testIngredientCountSlugGetsCleanRedirect(): void {
    $renderer = new StaticSiteRenderer();
    $recipes = [
      $this->recipe(1, '1-eggplant-thai-stew', '1 Eggplant Thai Stew'),
      $this->recipe(2, '7-layer-dip', '7 Layer Dip'),
    ];

    $files = $renderer->render($recipes, [], 'https://example.com');

    self::assertArrayHasKey('recipes/eggplant-thai-stew/index.html', $files);
    self::assertArrayNotHasKey('recipes/layer-dip/index.html', $files);
    self::assertStringContainsString(
      'rel="canonical" href="https://example.com/recipes/1-eggplant-thai-stew/"',
      $files['recipes/eggplant-thai-stew/index.html'],
    );
    $manifest = json_decode($files['fresco-manifest.json'], TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(
      'https://example.com/recipes/1-eggplant-thai-stew/',
      $manifest['redirects']['eggplant-thai-stew'],
    );
  }

  /**
   * Creates a complete aggregate recipe descriptor.
   *
   * @return array<string, int|string>
   *   Recipe descriptor.
   */
  private function recipe(int $id, string $slug, string $title): array {
    return [
      'recipe_id' => $id,
      'revision_id' => $id * 10,
      'slug' => $slug,
      'title' => $title,
      'description' => 'A vegan recipe.',
      'cuisine' => 'Caribbean',
      'servings' => 4,
      'content_hash' => str_repeat((string) $id, 64),
      'public_url' => 'https://example.com/recipes/' . $slug . '/',
      'image_url' => 'https://example.com/assets/recipes/' . $slug . '.svg',
    ];
  }

}
