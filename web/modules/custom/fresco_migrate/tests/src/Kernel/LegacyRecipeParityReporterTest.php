<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_migrate\Kernel;

use Drupal\node\Entity\Node;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests read-only parity reporting for legacy migrations.
 */
#[Group('fresco_migrate')]
#[RunTestsInSeparateProcesses]
final class LegacyRecipeParityReporterTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig('fresco_recipe');
    require_once DRUPAL_ROOT . '/modules/custom/fresco_recipe/fresco_recipe.install';
    \fresco_recipe_install();
    $this->databasePath = tempnam(sys_get_temp_dir(), 'fresco-legacy-parity-') ?: throw new \RuntimeException('Unable to create temporary database path.');
    unlink($this->databasePath);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->databasePath) && is_file($this->databasePath)) {
      unlink($this->databasePath);
    }
    parent::tearDown();
  }

  /**
   * Confirms a matching import produces a clean parity report.
   */
  public function testMatchingImportReportsParity(): void {
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
        'status' => 'unpublished',
        'slug' => 'chickpea-stew',
        'ingredients' => ['2 cups chickpeas', '2 cloves garlic'],
        'instructions' => ['Simmer until tender.'],
        'appliance_settings' => [],
      ],
    ]);

    Node::create([
      'type' => 'recipe',
      'title' => 'Chickpea Stew',
      'status' => 0,
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
      'field_pages_url' => '',
      'field_legacy_id' => 7,
    ])->save();

    $report = $this->report();

    self::assertTrue($report['parity']);
    self::assertSame(1, $report['source_count']);
    self::assertSame(1, $report['imported_count']);
    self::assertSame(0, $report['missing_count']);
    self::assertSame(0, $report['extra_count']);
    self::assertSame(0, $report['mismatch_count']);
  }

  /**
   * Confirms mismatches, missing rows, and extra imported rows are reported.
   */
  public function testParityReportCapturesDifferences(): void {
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
        'ingredients' => ['2 cups chickpeas', '2 cloves garlic'],
        'instructions' => ['Simmer until tender.'],
        'appliance_settings' => [],
      ],
      [
        'id' => 8,
        'title' => 'Tomato Rice',
        'description' => 'A bright side dish.',
        'servings' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 25,
        'cuisine' => 'Caribbean',
        'category' => 'Side',
        'status' => 'unpublished',
        'slug' => 'tomato-rice',
        'ingredients' => ['1 cup rice', '2 cups water'],
        'instructions' => ['Simmer until fluffy.'],
        'appliance_settings' => [],
      ],
    ]);

    Node::create([
      'type' => 'recipe',
      'title' => 'Chickpea Stew Deluxe',
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
    ])->save();

    Node::create([
      'type' => 'recipe',
      'title' => 'Extra Imported Recipe',
      'status' => 0,
      'field_recipe_description' => 'Imported without source.',
      'field_servings' => 2,
      'field_prep_minutes' => 5,
      'field_cook_minutes' => 10,
      'field_cuisine' => 'Caribbean',
      'field_category' => 'Side',
      'field_ingredients' => [['value' => '1 cup rice'], ['value' => '2 cups water']],
      'field_instructions' => [['value' => 'Simmer until fluffy.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
      'field_provider' => 'legacy',
      'field_generation_context' => '{}',
      'field_pages_url' => '',
      'field_legacy_id' => 99,
    ])->save();

    $report = $this->report();

    self::assertFalse($report['parity']);
    self::assertSame(2, $report['source_count']);
    self::assertSame(2, $report['imported_count']);
    self::assertSame(1, $report['missing_count']);
    self::assertSame(1, $report['extra_count']);
    self::assertSame(1, $report['mismatch_count']);
    self::assertSame('Tomato Rice', $report['missing_sample'][0]['title']);
    self::assertSame('Extra Imported Recipe', $report['extra_sample'][0]['title']);
    self::assertSame(['title'], $report['mismatch_sample'][0]['fields']);
  }

  /**
   * Creates a temporary legacy SQLite database with recipes and children.
   *
   * @param array<int, array<string, mixed>> $recipes
   *   Legacy recipe rows.
   */
  private function createLegacyDatabase(array $recipes): void {
    $database = new \PDO('sqlite:' . $this->databasePath, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $database->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY, title TEXT, description TEXT, servings INTEGER, prep_time_minutes INTEGER, cook_time_minutes INTEGER, cuisine TEXT, category TEXT, status TEXT, slug TEXT)');
    $database->exec('CREATE TABLE recipe_ingredients (recipe_id INTEGER, position INTEGER, ingredient TEXT)');
    $database->exec('CREATE TABLE recipe_steps (recipe_id INTEGER, position INTEGER, instruction TEXT)');
    $database->exec('CREATE TABLE appliance_settings (recipe_id INTEGER, appliance_name TEXT, mode TEXT, setting_key TEXT, setting_value TEXT, time_minutes INTEGER, notes TEXT, step_number INTEGER)');

    foreach ($recipes as $recipe) {
      $database->prepare('INSERT INTO recipes (id, title, description, servings, prep_time_minutes, cook_time_minutes, cuisine, category, status, slug) VALUES (:id, :title, :description, :servings, :prep, :cook, :cuisine, :category, :status, :slug)')
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

  /**
   * Loads a parity report from the service.
   *
   * @return array<string, mixed>
   *   Parity report.
   */
  private function report(): array {
    return \Drupal::service('fresco_migrate.legacy_recipe_parity_reporter')->report($this->databasePath);
  }

}
