<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Component\Utility\Html;

/**
 * Renders deterministic aggregate files and historical-slug redirects.
 */
final class StaticSiteRenderer {

  /**
   * Robots directive for every page: no search listings and no AI use.
   */
  public const ROBOTS = 'noindex, nofollow, noai, noimageai';

  /**
   * Crawlers that collect pages for AI training or AI answers.
   *
   * Robots.txt already disallows every crawler; these are named explicitly so
   * the AI rules survive if search indexing is allowed again.
   */
  public const AI_CRAWLERS = [
    'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User',
    'Claude-SearchBot', 'anthropic-ai', 'CCBot', 'Google-Extended',
    'Applebot-Extended', 'Bytespider', 'Meta-ExternalAgent',
    'Meta-ExternalFetcher', 'PerplexityBot', 'Perplexity-User', 'Amazonbot',
    'cohere-ai', 'Diffbot', 'ImagesiftBot', 'Omgilibot', 'Timpibot', 'YouBot',
    'DuckAssistBot', 'MistralAI-User',
  ];

  /**
   * Response headers for Cloudflare static assets.
   *
   * Robots headers are advisory; Cloudflare's bot rules do the blocking.
   */
  private const HEADERS = <<<'HEADERS'
/*
  X-Robots-Tag: noindex, nofollow, noai, noimageai
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: camera=(), microphone=(), geolocation=()
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'
/family/*
  Cache-Control: private, no-store

HEADERS;

  /**
   * Repository files Cloudflare must never serve.
   */
  private const ASSETS_IGNORE = "fresco-manifest.json\n.nojekyll\nwrangler.jsonc\nREADME.md\n.git\n";

