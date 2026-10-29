<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Kernel;

use Drupal\fresco_pages\Entity\Publication;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests publication workflow including validation, idempotency, and metadata.
 */
#[Group('fresco_pages')]
final class PublicationWorkflowTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'fresco_recipe',
    'fresco_pages',
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
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('fresco_publication');
    $this->installConfig('fresco_pages');
    $this->installConfig('fresco_recipe');
    require_once DRUPAL_ROOT . '/modules/custom/fresco_recipe/fresco_recipe.install';
    \fresco_recipe_install();

    // Create a recipe node type with required fields.
    if (NodeType::load('recipe') === NULL) {
      $type = NodeType::create(['type' => 'recipe', 'name' => 'Recipe']);
      $type->save();
    }

    // Configure fresco_pages settings with a valid site URL.
    \Drupal::configFactory()
      ->getEditable('fresco_pages.settings')
      ->set('site_url', 'https://example.github.io/fresco-recipes')
      ->save();
  }

  /**
   * Tests that an invalid recipe cannot be published.
   */
  public function testInvalidRecipeCannotBePublished(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create a recipe node with missing required sections (no instructions).
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Invalid Recipe',
      'status' => 1,
      'field_recipe_description' => 'A description',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      // Empty instructions are invalid.
      'field_instructions' => [],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();

    // Publishing an invalid recipe should throw an exception.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Recipe validation failed');
    $publisher->queue((int) $node->id(), (int) $node->getRevisionId());
  }

  /**
   * Tests that a valid recipe can be published and creates a record.
   */
  public function testValidRecipeCreatesPublicationRecord(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create a valid recipe node.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Valid Recipe',
      'status' => 1,
      'field_recipe_description' => 'A delicious recipe',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        [
          'value' => 'Sauté garlic and scallions, add the chickpeas and 1 cup water, simmer with thyme until tender, then finish with lime.',
        ],
      ],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();

    // Publishing should return a UUID and create a publication record.
    $uuid = $publisher->queue((int) $node->id(), (int) $node->getRevisionId());
    self::assertNotEmpty($uuid);

    // Verify the publication record was created.
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $node->id())
      ->execute();

    self::assertCount(1, $publications);
  }

  /**
   * Tests that a public recipe made family-only leaves no public content.
   */
  public function testFamilyOnlyRecipeLeavesPublicRedirectOnly(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Family Chickpea Stew',
      'status' => 1,
      'field_recipe_description' => 'A family stew',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Caribbean',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        ['value' => 'Sauté garlic and scallions, add the chickpeas and 1 cup water, simmer with thyme until tender, then finish with lime.'],
      ],
      'field_appliance_settings' => [['value' => 'Instant Pot | Sauté | 30 minutes']],
      'field_nutrition' => '{}',
    ]);
    $node->save();
    $public = current($storage->loadByProperties(['uuid' => $publisher->queue((int) $node->id(), (int) $node->getRevisionId())]));
    self::assertInstanceOf(Publication::class, $public);
    $public->set('state', 'published')->save();

    $node->set('field_family_only', TRUE);
    $node->setNewRevision(TRUE);
    $node->save();
    $family = current($storage->loadByProperties(['uuid' => $publisher->queue((int) $node->id(), (int) $node->getRevisionId())]));
    self::assertInstanceOf(Publication::class, $family);
    $artifact = \Drupal::service('fresco_pages.site_assembler')->assemble($family);

    self::assertArrayHasKey('family/recipes/family-chickpea-stew/index.html', $artifact->files);
    self::assertStringContainsString('Family Chickpea Stew', $artifact->files['family/index.html']);
    $publicPage = $artifact->files['recipes/family-chickpea-stew/index.html'];
    self::assertStringContainsString('Recipe moved', $publicPage);
    self::assertStringContainsString('https://example.github.io/fresco-recipes/family/recipes/family-chickpea-stew/', $publicPage);
    self::assertStringNotContainsString('chickpeas', $publicPage);
    self::assertContains('assets/recipes/family-chickpea-stew.svg', $artifact->deletedPaths);
    self::assertStringNotContainsString('Family Chickpea Stew', $artifact->files['index.html']);
    self::assertArrayNotHasKey('sitemap.xml', $artifact->files);
    self::assertContains('sitemap.xml', $artifact->deletedPaths);
    self::assertStringNotContainsString('family-chickpea-stew', $artifact->files['recipes.json']);
  }

  /**
   * Tests idempotent publication for same revision and content hash.
   */
  public function testPublicationIsIdempotent(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create a valid recipe node.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Idempotent Recipe',
      'status' => 1,
      'field_recipe_description' => 'A stable recipe',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        [
          'value' => 'Sauté garlic and scallions, add the chickpeas and 1 cup water, simmer with thyme until tender, then finish with lime.',
        ],
      ],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();
    $nodeId = $node->id();
    $revisionId = $node->getRevisionId();

    // Publish the recipe twice.
    $uuid1 = $publisher->queue((int) $nodeId, (int) $revisionId);
    $uuid2 = $publisher->queue((int) $nodeId, (int) $revisionId);

    // Both calls should return the same UUID.
    self::assertSame($uuid1, $uuid2);

    // Verify only one publication record exists.
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $nodeId)
      ->execute();

    self::assertCount(1, $publications);
  }

  /**
   * Tests that publication records preserve all required metadata.
   */
  public function testPublicationRecordPreservesMetadata(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create a valid recipe node.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Metadata Test Recipe',
      'status' => 1,
      'field_recipe_description' => 'A recipe for testing',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        [
          'value' => 'Sauté garlic and scallions, add the chickpeas and 1 cup water, simmer with thyme until tender, then finish with lime.',
        ],
      ],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();
    $nodeId = $node->id();
    $revisionId = $node->getRevisionId();

    // Publish the recipe.
    self::assertNotEmpty($publisher->queue((int) $nodeId, (int) $revisionId));

    // Load the publication record.
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $nodeId)
      ->execute();

    $publication = $storage->load(reset($publications));
    self::assertInstanceOf(Publication::class, $publication);

    // Verify metadata preservation.
    self::assertSame((int) $nodeId, (int) $publication->get('recipe')->target_id);
    self::assertSame((int) $revisionId, (int) $publication->get('recipe_revision_id')->value);
    self::assertNotEmpty($publication->get('content_hash')->value);
    self::assertSame(64, strlen($publication->get('content_hash')->value));
    self::assertNotEmpty($publication->get('correlation_id')->value);
    self::assertSame('publish', $publication->get('operation')->value);
    self::assertSame('queued', $publication->get('state')->value);
    self::assertSame(0, (int) $publication->get('attempts')->value);
    self::assertNotEmpty($publication->get('artifact')->value);
    self::assertNotEmpty($publication->get('created')->value);
    self::assertNotEmpty($publication->get('changed')->value);
  }

  /**
   * Tests imported ingredient-count titles publish to clean canonical slugs.
   */
  public function testIngredientCountTitleUsesCleanPublicationSlug(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');
    $node = Node::create([
      'type' => 'recipe',
      'title' => '1 Eggplant Thai Stew',
      'status' => 1,
      'field_recipe_description' => 'A savory stew',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Thai',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '1 eggplant'],
        ['value' => '1 small onion'],
        ['value' => '2 cloves garlic'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        [
          'value' => 'Sauté onion, garlic, and thyme, then simmer with eggplant until tender and finish with lime.',
        ],
      ],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();

    self::assertNotEmpty($publisher->queue((int) $node->id(), (int) $node->getRevisionId()));

    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $node->id())
      ->execute();
    $publication = $storage->load(reset($publications));
    self::assertInstanceOf(Publication::class, $publication);
    self::assertSame('eggplant-thai-stew', $publication->get('slug')->value);
    self::assertSame(
      'https://example.github.io/fresco-recipes/recipes/eggplant-thai-stew/',
      $publication->get('public_url')->value,
    );

    $artifact = json_decode((string) $publication->get('artifact')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertArrayHasKey('recipes/eggplant-thai-stew/index.html', $artifact['files']);
    self::assertArrayNotHasKey('recipes/1-eggplant-thai-stew/index.html', $artifact['files']);
  }

  /**
   * Tests that an unpublished recipe cannot be queued.
   */
  public function testUnpublishedRecipeCannotBeQueued(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create an unpublished recipe node.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Unpublished Recipe',
      'status' => 0,
      'field_recipe_description' => 'A draft recipe',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [
        ['value' => '2 cups chickpeas'],
        ['value' => '2 cloves garlic'],
        ['value' => '2 scallions'],
        ['value' => '1 teaspoon thyme'],
        ['value' => '1 lime'],
      ],
      'field_instructions' => [
        [
          'value' => 'Sauté garlic and scallions, add the chickpeas and 1 cup water, simmer with thyme until tender, then finish with lime.',
        ],
      ],
      'field_appliance_settings' => [
        ['value' => 'Instant Pot | Sauté | 30 minutes'],
      ],
      'field_nutrition' => '{}',
    ]);
    $node->save();

    // Queuing should fail because the node is not published.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only published recipe revisions can be queued');
    $publisher->queue((int) $node->id(), (int) $node->getRevisionId());
  }

}
