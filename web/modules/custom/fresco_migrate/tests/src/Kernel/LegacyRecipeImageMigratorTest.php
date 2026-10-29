<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_migrate\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests repeatable legacy image migration into media entities.
 */
#[Group('fresco_migrate')]
#[RunTestsInSeparateProcesses]
final class LegacyRecipeImageMigratorTest extends KernelTestBase {

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
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig('fresco_recipe');
    require_once DRUPAL_ROOT . '/modules/custom/fresco_recipe/fresco_recipe.install';
    \fresco_recipe_install();

    $this->databasePath = tempnam(sys_get_temp_dir(), 'fresco-legacy-image-') ?: throw new \RuntimeException('Unable to create temporary database path.');
    unlink($this->databasePath);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_file($this->databasePath)) {
      unlink($this->databasePath);
    }
    parent::tearDown();
  }

  /**
   * Confirms legacy artwork is imported and attached to the recipe node.
   */
  public function testImportsLegacyImageIntoMedia(): void {
    $this->createLegacyDatabase([
      [
        'id' => 7,
        'title' => 'Chickpea Stew',
        'image_url' => '/generated/recipes/chickpea-stew.svg',
      ],
    ]);

    $node = Node::create([
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
    ]);
    $node->save();
    $nodeId = $node->id();

    $summary = \Drupal::service('fresco_migrate.legacy_recipe_image_migrator')->migrate($this->databasePath);

    self::assertSame(1, $summary['inspected']);
    self::assertSame(1, $summary['imported']);
    self::assertSame(1, $summary['updated']);
    self::assertSame(0, $summary['skipped']);

    $node = Node::load($nodeId);
    self::assertNotNull($node);
    self::assertFalse($node->get('field_hero_image')->isEmpty());
    $mediaId = (int) $node->get('field_hero_image')->target_id;
    self::assertGreaterThan(0, $mediaId);
    $media = \Drupal::entityTypeManager()->getStorage('media')->load($mediaId);
    self::assertNotNull($media);
    self::assertSame('recipe_image', $media->bundle());
    self::assertNotEmpty($media->get('field_media_image')->entity);
  }

  /**
   * Creates a temporary legacy SQLite database with image URLs.
   *
   * @param array<int, array<string, mixed>> $recipes
   *   Legacy recipe rows.
   */
  private function createLegacyDatabase(array $recipes): void {
    $database = new \PDO('sqlite:' . $this->databasePath, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $database->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY, title TEXT, image_url TEXT)');
    foreach ($recipes as $recipe) {
      $database->prepare('INSERT INTO recipes (id, title, image_url) VALUES (:id, :title, :image_url)')
        ->execute([
          'id' => $recipe['id'],
          'title' => $recipe['title'],
          'image_url' => $recipe['image_url'],
        ]);
    }
  }

}
