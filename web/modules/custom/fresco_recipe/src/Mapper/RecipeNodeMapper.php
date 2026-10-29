<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Mapper;

use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\node\NodeInterface;

/**
 * Maps Drupal recipe nodes to the shared recipe contract.
 */
final class RecipeNodeMapper {

  public function __construct(
    private readonly RecipeContractFactory $contractFactory,
  ) {}

  /**
   * Maps a recipe node to a provider-independent contract.
   */
  public function fromNode(NodeInterface $node): RecipeContract {
    if ($node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('Only recipe nodes can be mapped.');
    }

    $nutrition = json_decode((string) $node->get('field_nutrition')->value, TRUE);
    return $this->contractFactory->fromArray([
      'title' => $node->label(),
      'description' => (string) $node->get('field_recipe_description')->value,
      'servings' => (int) $node->get('field_servings')->value,
      'prep_time_minutes' => (int) $node->get('field_prep_minutes')->value,
      'cook_time_minutes' => (int) $node->get('field_cook_minutes')->value,
      'cuisine' => (string) $node->get('field_cuisine')->value,
      'category' => (string) $node->get('field_category')->value,
      'ingredients' => array_map(
        static fn (array $item): array => [
          'food' => (string) ($item['value'] ?? ''),
          'original_text' => (string) ($item['value'] ?? ''),
        ],
        $node->get('field_ingredients')->getValue(),
      ),
      'instructions' => array_map(
        static fn (array $item): array => ['text' => (string) ($item['value'] ?? '')],
        $node->get('field_instructions')->getValue(),
      ),
      'appliance_settings' => array_map(
        static function (array $item): array {
          $parts = array_map('trim', explode('|', (string) ($item['value'] ?? '')));
          return [
            'appliance' => $parts[0],
            'mode' => $parts[1] ?? '',
            'time' => $parts[2] ?? '',
          ];
        },
        $node->get('field_appliance_settings')->getValue(),
      ),
      'nutrition' => is_array($nutrition) ? $nutrition : [],
    ]);
  }

}
