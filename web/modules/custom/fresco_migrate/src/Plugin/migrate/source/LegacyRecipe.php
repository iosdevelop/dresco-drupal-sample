<?php

declare(strict_types=1);

namespace Drupal\fresco_migrate\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads complete recipe rows from the legacy read-only SQLite database.
 */
#[MigrateSource('fresco_legacy_recipe')]
final class LegacyRecipe extends SourcePluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   Source field labels keyed by field name.
   */
  public function fields(): array {
    return array_combine($this->fieldNames(), $this->fieldNames());
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator(): \Iterator {
    $path = (string) ($this->configuration['path'] ?? getenv('FRESCO_LEGACY_DB') ?: '');
    if ($path === '' || !is_readable($path)) {
      throw new \RuntimeException('Legacy Fresco database is not readable at ' . ($path ?: '[empty path]'));
    }
    $database = new \PDO('sqlite:' . $path, NULL, NULL, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $recipes = $database->query('SELECT * FROM recipes ORDER BY id')->fetchAll();
    $rows = [];
    foreach ($recipes as $recipe) {
      $recipeId = (int) $recipe['id'];
      $ingredients = $this->values($database, 'recipe_ingredients', 'ingredient', $recipeId, 'position');
      $instructions = $this->values($database, 'recipe_steps', 'instruction', $recipeId, 'position');
      $settings = $this->settings($database, $recipeId);
      $nutrition = json_decode((string) $recipe['nutrition'], TRUE);
      $published = (string) $recipe['status'] === 'published';
      $rows[] = [
        'id' => $recipeId,
        'title' => (string) $recipe['title'],
        'description' => (string) $recipe['description'],
        'servings' => (int) $recipe['servings'],
        'prep_time_minutes' => (int) $recipe['prep_time_minutes'],
        'cook_time_minutes' => (int) $recipe['cook_time_minutes'],
        'cuisine' => (string) $recipe['cuisine'],
        'category' => (string) $recipe['category'],
        'published' => $published ? 1 : 0,
        'created' => strtotime((string) $recipe['created_at']) ?: 0,
        'changed' => strtotime((string) $recipe['updated_at']) ?: 0,
        'ingredients' => array_map(static fn (string $value): array => ['value' => $value], $ingredients),
        'instructions' => array_map(static fn (string $value): array => ['value' => $value], $instructions),
        'appliance_settings' => array_map(static fn (string $value): array => ['value' => $value], $settings),
        'nutrition' => json_encode(is_array($nutrition) ? $nutrition : [], JSON_THROW_ON_ERROR),
        'provider' => (string) ($recipe['provider'] ?? 'legacy'),
        'generation_context' => json_encode([
          'legacy_id' => $recipeId,
          'legacy_slug' => (string) $recipe['slug'],
          'legacy_image_url' => (string) $recipe['image_url'],
          'raw_prompt' => $recipe['raw_prompt'],
          'raw_response' => $recipe['raw_response'],
          'published_at' => $recipe['published_at'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'pages_url' => $published
          ? 'https://iosdevelop.github.io/fresco-recipes/recipes/' . rawurlencode((string) $recipe['slug']) . '/'
          : '',
      ];
    }
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{type: string}>
   *   Source identifier definition.
   */
  public function getIds(): array {
    return ['id' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Legacy Fresco SQLite recipes';
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
   * Loads display-ready appliance settings for a recipe.
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
   * Returns source field names.
   *
   * @return array<int, string>
   *   Source field names.
   */
  private function fieldNames(): array {
    return [
      'id', 'title', 'description', 'servings', 'prep_time_minutes',
      'cook_time_minutes', 'cuisine', 'category', 'published', 'created',
      'changed', 'ingredients', 'instructions', 'appliance_settings',
      'nutrition', 'provider', 'generation_context', 'pages_url',
    ];
  }

}
