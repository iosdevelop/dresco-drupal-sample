<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation;

/**
 * A machine-readable recipe validation result.
 */
final readonly class ValidationResult {

  public function __construct(
    public string $ruleId,
    public string $message,
    public string $severity = 'error',
    public ?string $component = NULL,
    public ?string $remediation = NULL,
  ) {}

}
