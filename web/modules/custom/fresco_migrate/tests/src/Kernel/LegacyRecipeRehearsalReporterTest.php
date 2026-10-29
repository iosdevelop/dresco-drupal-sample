<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_migrate\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests read-only cutover rehearsal reporting for legacy migrations.
 */
#[Group('fresco_migrate')]
#[RunTestsInSeparateProcesses]
final class LegacyRecipeRehearsalReporterTest extends KernelTestBase {

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
   * Temporary SQLite database path.
   */
  private string $databasePath;

  /**
   * Temporary image path.
   */
  private string $imagePath;

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

    $this->databasePath = tempnam(sys_get_temp_dir(), 'fresco-legacy-rehearsal-') ?: throw new \RuntimeException('Unable to create temporary database path.');
    unlink($this->databasePath);
    $this->imagePath = tempnam(sys_get_temp_dir(), 'fresco-legacy-rehearsal-png-') ?: throw new \RuntimeException('Unable to create temporary image path.');
    file_put_contents($this->imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3ZqWQAAAAASUVORK5CYII='));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ([$this->databasePath, $this->imagePath] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Confirms a full rehearsal report turns green when image coverage matches.
   */
  public function testReadyForCutoverWhenParityAndImagesMatch(): void {
    $this->createLegacyDatabase([
      [
        'id' => 7,
        'title' => 'Chickpea Stew',
        'description' => 'A savory vegan stew.',
        'servings' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 30,
        'cuisine' => 'Caribbean',
        'category' => 'Vegan',
        'status' => 'published',
        'slug' => 'chickpea-stew',
        'image_url' => 'file://' . $this->imagePath,
        'ingredients' => ['2 cups chickpeas', '2 cloves garlic'],
        'instructions' => ['Simmer until tender.'],
        'appliance_settings' => [],
      ],
    ]);

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
      'field_ingredients' => [['value' => '2 cups chickpeas'], ['value' => '2 cloves garlic']],
      'field_instructions' => [['value' => 'Simmer until tender.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => '{}',
      'field_pages_url' => 'https://iosdevelop.github.io/fresco-recipes/recipes/chickpea-stew/',
      'field_legacy_id' => 7,
      'field_hero_image' => [],
    ])->save();

    \Drupal::service('fresco_migrate.legacy_recipe_image_migrator')->migrate($this->databasePath);

    $report = \Drupal::service('fresco_migrate.legacy_recipe_rehearsal_reporter')->report($this->databasePath);

    self::assertTrue($report['ready_for_cutover']);
    self::assertTrue($report['parity']['parity']);
    self::assertSame(1, $report['image_coverage']['legacy_with_images']);
    self::assertSame(0, $report['image_coverage']['missing_media_count']);
  }

  /**
   * Confirms missing imported media keeps the rehearsal report red.
   */
  public function testRehearsalFailsWhenImageCoverageMissing(): void {
    $this->createLegacyDatabase([
      [
        'id' => 7,
        'title' => 'Chickpea Stew',
        'description' => 'A savory vegan stew.',
        'servings' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 30,
        'cuisine' => 'Caribbean',
        'category' => 'Vegan',
        'status' => 'published',
        'slug' => 'chickpea-stew',
        'image_url' => 'file://' . $this->imagePath,
        'ingredients' => ['2 cups chickpeas', '2 cloves garlic'],
        'instructions' => ['Simmer until tender.'],
        'appliance_settings' => [],
      ],
    ]);

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
      'field_ingredients' => [['value' => '2 cups chickpeas'], ['value' => '2 cloves garlic']],
      'field_instructions' => [['value' => 'Simmer until tender.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => '{}',
      'field_pages_url' => 'https://iosdevelop.github.io/fresco-recipes/recipes/chickpea-stew/',
      'field_legacy_id' => 7,
      'field_hero_image' => [],
    ])->save();

    $report = \Drupal::service('fresco_migrate.legacy_recipe_rehearsal_reporter')->report($this->databasePath);

    self::assertFalse($report['ready_for_cutover']);
    self::assertSame(1, $report['image_coverage']['missing_media_count']);
  }

  /**
   * Creates a temporary legacy SQLite database with recipe rows.
   *
   * @param array<int, array<string, mixed>> $recipes
   *   Legacy recipe rows.
   */
  private function createLegacyDatabase(array $recipes): void {
    $database = new \PDO('sqlite:' . $this->databasePath, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $database->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY, title TEXT, description TEXT, servings INTEGER, prep_time_minutes INTEGER, cook_time_minutes INTEGER, cuisine TEXT, category TEXT, status TEXT, slug TEXT, image_url TEXT)');
    $database->exec('CREATE TABLE recipe_ingredients (recipe_id INTEGER, position INTEGER, ingredient TEXT)');
    $database->exec('CREATE TABLE recipe_steps (recipe_id INTEGER, position INTEGER, instruction TEXT)');
    $database->exec('CREATE TABLE appliance_settings (recipe_id INTEGER, appliance_name TEXT, mode TEXT, setting_key TEXT, setting_value TEXT, time_minutes INTEGER, notes TEXT, step_number INTEGER)');

    foreach ($recipes as $recipe) {
      $database->prepare('INSERT INTO recipes (id, title, description, servings, prep_time_minutes, cook_time_minutes, cuisine, category, status, slug, image_url) VALUES (:id, :title, :description, :servings, :prep, :cook, :cuisine, :category, :status, :slug, :image_url)')
        ->execute([
          'id' => $recipe['id'],
          'title' => $recipe['title'],
          'description' => $recipe['description'],
          'servings' => $recipe['servings'],
          'prep' => $recipe['prep_time_minutes'],
          'cook' => $recipe['cook_time_minutes'],
          'cuisine' => $recipe['cuisine'],
          'category' => $recipe['category'],
          'status' => $recipe['status'],
          'slug' => $recipe['slug'],
          'image_url' => $recipe['image_url'],
        ]);

      foreach ($recipe['ingredients'] as $position => $ingredient) {
        $database->prepare('INSERT INTO recipe_ingredients (recipe_id, position, ingredient) VALUES (:recipe_id, :position, :ingredient)')
          ->execute([
            'recipe_id' => $recipe['id'],
            'position' => $position + 1,
            'ingredient' => $ingredient,
          ]);
      }

      foreach ($recipe['instructions'] as $position => $instruction) {
        $database->prepare('INSERT INTO recipe_steps (recipe_id, position, instruction) VALUES (:recipe_id, :position, :instruction)')
          ->execute([
            'recipe_id' => $recipe['id'],
            'position' => $position + 1,
            'instruction' => $instruction,
          ]);
      }

      foreach ($recipe['appliance_settings'] as $position => $setting) {
        $database->prepare('INSERT INTO appliance_settings (recipe_id, appliance_name, mode, setting_key, setting_value, time_minutes, notes, step_number) VALUES (:recipe_id, :appliance_name, :mode, :setting_key, :setting_value, :time_minutes, :notes, :step_number)')
          ->execute([
            'recipe_id' => $recipe['id'],
            'appliance_name' => (string) ($setting['appliance_name'] ?? ''),
            'mode' => (string) ($setting['mode'] ?? ''),
            'setting_key' => (string) ($setting['setting_key'] ?? ''),
            'setting_value' => (string) ($setting['setting_value'] ?? ''),
            'time_minutes' => $setting['time_minutes'] ?? NULL,
            'notes' => (string) ($setting['notes'] ?? ''),
            'step_number' => $position + 1,
          ]);
      }
    }
  }

}
