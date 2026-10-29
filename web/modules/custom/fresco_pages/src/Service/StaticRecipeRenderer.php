<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Component\Utility\Html;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Renders complete, portable static recipe artifacts.
 */
final class StaticRecipeRenderer {

  public function __construct(
    private readonly RecipeSvgBuilder $svgBuilder,
  ) {}

  /**
   * Renders a validated recipe contract to immutable repository files.
   *
   * Family recipes live under family/, which Cloudflare Access protects.
   */
  public function render(RecipeContract $recipe, string $slug, string $siteUrl, bool $family = FALSE): StaticArtifact {
    $slug = trim($slug, '/');
    if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
      throw new \InvalidArgumentException('A safe recipe slug is required.');
    }
    $siteUrl = rtrim($siteUrl, '/');
    if (filter_var($siteUrl, FILTER_VALIDATE_URL) === FALSE) {
      throw new \InvalidArgumentException('A valid Pages site URL is required.');
    }
    $prefix = $family ? 'family/' : '';
    $publicUrl = $siteUrl . '/' . $prefix . 'recipes/' . $slug . '/';
    $imagePath = 'assets/recipes/' . $slug . '.svg';
    $ingredients = array_map(
      static fn (array $ingredient): string => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
      $recipe->ingredients,
    );
    $instructions = array_map(
      static fn (array $instruction): string => (string) ($instruction['text'] ?? ''),
      $recipe->instructions,
    );
    $files = [
      $prefix . 'recipes/' . $slug . '/index.html' => $this->html(
        $recipe,
        $publicUrl,
        '../../' . $imagePath,
        $siteUrl . '/' . $prefix . $imagePath,
        $ingredients,
        $instructions,
        $family,
      ),
      $prefix . $imagePath => $this->svgBuilder->build($recipe->title, $ingredients),
    ];
    ksort($files);
    $hash = hash('sha256', json_encode([
      'files' => $files,
      'deleted_paths' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return new StaticArtifact($slug, $publicUrl, $files, $hash);
  }

  /**
   * Renders a non-recipe tombstone for a public URL removed by editors.
   */
  public function archive(string $title, string $slug, string $siteUrl, bool $family = FALSE): StaticArtifact {
    $slug = trim($slug, '/');
    if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
      throw new \InvalidArgumentException('A safe recipe slug is required.');
    }
    $siteUrl = rtrim($siteUrl, '/');
    if (filter_var($siteUrl, FILTER_VALIDATE_URL) === FALSE) {
      throw new \InvalidArgumentException('A valid Pages site URL is required.');
    }
    $prefix = $family ? 'family/' : '';
    $publicUrl = $siteUrl . '/' . $prefix . 'recipes/' . $slug . '/';
    $safeTitle = Html::escape($title);
    $canonical = Html::escape($publicUrl);
    $html = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width,initial-scale=1"><link rel="canonical" href="{$canonical}">
<title>Recipe archived · Dresco</title><style>body{margin:0;background:#101414;color:#f4f7f1;font:1rem/1.6 system-ui,sans-serif}main{width:min(44rem,calc(100% - 2rem));margin:12vh auto}a{color:#61d394}.eyebrow{color:#f4c95d;font-weight:800;text-transform:uppercase}</style></head>
<body><main><p class="eyebrow">Dresco recipe archived</p><h1>{$safeTitle}</h1>
<p>This recipe is no longer publicly available from Dresco.</p><p><a href="../../">Browse current recipes</a></p></main></body></html>
HTML;
    $files = [
      $prefix . 'recipes/' . $slug . '/index.html' => $html,
    ];
    $deletedPaths = [$prefix . 'assets/recipes/' . $slug . '.svg'];
    $hash = hash('sha256', json_encode([
      'files' => $files,
      'deleted_paths' => $deletedPaths,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return new StaticArtifact($slug, $publicUrl, $files, $hash, $deletedPaths);
  }

  /**
   * Builds the standalone recipe page.
   *
   * @param \Drupal\fresco_recipe\Value\RecipeContract $recipe
   *   Validated recipe contract.
   * @param string $publicUrl
   *   Canonical public URL.
   * @param string $imageUrl
   *   Page-relative artwork URL.
   * @param string $absoluteImageUrl
   *   Canonical artwork URL.
   * @param array<int, string> $ingredients
   *   Display-ready ingredients.
   * @param array<int, string> $instructions
   *   Display-ready instructions.
   * @param bool $family
   *   Whether the page is a family-only recipe.
   */
  private function html(RecipeContract $recipe, string $publicUrl, string $imageUrl, string $absoluteImageUrl, array $ingredients, array $instructions, bool $family = FALSE): string {
    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'Recipe',
      'name' => $recipe->title,
      'description' => $recipe->description,
      'image' => $absoluteImageUrl,
      'url' => $publicUrl,
      'prepTime' => 'PT' . $recipe->prepTimeMinutes . 'M',
      'cookTime' => 'PT' . $recipe->cookTimeMinutes . 'M',
      'totalTime' => 'PT' . ($recipe->prepTimeMinutes + $recipe->cookTimeMinutes) . 'M',
      'recipeYield' => (string) $recipe->servings,
      'recipeCuisine' => $recipe->cuisine,
      'recipeCategory' => $recipe->category,
      'recipeIngredient' => $ingredients,
      'recipeInstructions' => array_map(
        static fn (string $instruction): array => ['@type' => 'HowToStep', 'text' => $instruction],
        $instructions,
      ),
    ];
    $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $ingredientItems = implode('', array_map(static fn (string $item): string => '<li>' . Html::escape($item) . '</li>', $ingredients));
    $instructionItems = implode('', array_map(static fn (string $item): string => '<li>' . Html::escape($item) . '</li>', $instructions));
    $title = Html::escape($recipe->title);
    $description = Html::escape($recipe->description);
    $canonical = Html::escape($publicUrl);
    $image = Html::escape($imageUrl);
    $absoluteImage = Html::escape($absoluteImageUrl);
    $cuisine = Html::escape($recipe->cuisine);
    $category = Html::escape($recipe->category);
    $css = $this->css();
    $robots = StaticSiteRenderer::ROBOTS;
    $back = $family ? 'All family recipes' : 'All recipes';

    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · Dresco</title><meta name="robots" content="{$robots}"><link rel="canonical" href="{$canonical}">
<meta name="description" content="{$description}"><meta property="og:type" content="article">
<meta property="og:title" content="{$title}"><meta property="og:description" content="{$description}">
<meta property="og:url" content="{$canonical}"><meta property="og:image" content="{$absoluteImage}"><style>{$css}</style>
<script type="application/ld+json">{$schemaJson}</script></head>
<body><main><p><a href="../../">← {$back}</a></p><article>
<header><div><p class="eyebrow">{$cuisine} · {$category}</p><h1>{$title}</h1><p>{$description}</p>
<p class="meta">Prep {$recipe->prepTimeMinutes} min · Cook {$recipe->cookTimeMinutes} min · Serves {$recipe->servings}</p></div>
<img src="{$image}" alt="" width="1200" height="800"></header>
<section><h2>Ingredients</h2><ul>{$ingredientItems}</ul></section>
<section><h2>Instructions</h2><ol>{$instructionItems}</ol></section>
</article></main></body></html>
HTML;
  }

  /**
   * Returns self-contained static page styling.
   */
  private function css(): string {
    return ':root{color-scheme:dark;--bg:#101414;--surface:#17201e;--text:#f4f7f1;--muted:#b8c7bc;--line:#33413c;--accent:#61d394;--gold:#f4c95d}'
      . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:1rem/1.6 system-ui,sans-serif}'
      . 'main{width:min(70rem,calc(100% - 2rem));margin:auto;padding:2rem 0 4rem}a{color:var(--accent)}header{display:flex;gap:2rem;align-items:flex-start;padding:2rem 0}'
      . 'h1{margin:.25rem 0;font-size:clamp(2.2rem,6vw,4.5rem);line-height:1.02}.eyebrow{color:var(--gold);font-weight:800;text-transform:uppercase}.meta{color:var(--muted)}'
      . 'header img{width:min(25rem,40vw);height:auto;border:1px solid var(--line);border-radius:1rem}section{margin:1rem 0;padding:1.25rem;border:1px solid var(--line);border-radius:.8rem;background:var(--surface)}'
      . 'li+li{margin-top:.45rem}@media(max-width:44rem){header{display:grid}header img{width:100%}}';
  }

}
