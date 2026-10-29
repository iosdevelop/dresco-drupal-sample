<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Value;

/**
 * Provider-independent, versioned recipe data contract.
 */
final readonly class RecipeContract {

  public const SCHEMA_VERSION = '1.0';

  /**
   * Constructs a recipe contract.
   *
   * @param string $title
   *   Recipe title.
   * @param string $description
   *   Recipe summary.
   * @param int $servings
   *   Number of servings.
   * @param int $prepTimeMinutes
   *   Preparation time in minutes.
   * @param int $cookTimeMinutes
   *   Cooking time in minutes.
   * @param string $cuisine
   *   Recipe cuisine.
   * @param string $category
   *   Recipe category.
   * @param array<int, array<string, mixed>> $ingredients
   *   Ordered structured ingredients.
   * @param array<int, array<string, mixed>> $instructions
   *   Ordered structured instructions.
   * @param array<int, array<string, mixed>> $applianceSettings
   *   Ordered appliance settings.
   * @param array<string, int|string|float|null> $nutrition
   *   Normalized nutrition values.
   * @param string $schemaVersion
   *   Recipe contract schema version.
   */
  public function __construct(
    public string $title,
    public string $description,
    public int $servings,
    public int $prepTimeMinutes,
    public int $cookTimeMinutes,
    public string $cuisine,
    public string $category,
    public array $ingredients,
    public array $instructions,
    public array $applianceSettings,
    public array $nutrition,
    public string $schemaVersion = self::SCHEMA_VERSION,
  ) {
    if (trim($title) === '' || trim($description) === '') {
      throw new \InvalidArgumentException('Recipe title and description are required.');
    }
    // No-cook recipes legitimately have zero cook time.
    if ($servings < 1 || $prepTimeMinutes < 1 || $cookTimeMinutes < 0) {
      throw new \InvalidArgumentException('Servings and prep time must be positive, and cook time cannot be negative.');
    }
  }

  /**
   * Returns the stable serialization shape.
   *
   * @return array<string, mixed>
   *   Recipe contract data.
   */
  public function toArray(): array {
    return [
      'schema_version' => $this->schemaVersion,
      'title' => $this->title,
      'description' => $this->description,
      'servings' => $this->servings,
      'prep_time_minutes' => $this->prepTimeMinutes,
      'cook_time_minutes' => $this->cookTimeMinutes,
      'cuisine' => $this->cuisine,
      'category' => $this->category,
      'ingredients' => $this->ingredients,
      'instructions' => $this->instructions,
      'appliance_settings' => $this->applianceSettings,
      'nutrition' => $this->nutrition,
    ];
  }

}
