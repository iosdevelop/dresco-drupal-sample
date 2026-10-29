<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the recipe generator form in a real browser session.
 */
#[Group('fresco_recipe')]
#[RunTestsInSeparateProcesses]
final class RecipeGeneratorBrowserTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'fresco_ai',
    'fresco_recipe',
    'node',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (NodeType::load('recipe') === NULL) {
      NodeType::create([
        'type' => 'recipe',
        'name' => 'Recipe',
      ])->save();
    }

    $this->createField('field_recipe_description', 'string_long');
    $this->createField('field_servings', 'integer');
    $this->createField('field_prep_minutes', 'integer');
    $this->createField('field_cook_minutes', 'integer');
    $this->createField('field_cuisine', 'string_long');
    $this->createField('field_category', 'string_long');
    $this->createField('field_ingredients', 'string_long', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_instructions', 'string_long', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_appliance_settings', 'string_long', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_nutrition', 'string_long');
    $this->createField('field_provider', 'string_long');
    $this->createField('field_generation_context', 'string_long');

    \Drupal::configFactory()
      ->getEditable('fresco_ai.settings')
      ->set('provider', 'local')
      ->set('image_provider', 'ollama')
      ->save();

    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit own recipe content',
      'generate fresco recipes',
      'view own unpublished content',
    ]));
  }

  /**
   * Confirms a failed generation returns to the form with values and feedback.
   */
  public function testFailedGenerationRestoresTheForm(): void {
    $this->drupalGet('/generator');
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('generator_mode', 'restaurant');
    $page->fillField('Menu item or dish name', 'Tonkotsu ramen');
    $page->checkField('Stovetop');
    $page->pressButton('Generate draft');
    $this->checkForMetaRefresh();

    $this->assertSession()->addressEquals('/generator');
    $this->assertSession()->pageTextContains('cannot invent "Tonkotsu ramen"');
    $this->assertSession()->fieldValueEquals('Menu item or dish name', 'Tonkotsu ramen');
    $this->assertSession()->checkboxChecked('Stovetop');

    // The restored request is used once; a fresh visit starts clean.
    $this->drupalGet('/generator');
    $this->assertSession()->fieldValueEquals('Menu item or dish name', '');
  }

  /**
   * Confirms selected staples merge into ingredients and draft generation.
   *
   * This covers the browser submit flow end to end.
   */
  public function testStaplesMoveIntoIngredientsAndGenerateDraft(): void {
    $this->drupalGet('/generator');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->checkField('Black Beans');
    $page->checkField('Brown Rice');
    $page->checkField('Air Fryer');
    $page->checkField('Instant Pot Pro WiFi (6 qt)');
    $page->pressButton('Add selected staples');

    $this->assertSession()->fieldValueEquals('Available ingredients', "black beans\nbrown rice");

    $page->checkField('Red Kidney Beans');
    $page->pressButton('Add selected staples');

    $this->assertSession()->fieldValueEquals('Available ingredients', "black beans\nbrown rice\nred kidney beans");

    $page->pressButton('Generate draft');
    // Generation runs as a batch; follow its progress page to the draft.
    $this->checkForMetaRefresh();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Generated Lantern Black Bean Stew as a draft.');

    $node = $this->loadNodeByTitle('Lantern Black Bean Stew');
    self::assertInstanceOf(Node::class, $node);
    self::assertSame('Lantern Black Bean Stew', $node->label());
    self::assertSame(0, (int) $node->isPublished());

    $this->drupalGet('/recipes/' . $node->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->pressButton('Add another Ingredient');
    // The new empty field follows the generated ingredients.
    $newField = 'Ingredient ' . ($node->get('field_ingredients')->count() + 1);
    $this->assertSession()->fieldExists($newField);
    $page->fillField($newField, '1 tablespoon fresh ginger');
    $page->fillField('Revision note', 'Add fresh ginger.');
    $page->pressButton('Save new revision');
    $this->assertSession()->statusCodeEquals(200);

    $updatedNode = Node::load($node->id());
    self::assertInstanceOf(Node::class, $updatedNode);
    self::assertContains(
      ['value' => '1 tablespoon fresh ginger'],
      $updatedNode->get('field_ingredients')->getValue(),
    );

    $this->drupalGet('/recipes/' . $node->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->fillField('Cuisine', 'Ethiopian');
    $page->fillField('Revision note', 'Regenerate for Ethiopian cuisine.');
    $page->pressButton('Regenerate from edits');
    $this->assertSession()->statusCodeEquals(200);

    $regeneratedNode = Node::load($node->id());
    self::assertInstanceOf(Node::class, $regeneratedNode);
    self::assertSame('Ethiopian', (string) $regeneratedNode->get('field_cuisine')->value);
    // Evocative titles name the dish, not the cuisine.
    self::assertStringEndsWith('Black Bean Stew', (string) $regeneratedNode->label());
  }

  /**
   * Creates a recipe field and bundle field config.
   *
   * @param string $fieldName
   *   Field machine name.
   * @param string $type
   *   Field type.
   * @param int $cardinality
   *   Field cardinality.
   */
  private function createField(string $fieldName, string $type, int $cardinality = 1): void {
    if (FieldStorageConfig::loadByName('node', $fieldName) === NULL) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => $type,
        'cardinality' => $cardinality,
        'translatable' => FALSE,
      ])->save();
    }

    if (FieldConfig::loadByName('node', 'recipe', $fieldName) === NULL) {
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'recipe',
        'label' => $fieldName,
        'translatable' => FALSE,
      ])->save();
    }
  }

  /**
   * Loads a node by title without relying on route access.
   *
   * @param string $title
   *   Node title.
   *
   * @return \Drupal\node\Entity\Node|null
   *   Loaded node or NULL.
   */
  private function loadNodeByTitle(string $title): ?Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('title', $title)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $node = $storage->load(reset($ids));
    return $node instanceof Node ? $node : NULL;
  }

}
