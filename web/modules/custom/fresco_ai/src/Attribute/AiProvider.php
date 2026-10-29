<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines a Dresco AI provider plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AiProvider extends Plugin {

  public function __construct(
    string $id,
    public readonly string $label,
    public readonly string $description = '',
  ) {
    parent::__construct($id);
  }

}
