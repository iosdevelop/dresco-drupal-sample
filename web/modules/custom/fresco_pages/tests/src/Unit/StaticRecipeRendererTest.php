<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\StaticRecipeRenderer;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests portable, deterministic static recipe artifacts.
 */
#[Group('fresco_pages')]
final class StaticRecipeRendererTest extends UnitTestCase {

  /**
   * Confirms canonical metadata, artwork, escaping, and stable hashing.
   */
  public function testRender(): void {
    $renderer = new StaticRecipeRenderer(new RecipeSvgBuilder());
    $recipe = new RecipeContract(
      title: 'Rice & <Peas>',
      description: 'A bright & savory bowl.',
      servings: 4,
      prepTimeMinutes: 10,
      cookTimeMinutes: 25,
      cuisine: 'Caribbean',
      category: 'Vegan',
      ingredients: [['original_text' => '1 cup rice'], ['original_text' => '1 cup kidney beans']],
      instructions: [['text' => 'Simmer until tender.']],
      applianceSettings: [],
      nutrition: [],
    );
    $artifact = $renderer->render($recipe, 'rice-and-peas', 'https://example.github.io/fresco-recipes');
    $html = $artifact->files['recipes/rice-and-peas/index.html'];

    self::assertSame('https://example.github.io/fresco-recipes/recipes/rice-and-peas/', $artifact->publicUrl);
    self::assertStringContainsString('Rice &amp; &lt;Peas&gt;', $html);
    self::assertStringContainsString('name="robots" content="noindex, nofollow, noai, noimageai"', $html);
    self::assertStringContainsString('https://example.github.io/fresco-recipes/assets/recipes/rice-and-peas.svg', $html);
    self::assertArrayHasKey('assets/recipes/rice-and-peas.svg', $artifact->files);
    self::assertSame($artifact->contentHash, $renderer->render($recipe, 'rice-and-peas', 'https://example.github.io/fresco-recipes')->contentHash);
    self::assertSame($artifact->contentHash, StaticArtifact::fromJson($artifact->toJson())->contentHash);
  }

  /**
   * Confirms family recipes publish under family/ and stay out of search.
   */
  public function testFamilyRender(): void {
    $renderer = new StaticRecipeRenderer(new RecipeSvgBuilder());
    $recipe = new RecipeContract(
      title: 'Grandma Rundown',
      description: 'A family rundown.',
      servings: 4,
      prepTimeMinutes: 10,
      cookTimeMinutes: 25,
      cuisine: 'Caribbean',
      category: 'Vegan',
      ingredients: [['original_text' => '1 cup coconut milk']],
      instructions: [['text' => 'Simmer the coconut milk until thick.']],
      applianceSettings: [],
      nutrition: [],
    );
    $artifact = $renderer->render($recipe, 'grandma-rundown', 'https://recipes.example.com', TRUE);
    $html = $artifact->files['family/recipes/grandma-rundown/index.html'];

    self::assertSame('https://recipes.example.com/family/recipes/grandma-rundown/', $artifact->publicUrl);
    self::assertSame(['family/assets/recipes/grandma-rundown.svg', 'family/recipes/grandma-rundown/index.html'], array_keys($artifact->files));
    self::assertStringContainsString('content="noindex, nofollow, noai, noimageai"', $html);
    self::assertStringContainsString('https://recipes.example.com/family/assets/recipes/grandma-rundown.svg', $html);
    self::assertStringContainsString('All family recipes', $html);
  }

  /**
   * Confirms archive artifacts keep the URL but remove recipe affordances.
   */
  public function testArchiveRender(): void {
    $renderer = new StaticRecipeRenderer(new RecipeSvgBuilder());
    $artifact = $renderer->archive('Rice & <Peas>', 'rice-and-peas', 'https://example.github.io/fresco-recipes');
    $html = $artifact->files['recipes/rice-and-peas/index.html'];

    self::assertSame('https://example.github.io/fresco-recipes/recipes/rice-and-peas/', $artifact->publicUrl);
    self::assertSame(['assets/recipes/rice-and-peas.svg'], $artifact->deletedPaths);
    self::assertStringContainsString('name="robots" content="noindex"', $html);
    self::assertStringContainsString('Rice &amp; &lt;Peas&gt;', $html);
    self::assertStringNotContainsString('application/ld+json', $html);
    self::assertSame($artifact->contentHash, StaticArtifact::fromJson($artifact->toJson())->contentHash);
  }

  /**
   * Confirms unsafe repository paths are rejected during restoration.
   */
  public function testUnsafeStoredPathIsRejected(): void {
    $this->expectException(\UnexpectedValueException::class);
    StaticArtifact::fromJson(json_encode([
      'slug' => 'recipe',
      'public_url' => 'https://example.com/recipes/recipe/',
      'files' => ['../index.html' => 'unsafe'],
      'content_hash' => str_repeat('a', 64),
    ], JSON_THROW_ON_ERROR));
  }

}
