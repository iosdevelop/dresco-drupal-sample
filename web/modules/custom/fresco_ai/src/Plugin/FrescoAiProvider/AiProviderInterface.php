<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Plugin\FrescoAiProvider;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Contract implemented by recipe generation providers.
 */
interface AiProviderInterface extends PluginInspectionInterface {

  /**
   * Generates normalized recipe data from a bounded request.
   *
   * @param array<string, mixed> $request
   *   Generation request data.
   *
   * @return array<string, mixed>
   *   Data matching the Dresco recipe schema.
   */
  public function generate(array $request): array;

  /**
   * Reports whether the provider is configured and reachable.
   */
  public function isAvailable(): bool;

}
