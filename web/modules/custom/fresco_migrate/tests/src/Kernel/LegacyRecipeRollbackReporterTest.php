<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_migrate\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests read-only rollback rehearsal reporting for migrated recipes.
 */
#[Group('fresco_migrate')]
#[RunTestsInSeparateProcesses]
final class LegacyRecipeRollbackReporterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'fresco_migrate',
    'fresco_recipe',
    'migrate',
    'node',
    'field',
    'filter',
    'text',
    'user',
    'media',
    'file',
    'image',
    'options',
    'taxonomy',
    'workflows',
    'content_moderation',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig('fresco_recipe');
    require_once DRUPAL_ROOT . '/modules/custom/fresco_recipe/fresco_recipe.install';
    \fresco_recipe_install();
  }

  /**
   * Confirms imported recipes are rollback-ready when provenance is intact.
   */
  public function testReadyForRollbackWhenProvenanceIsIntact(): void {
    Node::create([
      'type' => 'recipe',
      'title' => 'Chickpea Stew',
      'status' => 1,
      'field_recipe_description' => 'A savory vegan stew.',
      'field_servings' => 4,
      'field_prep_minutes' => 15,
      'field_cook_minutes' => 30,
      'field_cuisine' => 'Caribbean',
      'field_category' => 'Vegan',
      'field_ingredients' => [['value' => '2 cups chickpeas']],
      'field_instructions' => [['value' => 'Simmer until tender.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => json_encode(['legacy_id' => 7, 'legacy_slug' => 'chickpea-stew']),
      'field_pages_url' => 'https://iosdevelop.github.io/fresco-recipes/recipes/chickpea-stew/',
      'field_legacy_id' => 7,
      'field_hero_image' => [],
    ])->save();

    $report = \Drupal::service('fresco_migrate.legacy_recipe_rollback_reporter')->report();

    self::assertTrue($report['ready_for_rollback']);
    self::assertSame(0, $report['missing_provenance_count']);
    self::assertSame(0, $report['missing_pages_url_count']);
  }

  /**
   * Confirms missing provenance prevents rollback readiness.
   */
  public function testRollbackFailsWhenProvenanceIsMissing(): void {
    Node::create([
      'type' => 'recipe',
      'title' => 'Chickpea Stew',
      'status' => 1,
      'field_recipe_description' => 'A savory vegan stew.',
      'field_servings' => 4,
      'field_prep_minutes' => 15,
      'field_cook_minutes' => 30,
      'field_cuisine' => 'Caribbean',
      'field_category' => 'Vegan',
      'field_ingredients' => [['value' => '2 cups chickpeas']],
      'field_instructions' => [['value' => 'Simmer until tender.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => '{}',
      'field_pages_url' => '',
      'field_legacy_id' => 7,
      'field_hero_image' => [],
    ])->save();

    $report = \Drupal::service('fresco_migrate.legacy_recipe_rollback_reporter')->report();

    self::assertFalse($report['ready_for_rollback']);
    self::assertSame(1, $report['missing_provenance_count']);
    self::assertSame(0, $report['missing_pages_url_count']);
  }

  /**
   * Confirms published recipes need pages URLs.
   */
  public function testPublishedRecipesRequirePagesUrl(): void {
    Node::create([
      'type' => 'recipe',
      'title' => 'Chickpea Stew',
      'status' => 1,
      'field_recipe_description' => 'A savory vegan stew.',
      'field_servings' => 4,
      'field_prep_minutes' => 15,
      'field_cook_minutes' => 30,
      'field_cuisine' => 'Caribbean',
      'field_category' => 'Vegan',
      'field_ingredients' => [['value' => '2 cups chickpeas']],
      'field_instructions' => [['value' => 'Simmer until tender.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => json_encode([
        'legacy_id' => 7,
        'legacy_slug' => 'chickpea-stew',
        'published_at' => '2026-06-01T00:00:00+00:00',
      ]),
      'field_pages_url' => '',
      'field_legacy_id' => 7,
      'field_hero_image' => [],
    ])->save();

    $report = \Drupal::service('fresco_migrate.legacy_recipe_rollback_reporter')->report();

    self::assertFalse($report['ready_for_rollback']);
    self::assertSame(0, $report['missing_provenance_count']);
    self::assertSame(1, $report['missing_pages_url_count']);
  }

}
