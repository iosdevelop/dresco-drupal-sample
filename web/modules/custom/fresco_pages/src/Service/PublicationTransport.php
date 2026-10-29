<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fresco_pages\Value\StaticArtifact;

/**
 * Selects the safe preview or configured GitHub publication transport.
 */
final class PublicationTransport {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GitHubGitClient $gitHubClient,
  ) {}

  /**
   * Publishes or validates an artifact and returns its transport identifier.
   */
  public function publish(StaticArtifact $artifact, string $message): string {
    $transport = (string) $this->configFactory->get('fresco_pages.settings')->get('transport');
    return match ($transport) {
      'preview' => 'preview:' . $artifact->contentHash,
      'github' => $this->gitHubClient->publish($artifact, $message),
      default => throw new \RuntimeException('Unsupported publication transport.'),
    };
  }

  /**
   * Returns whether the transport updates the canonical public repository.
   */
  public function isLive(): bool {
    return $this->configFactory->get('fresco_pages.settings')->get('transport') === 'github';
  }

}
