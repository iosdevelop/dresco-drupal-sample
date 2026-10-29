<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

/**
 * Defines the strict structured-output schema shared by remote providers.
 */
final class RecipeSchema {

  /**
   * Returns the JSON Schema accepted by the recipe contract factory.
   *
   * @return array<string, mixed>
   *   Strict recipe schema.
   */
  public function get(): array {
    return [
      'type' => 'object',
      'properties' => [
        'schema_version' => ['type' => 'string'],
        'title' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'servings' => ['type' => 'integer'],
        'prep_time_minutes' => ['type' => 'integer'],
        'cook_time_minutes' => ['type' => 'integer'],
        'cuisine' => ['type' => 'string'],
        'category' => ['type' => 'string'],
        'ingredients' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'food' => ['type' => 'string'],
              'original_text' => [
                'type' => 'string',
                'description' => 'Full ingredient line starting with quantity and unit, for example "14 ounces firm tofu, cubed".',
              ],
            ],
            'required' => ['food', 'original_text'],
            'additionalProperties' => FALSE,
          ],
        ],
        'instructions' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => FALSE,
          ],
        ],
        'appliance_settings' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'appliance' => ['type' => 'string'],
              'mode' => ['type' => 'string'],
              'time' => ['type' => 'string'],
            ],
            'required' => ['appliance', 'mode', 'time'],
            'additionalProperties' => FALSE,
          ],
        ],
        'nutrition' => [
          'type' => 'object',
          'properties' => [
            'calories' => ['type' => ['integer', 'string']],
            'protein' => ['type' => 'string'],
            'fiber' => ['type' => 'string'],
          ],
          'required' => ['calories', 'protein', 'fiber'],
          'additionalProperties' => FALSE,
        ],
      ],
      'required' => [
        'schema_version', 'title', 'description', 'servings',
        'prep_time_minutes', 'cook_time_minutes', 'cuisine', 'category',
        'ingredients', 'instructions', 'appliance_settings', 'nutrition',
      ],
      'additionalProperties' => FALSE,
    ];
  }

}
