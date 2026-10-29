<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Dresco dashboard and searchable recipe library.
 */
final class RecipeDashboardController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $frescoEntityTypeManager,
    private readonly DateFormatterInterface $frescoDateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the dashboard.
   *
   * @return array<string, mixed>
   *   Dashboard render array.
   */
  public function dashboard(): array {
    $storage = $this->frescoEntityTypeManager->getStorage('node');
    $count = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'recipe')
      ->count()
      ->execute();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'recipe')
      ->sort('changed', 'DESC')
      ->range(0, 8)
      ->execute();

    return [
      '#theme' => 'fresco_recipe_dashboard',
      '#recipes' => $this->recipeRows($storage->loadMultiple($ids)),
      '#recipe_count' => (int) $count,
      '#cache' => [
        'tags' => ['node_list:recipe', 'fresco_publication_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Builds the searchable recipe library.
   *
   * @return array<string, mixed>
   *   Library render array.
   */
  public function library(Request $request): array {
    $query = trim((string) $request->query->get('q', ''));
    $storage = $this->frescoEntityTypeManager->getStorage('node');
    $entityQuery = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'recipe')
      ->sort('changed', 'DESC');
    if ($query !== '') {
      $matches = $entityQuery->orConditionGroup()
        ->condition('title', $query, 'CONTAINS')
        ->condition('field_ingredients', $query, 'CONTAINS')
        ->condition('field_cuisine', $query, 'CONTAINS')
        ->condition('field_appliance_settings', $query, 'CONTAINS');
      $entityQuery->condition($matches);
    }
    $ids = $entityQuery->execute();

    return [
      '#theme' => 'fresco_recipe_library',
      '#recipes' => $this->recipeRows($storage->loadMultiple($ids)),
      '#query' => $query,
      '#cache' => [
        'tags' => ['node_list:recipe', 'fresco_publication_list'],
        'contexts' => ['url.query_args:q', 'user.permissions'],
      ],
    ];
  }

  /**
   * Normalizes recipe nodes for Twig.
   *
   * @param array<int|string, \Drupal\node\NodeInterface> $nodes
   *   Recipe nodes.
   *
   * @return array<int, array<string, mixed>>
   *   Recipe view rows.
   */
  private function recipeRows(array $nodes): array {
    $rows = [];
    foreach ($nodes as $node) {
      [$publicUrl, $publicState, $publicMessage] = $this->publicRecipeData($node);
      $rows[] = [
        'title' => $node->label(),
        'description' => (string) $node->get('field_recipe_description')->value,
        'cuisine' => (string) $node->get('field_cuisine')->value,
        'status' => $node->isPublished() ? 'Published' : 'Draft',
        'public_url' => $publicUrl,
        'public_state' => $publicState,
        'public_message' => $publicMessage,
        'changed' => $this->frescoDateFormatter->format((int) $node->getChangedTime(), 'short'),
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString(),
        'image_url' => Url::fromRoute('fresco_recipe.image', ['node' => $node->id()])->toString(),
      ];
    }
    return $rows;
  }

  /**
   * Returns the latest public Pages URL if one exists.
   *
   * @return array{0: string, 1: string, 2: string}
   *   Public URL, publication state, and operator-facing status message.
   */
  private function publicRecipeData(NodeInterface $node): array {
    $fieldPublicUrl = trim((string) $node->get('field_pages_url')->value);
    $state = $node->isPublished() ? 'published' : 'draft';
    $storage = $this->frescoEntityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipe', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return [$fieldPublicUrl, $state, ''];
    }

    $publication = $storage->load(reset($ids));
    if (!$publication instanceof Publication) {
      return [$fieldPublicUrl, $state, ''];
    }

    $state = (string) $publication->get('state')->value;
    $publicUrl = $state === 'published' ? (string) $publication->get('public_url')->value : '';
    return [$publicUrl, $state, $this->publicRecipeStatusMessage($state)];
  }

  /**
   * Returns operator-facing copy for non-live publication states.
   */
  private function publicRecipeStatusMessage(string $state): string {
    return match ($state) {
      'queued' => 'Queued for GitHub Pages; public page is not live yet.',
      'processing' => 'Publishing to GitHub Pages now; public page is not live until it finishes.',
      'ready' => 'Ready in preview transport only; not pushed to GitHub Pages.',
      'failed' => 'Publication failed; review publications and queue it again.',
      'archived' => 'Archived from GitHub Pages.',
      default => '',
    };
  }

}
