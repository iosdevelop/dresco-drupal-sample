<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds read-only parity reports for legacy SQLite recipe imports.
 */
final class LegacyRecipeParityReporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Compares legacy source rows with imported Drupal recipe nodes.
   *
   * @return array<string, mixed>
   *   JSON-serializable parity report.
   */
  public function report(string $path, int $sampleLimit = 10): array {
    if ($path === '' || !is_readable($path)) {
      return [
        'source' => $path,
        'source_readable' => FALSE,
        'errors' => ['Legacy Fresco database is not readable at ' . ($path ?: '[empty path]')],
      ];
    }

    $sourceRows = $this->legacyRows($path);
    $drupalRows = $this->drupalRows();
    $missing = [];
    $mismatches = [];

    foreach ($sourceRows as $legacyId => $source) {
      if (!isset($drupalRows[$legacyId])) {
        $missing[] = [
          'legacy_id' => $legacyId,
          'title' => $source['title'],
        ];
        continue;
      }
      $differences = $this->differences($source, $drupalRows[$legacyId]);
      if ($differences !== []) {
        $mismatches[] = [
          'legacy_id' => $legacyId,
          'title' => $source['title'],
          'node_id' => $drupalRows[$legacyId]['node_id'],
          'fields' => $differences,
        ];
      }
    }

    $extra = [];
    foreach ($drupalRows as $legacyId => $drupal) {
      if (!isset($sourceRows[$legacyId])) {
        $extra[] = [
          'legacy_id' => $legacyId,
          'node_id' => $drupal['node_id'],
          'title' => $drupal['title'],
        ];
      }
    }

    $hasIssues = $missing !== [] || $extra !== [] || $mismatches !== [];
    return [
      'source' => $path,
      'source_readable' => TRUE,
      'source_count' => count($sourceRows),
      'imported_count' => count($drupalRows),
      'missing_count' => count($missing),
      'missing_sample' => array_slice($missing, 0, $sampleLimit),
      'extra_count' => count($extra),
      'extra_sample' => array_slice($extra, 0, $sampleLimit),
      'mismatch_count' => count($mismatches),
      'mismatch_sample' => array_slice($mismatches, 0, $sampleLimit),
      'parity' => !$hasIssues,
    ];
  }

  /**
   * Loads normalized legacy recipe rows keyed by legacy ID.
   *
   * @return array<int, array<string, mixed>>
   *   Source rows.
   */
  private function legacyRows(string $path): array {
    $database = new \PDO('sqlite:' . $path, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $rows = [];
    foreach ($database->query('SELECT * FROM recipes ORDER BY id')->fetchAll() as $recipe) {
      $legacyId = (int) $recipe['id'];
      $published = (string) $recipe['status'] === 'published';
      $rows[$legacyId] = [
        'title' => (string) $recipe['title'],
        'description' => (string) $recipe['description'],
        'servings' => (int) $recipe['servings'],
        'prep_minutes' => (int) $recipe['prep_time_minutes'],
        'cook_minutes' => (int) $recipe['cook_time_minutes'],
        'cuisine' => (string) $recipe['cuisine'],
        'category' => (string) $recipe['category'],
        'status' => $published ? 1 : 0,
        'ingredients' => $this->values($database, 'recipe_ingredients', 'ingredient', $legacyId, 'position'),
        'instructions' => $this->values($database, 'recipe_steps', 'instruction', $legacyId, 'position'),
        'appliance_settings' => $this->settings($database, $legacyId),
        'pages_url' => $published
          ? 'https://iosdevelop.github.io/fresco-recipes/recipes/' . rawurlencode((string) $recipe['slug']) . '/'
          : '',
      ];
    }
    return $rows;
  }

  /**
   * Loads normalized imported Drupal recipe nodes keyed by legacy ID.
   *
   * @return array<int, array<string, mixed>>
   *   Imported rows.
   */
  private function drupalRows(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'recipe')
      ->exists('field_legacy_id')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $legacyId = (int) $node->get('field_legacy_id')->value;
      if ($legacyId < 1) {
        continue;
      }
      $rows[$legacyId] = [
        'node_id' => (int) $node->id(),
        'title' => $node->label(),
        'description' => (string) $node->get('field_recipe_description')->value,
        'servings' => (int) $node->get('field_servings')->value,
        'prep_minutes' => (int) $node->get('field_prep_minutes')->value,
        'cook_minutes' => (int) $node->get('field_cook_minutes')->value,
        'cuisine' => (string) $node->get('field_cuisine')->value,
        'category' => (string) $node->get('field_category')->value,
        'status' => $node->isPublished() ? 1 : 0,
        'ingredients' => $this->fieldValues($node, 'field_ingredients'),
        'instructions' => $this->fieldValues($node, 'field_instructions'),
        'appliance_settings' => $this->fieldValues($node, 'field_appliance_settings'),
        'pages_url' => (string) $node->get('field_pages_url')->value,
      ];
    }
    ksort($rows);
    return $rows;
  }

  /**
   * Returns field names that differ between source and imported rows.
   *
   * @param array<string, mixed> $source
   *   Normalized legacy row.
   * @param array<string, mixed> $drupal
   *   Normalized Drupal row.
   *
   * @return array<int, string>
   *   Mismatched field names.
   */
  private function differences(array $source, array $drupal): array {
    $fields = [
      'title', 'description', 'servings', 'prep_minutes', 'cook_minutes',
      'cuisine', 'category', 'status', 'ingredients', 'instructions',
      'appliance_settings', 'pages_url',
    ];
    return array_values(array_filter(
      $fields,
      static fn (string $field): bool => ($source[$field] ?? NULL) !== ($drupal[$field] ?? NULL),
    ));
  }

  /**
   * Loads one ordered string column from a legacy child table.
   *
   * @return array<int, string>
   *   Ordered values.
   */
  private function values(\PDO $database, string $table, string $column, int $recipeId, string $order): array {
    $allowed = [
      'recipe_ingredients' => ['ingredient', 'position'],
      'recipe_steps' => ['instruction', 'position'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], TRUE) || !in_array($order, $allowed[$table], TRUE)) {
      throw new \InvalidArgumentException('Invalid legacy recipe child query.');
    }
    $statement = $database->prepare("SELECT {$column} FROM {$table} WHERE recipe_id = :id ORDER BY {$order}");
    $statement->execute(['id' => $recipeId]);
    return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
  }

  /**
   * Loads display-ready appliance settings for a legacy recipe.
   *
   * @return array<int, string>
   *   Ordered appliance settings.
   */
  private function settings(\PDO $database, int $recipeId): array {
    $statement = $database->prepare(
      'SELECT appliance_name, mode, setting_key, setting_value, time_minutes, notes
       FROM appliance_settings WHERE recipe_id = :id ORDER BY appliance_name, step_number',
    );
    $statement->execute(['id' => $recipeId]);
    $settings = [];
    foreach ($statement->fetchAll() as $row) {
      $setting = trim((string) ($row['setting_key'] ?? '') . ' ' . (string) ($row['setting_value'] ?? ''));
      $time = $row['time_minutes'] !== NULL ? (string) $row['time_minutes'] . ' minutes' : '';
      $settings[] = implode(' | ', array_filter([
        (string) $row['appliance_name'],
        (string) $row['mode'],
        $setting,
        $time,
        (string) ($row['notes'] ?? ''),
      ]));
    }
    return $settings;
  }

  /**
   * Reads a multi-value string field from a node.
   *
   * @return array<int, string>
   *   Field values.
   */
  private function fieldValues(NodeInterface $node, string $fieldName): array {
    return array_values(array_map(
      static fn (array $item): string => (string) ($item['value'] ?? ''),
      $node->get($fieldName)->getValue(),
    ));
  }

}
