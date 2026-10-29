<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\fresco_ai\Attribute\AiProvider;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\AiProviderInterface;

/**
 * Discovers Dresco AI provider plugins.
 */
final class AiProviderManager extends DefaultPluginManager {

  /**
   * Constructs the AI provider plugin manager.
   *
   * @param \Traversable<string, string> $namespaces
   *   Module namespaces keyed by extension path.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Plugin definition cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cacheBackend,
    ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct(
      'Plugin/FrescoAiProvider',
      $namespaces,
      $moduleHandler,
      AiProviderInterface::class,
      AiProvider::class,
    );
    $this->alterInfo('fresco_ai_provider_info');
    $this->setCacheBackend($cacheBackend, 'fresco_ai_provider_plugins');
  }

}