  /**
   * Renders root site files for the latest recipe publications.
   *
   * @param array<int, array<string, int|string>> $recipes
   *   Latest published recipe descriptors.
   * @param array<string, string> $redirects
   *   Historical slugs keyed to canonical destination URLs.
   * @param string $siteUrl
   *   Canonical Pages site URL.
   *
   * @return array<string, string>
   *   Repository paths keyed to file contents.
   */
  public function render(array $recipes, array $redirects, string $siteUrl): array {
    $siteUrl = rtrim($siteUrl, '/');
    usort($recipes, static fn (array $left, array $right): int => strcasecmp(
      (string) $left['title'],
      (string) $right['title'],
    ));
    $allRecipes = $recipes;
    $familyRecipes = array_values(array_filter($recipes, static fn (array $recipe): bool => !empty($recipe['family'])));
    $recipes = array_values(array_filter($recipes, static fn (array $recipe): bool => empty($recipe['family'])));
    $currentSlugs = array_fill_keys(array_map(
      static fn (array $recipe): string => (string) $recipe['slug'],
      $recipes,
    ), TRUE);
    foreach ($recipes as $recipe) {
      foreach ($this->slugAliases($recipe) as $alias) {
        if (!isset($currentSlugs[$alias]) && !isset($redirects[$alias])) {
          $redirects[$alias] = (string) $recipe['public_url'];
        }
      }
    }
    ksort($redirects);
    $files = [
      '.assetsignore' => self::ASSETS_IGNORE,
      '.nojekyll' => '',
      '_headers' => self::HEADERS,
      '_redirects' => $this->redirectRules($redirects, $currentSlugs, $siteUrl),
      'family/index.html' => $this->index($familyRecipes, $siteUrl, TRUE),
      'index.html' => $this->index($recipes, $siteUrl, FALSE, $familyRecipes !== []),
      'robots.txt' => $this->robots(),
      'recipes.json' => $this->feed($recipes),
      'fresco-manifest.json' => $this->manifest($allRecipes, $redirects),
    ];
    foreach ($redirects as $slug => $destination) {
      if (!isset($currentSlugs[$slug])) {
        $files['recipes/' . $slug . '/index.html'] = $this->redirect($destination);
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Renders the public recipe-card index.
   *
   * @param array<int, array<string, int|string>> $recipes
   *   Recipe descriptors.
   * @param string $siteUrl
   *   Canonical Pages site URL.
   * @param bool $family
   *   Whether this is the signed-in family index under family/.
   * @param bool $linkFamily
   *   Whether the public index links to the family index.
   */
  private function index(array $recipes, string $siteUrl, bool $family = FALSE, bool $linkFamily = FALSE): string {
    $cards = '';
    foreach ($recipes as $recipe) {
      $slug = Html::escape((string) $recipe['slug']);
      $title = Html::escape((string) $recipe['title']);
      $description = Html::escape((string) $recipe['description']);
      $cuisine = Html::escape((string) $recipe['cuisine']);
      $servings = (int) $recipe['servings'];
      $cards .= '<article class="card"><a href="recipes/' . $slug . '/"><img src="assets/recipes/' . $slug
        . '.svg" alt="" width="1200" height="800" loading="lazy"></a><h2><a href="recipes/' . $slug
        . '/">' . $title . '</a></h2><p>' . $description . '</p><p class="meta">' . $cuisine . ' · '
        . $servings . ' servings</p></article>';
    }
    if ($cards === '') {
      $cards = '<p>No published recipes yet.</p>';
    }
    $canonical = Html::escape($siteUrl . ($family ? '/family/' : '/'));
    $title = $family ? 'Dresco family recipes' : 'Dresco recipes';
    $robots = self::ROBOTS;
    $heading = $family ? 'Family recipes' : 'Published vegan recipes';
    $intro = $family
      ? 'Recipes shared only with signed-in family members.'
      : 'Open a recipe and use its public URL with compatible recipe importers.'
        . ($linkFamily ? ' Family members can also open the <a href="family/">family recipes</a>.' : '');
    $css = ':root{color-scheme:dark;--bg:#101414;--surface:#17201e;--text:#f4f7f1;--muted:#b8c7bc;--line:#33413c;--accent:#61d394;--gold:#f4c95d}'
      . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:1rem/1.6 system-ui,sans-serif}main{width:min(70rem,calc(100% - 2rem));margin:auto;padding:3rem 0}'
      . 'a{color:var(--accent)}h1{font-size:clamp(2.4rem,7vw,5rem);line-height:1;margin:.3rem 0}.eyebrow{color:var(--gold);font-weight:800;text-transform:uppercase}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem;margin-top:2rem}'
      . '.card{padding:1rem;border:1px solid var(--line);border-radius:.8rem;background:var(--surface)}.card img{display:block;width:calc(100% + 2rem);max-width:none;height:auto;aspect-ratio:3/2;object-fit:cover;margin:-1rem -1rem 1rem;border-radius:.8rem .8rem 0 0}.meta{color:var(--muted)}';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>' . $title . '</title><meta name="description" content="Published vegan recipes from Dresco.">'
      . '<meta name="robots" content="' . $robots . '">'
      . '<link rel="canonical" href="' . $canonical . '"><style>' . $css . '</style></head><body><main>'
      . '<header><p class="eyebrow">Dresco recipe links</p><h1>' . $heading . '</h1>'
      . '<p>' . $intro . '</p></header><section class="grid">'
      . $cards . '</section></main></body></html>';
  }

  /**
   * Renders robots.txt that keeps search engines and AI crawlers out.
   */
  private function robots(): string {
    $rules = '';
    foreach (self::AI_CRAWLERS as $crawler) {
      $rules .= 'User-agent: ' . $crawler . "\nDisallow: /\n\n";
    }
    return $rules . "User-agent: *\nContent-Signal: search=no, ai-train=no, ai-input=no\nDisallow: /\n";
  }

  /**
   * Renders permanent redirects for historical slugs.
   *
   * @param array<string, string> $redirects
   *   Historical slugs keyed to canonical destination URLs.
   * @param array<string, bool> $currentSlugs
   *   Slugs of live recipes, which never redirect.
   * @param string $siteUrl
   *   Canonical site URL, stripped so rules work on any host.
   */
  private function redirectRules(array $redirects, array $currentSlugs, string $siteUrl): string {
    $rules = '';
    foreach ($redirects as $slug => $destination) {
      if (isset($currentSlugs[$slug])) {
        continue;
      }
      $target = str_starts_with($destination, $siteUrl . '/') ? substr($destination, strlen($siteUrl)) : $destination;
      $rules .= '/recipes/' . $slug . '/ ' . $target . " 301\n";
    }
    return $rules;
  }

  /**
   * Renders the machine-readable recipe feed.
   *
   * @param array<int, array<string, int|string>> $recipes
   *   Recipe descriptors.
   */
  private function feed(array $recipes): string {
    $feed = array_map(static fn (array $recipe): array => [
      'title' => $recipe['title'],
      'url' => $recipe['public_url'],
      'description' => $recipe['description'],
      'image' => $recipe['image_url'],
      'cuisine' => $recipe['cuisine'],
      'servings' => (int) $recipe['servings'],
    ], $recipes);
    return json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
  }

  /**
   * Renders the reconciliation manifest.
   *
   * @param array<int, array<string, int|string>> $recipes
   *   Recipe descriptors.
   * @param array<string, string> $redirects
   *   Historical redirects.
   */
  private function manifest(array $recipes, array $redirects): string {
    $entries = array_map(static fn (array $recipe): array => [
      'recipe_id' => (int) $recipe['recipe_id'],
      'revision_id' => (int) $recipe['revision_id'],
      'slug' => $recipe['slug'],
      'content_hash' => $recipe['content_hash'],
      'public_url' => $recipe['public_url'],
    ], $recipes);
    usort($entries, static fn (array $left, array $right): int => $left['recipe_id'] <=> $right['recipe_id']);
    return json_encode([
      'schema_version' => '1.0',
      'recipes' => $entries,
      'redirects' => $redirects,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
  }

  /**
   * Returns compatibility aliases for imported ingredient-count titles.
   *
   * @param array<string, int|string> $recipe
   *   Recipe descriptor.
   *
   * @return array<int, string>
   *   Safe redirect slugs.
   */
  private function slugAliases(array $recipe): array {
    $slug = (string) $recipe['slug'];
    if (preg_match('/^1-([a-z0-9]+(?:-[a-z0-9]+)+)$/', $slug, $matches) !== 1) {
      return [];
    }
    if (!$this->titleStartsWithIngredientCount((string) $recipe['title'])) {
      return [];
    }
    return [$matches[1]];
  }

  /**
   * Detects titles imported from an ingredient line such as "1 Eggplant ...".
   */
  private function titleStartsWithIngredientCount(string $title): bool {
    $normalized = trim((string) preg_replace('/\s+/', ' ', $title));
    if (preg_match('/^(?:1|one)\s+([a-z][a-z\'-]*)\b/i', $normalized, $matches) !== 1) {
      return FALSE;
    }
    $firstWord = strtolower($matches[1]);
    if (in_array($firstWord, ['bowl', 'pan', 'pot', 'sheet', 'skillet', 'tray'], TRUE)) {
      return FALSE;
    }
    $wordCount = preg_match_all('/[a-z][a-z\'-]*/i', $normalized, $words);
    return $wordCount !== FALSE && $wordCount >= 3;
  }

  /**
   * Renders a static canonical redirect without JavaScript.
   */
  private function redirect(string $destination): string {
    $url = Html::escape($destination);
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
      . '<meta http-equiv="refresh" content="0;url=' . $url . '"><link rel="canonical" href="' . $url . '">'
      . '<title>Recipe moved</title></head><body><p>This recipe moved to <a href="' . $url . '">' . $url
      . '</a>.</p></body></html>';
  }

}
