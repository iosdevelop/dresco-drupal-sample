<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves deterministic SVG artwork for recipe revisions.
 */
final class RecipeImageController extends ControllerBase {

  public function __construct(
    private readonly RecipeSvgBuilder $svgBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('fresco_recipe.svg_builder'));
  }

  /**
   * Returns accessible SVG artwork for a recipe.
   */
  public function image(NodeInterface $node): CacheableResponse {
    if ($node->bundle() !== 'recipe') {
      throw new NotFoundHttpException();
    }
    $ingredients = array_map(
      static fn (array $item): string => (string) ($item['value'] ?? ''),
      $node->get('field_ingredients')->getValue(),
    );
    $response = new CacheableResponse(
      $this->svgBuilder->build($node->label(), $ingredients),
      200,
      [
        'Content-Type' => 'image/svg+xml; charset=UTF-8',
        'Content-Disposition' => 'inline',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
      ],
    );
    $response->addCacheableDependency($node);
    return $response;
  }

}
