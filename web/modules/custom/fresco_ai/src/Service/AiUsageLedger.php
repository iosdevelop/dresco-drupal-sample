<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores a small auditable history of AI requests.
 */
final class AiUsageLedger {

  private const STATE_KEY = 'fresco_ai.usage_ledger';

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Records a request attempt.
   *
   * @param string $type
   *   Request type.
   * @param string $provider
   *   Provider ID.
   * @param int $uid
   *   User ID.
   * @param string $status
   *   Outcome status.
   * @param string|null $detail
   *   Short detail message.
   */
  public function record(string $type, string $provider, int $uid, string $status, ?string $detail = NULL): void {
    $entries = $this->entries();
    $entries[] = [
      'time' => time(),
      'type' => $type,
      'provider' => $provider,
      'uid' => $uid,
      'status' => $status,
      'detail' => $detail,
    ];
    $this->state->set(self::STATE_KEY, $this->purge($entries));
  }

  /**
   * Returns a compact summary of recent requests.
   *
   * @return array<string, mixed>
   *   Usage summary.
   */
  public function summary(): array {
    $entries = $this->purge($this->entries());
    $recent = array_slice($entries, -20);
    $counts = ['ok' => 0, 'error' => 0, 'fallback' => 0, 'rate_limited' => 0];
    foreach ($entries as $entry) {
      $status = (string) ($entry['status'] ?? 'error');
      $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return [
      'entries' => count($entries),
      'recent' => $recent,
      'counts' => $counts,
    ];
  }

  /**
   * Removes expired records from state.
   *
   * @return int
   *   Remaining entry count.
   */
  public function purgeExpired(): int {
    $entries = $this->purge($this->entries());
    $this->state->set(self::STATE_KEY, $entries);
    return count($entries);
  }

  /**
   * Returns all stored entries.
   *
   * @return array<int, array<string, mixed>>
   *   Stored entries.
   */
  private function entries(): array {
    $entries = $this->state->get(self::STATE_KEY, []);
    return is_array($entries) ? $entries : [];
  }

  /**
   * Purges old entries according to config retention.
   *
   * @param array<int, array<string, mixed>> $entries
   *   Entries to purge.
   *
   * @return array<int, array<string, mixed>>
   *   Purged entries.
   */
  private function purge(array $entries): array {
    $retentionDays = max(1, (int) ($this->configFactory->get('fresco_ai.settings')->get('audit_retention_days') ?: 30));
    $cutoff = time() - ($retentionDays * 86400);
    return array_values(array_filter($entries, static fn (array $entry): bool => (int) ($entry['time'] ?? 0) >= $cutoff));
  }

}
