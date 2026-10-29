<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Enforces a simple rolling AI request limit.
 */
final class AiRateLimiter {

  private const STATE_KEY = 'fresco_ai.rate_limit';

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks whether the current user may make another request.
   *
   * @param string $operation
   *   Request operation name.
   * @param int $uid
   *   User ID.
   *
   * @throws \RuntimeException
   *   Thrown when the request quota has been exceeded.
   */
  public function assertAllowed(string $operation, int $uid): void {
    $limit = max(1, (int) ($this->configFactory->get('fresco_ai.settings')->get('request_rate_limit_per_hour') ?: 20));
    $now = time();
    $windowStart = $now - 3600;
    $entries = $this->entries();
    $filtered = [];
    $recentForUser = 0;

    foreach ($entries as $entry) {
      if ((int) ($entry['time'] ?? 0) < $windowStart) {
        continue;
      }
      $filtered[] = $entry;
      if ((int) ($entry['uid'] ?? 0) === $uid) {
        $recentForUser++;
      }
    }

    if ($recentForUser >= $limit) {
      throw new \RuntimeException(sprintf('AI request limit reached for this hour (%d).', $limit));
    }

    $filtered[] = [
      'time' => $now,
      'uid' => $uid,
      'operation' => $operation,
    ];
    $this->state->set(self::STATE_KEY, $filtered);
  }

  /**
   * Returns the current rate-limit summary.
   *
   * @return array<string, mixed>
   *   Rate limit summary.
   */
  public function summary(): array {
    $entries = $this->windowEntries();
    $byUser = [];
    foreach ($entries as $entry) {
      $uid = (int) ($entry['uid'] ?? 0);
      $byUser[$uid] = ($byUser[$uid] ?? 0) + 1;
    }
    return [
      'limit_per_hour' => max(1, (int) ($this->configFactory->get('fresco_ai.settings')->get('request_rate_limit_per_hour') ?: 20)),
      'window_total' => count($entries),
      'by_user' => $byUser,
    ];
  }

  /**
   * Returns entries that are still inside the rolling window.
   *
   * @return array<int, array<string, mixed>>
   *   Window entries.
   */
  private function windowEntries(): array {
    $windowStart = time() - 3600;
    return array_values(array_filter($this->entries(), static fn (array $entry): bool => (int) ($entry['time'] ?? 0) >= $windowStart));
  }

  /**
   * Returns all stored rate-limit entries.
   *
   * @return array<int, array<string, mixed>>
   *   Stored entries.
   */
  private function entries(): array {
    $entries = $this->state->get(self::STATE_KEY, []);
    return is_array($entries) ? $entries : [];
  }

}
