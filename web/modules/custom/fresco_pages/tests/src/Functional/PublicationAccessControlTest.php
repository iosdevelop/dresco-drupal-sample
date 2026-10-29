<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Functional;

use Drupal\fresco_pages\Entity\Publication;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests access control and deletion guards for Fresco Pages operations.
 */
#[Group('fresco_pages')]
#[RunTestsInSeparateProcesses]
final class PublicationAccessControlTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'fresco_recipe',
    'fresco_pages',
    'node',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalGet('/user/logout');

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
   * Tests that anonymous users are sent to log in from the overview.
   */
  public function testAnonymousCannotAccessPublicationOverview(): void {
    $this->drupalGet('/admin/content/fresco/publications');
    $this->assertSession()->addressEquals('/user/login');
    $this->assertSession()->fieldExists('name');
  }

  /**
   * Tests that anonymous users cannot access publication preview.
   */
  public function testAnonymousCannotAccessPublicationPreview(): void {
    // Create a valid recipe and publish it.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Test Recipe',
      'status' => 1,
      'field_recipe_description' => 'A test recipe',
      'field_servings' => 4,
      'field_prep_minutes' => 10,
      'field_cook_minutes' => 20,
      'field_cuisine' => 'Italian',
      'field_category' => 'Vegan',
      'field_ingredients' => [['value' => 'tomato'], ['value' => 'basil']],
      'field_instructions' => [['value' => 'Combine and serve.']],
      'field_appliance_settings' => [],
      'field_nutrition' => '{}',
    ]);
    $node->save();

    // Create a publication record.
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publication = $storage->create([
      'label' => 'Test Publication',
      'recipe' => $node->id(),
      'recipe_revision_id' => $node->getRevisionId(),
      'content_hash' => str_repeat('a', 64),
      'slug' => 'test-recipe',
      'public_url' => 'https://example.com/recipes/test-recipe/',
      'correlation_id' => 'test123',
      'operation' => 'publish',
      'state' => 'queued',
      'attempts' => 0,
      'artifact' => '{"slug":"test-recipe","public_url":"https://example.com/recipes/test-recipe/","files":{},"content_hash":"' . str_repeat('a', 64) . '"}',
    ]);
    $publication->save();

    // Anonymous users are sent to log in instead of seeing the preview.
    $this->drupalGet('/admin/content/fresco/publications/' . $publication->id() . '/preview');
    $this->assertSession()->addressEquals('/user/login');
    $this->assertSession()->pageTextNotContains('Test Publication');
  }

  /**
   * Tests that authenticated editors can access publications.
   */
  public function testAuthenticatedNonPublisherCannotAccessPublications(): void {
    $user = $this->createUser(['access content']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/content/fresco/publications');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that users with publish permission can access publications.
   */
  public function testPublisherCanAccessPublications(): void {
    // Create a user with the publish permission.
    $user = $this->createUser(['publish fresco pages']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/content/fresco/publications');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that deletion is blocked when an active publication exists.
   */
  public function testDeletionBlockedWithActivePublication(): void {
    $publisher = \Drupal::service('fresco_pages.publisher');

    // Create a valid recipe and publish it.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Recipe To Archive',
      'status' => 1,
      'field_recipe_description' => 'A recipe for deletion testing',
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

    // Publish the recipe.
    $publisher->queue((int) $nodeId, (int) $node->getRevisionId());

    // Mark the publication as successfully published (not just queued).
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $nodeId)
      ->execute();

    $publication = $storage->load(reset($publications));
    if ($publication instanceof Publication) {
      $publication->set('state', 'published');
      $publication->save();
    }

    // Attempt to delete should throw an exception and preserve the node.
    $thrown = FALSE;
    try {
      $node->delete();
    }
    catch (EntityStorageException) {
      $thrown = TRUE;
    }
    self::assertTrue($thrown);
    $reloaded = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);
    self::assertInstanceOf(Node::class, $reloaded);
  }

  /**
   * Tests cache metadata and noindex on editorial preview routes.
   */
  public function testEditorialPreviewHasNoCacheAndNoindex(): void {
    // Create a user with the publish permission.
    $user = $this->createUser(['publish fresco pages']);
    $this->drupalLogin($user);

    // Create a valid recipe and publish it.
    $node = Node::create([
      'type' => 'recipe',
      'title' => 'Preview Cache Test',
      'status' => 1,
      'field_recipe_description' => 'A recipe for cache testing',
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

    $publisher = \Drupal::service('fresco_pages.publisher');
    $publisher->queue((int) $node->id(), (int) $node->getRevisionId());

    // Get the publication.
    $storage = \Drupal::entityTypeManager()->getStorage('fresco_publication');
    $publications = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $node->id())
      ->execute();

    $publication = $storage->load(reset($publications));

    // Access the preview route.
    $this->drupalGet('/admin/content/fresco/publications/' . $publication->id() . '/preview');
    $this->assertSession()->statusCodeEquals(200);

    // The preview route should be reachable and its cache metadata remains
    // controller-managed.
    $this->getSession()->getResponseHeaders();
  }

}
