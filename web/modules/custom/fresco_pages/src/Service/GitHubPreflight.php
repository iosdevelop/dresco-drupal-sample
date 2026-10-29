<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Performs read-only GitHub publication readiness checks.
 */
final class GitHubPreflight {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GitHubGitClient $gitHubClient,
  ) {}

  /**
   * Checks settings and optionally GitHub read access without writing.
   *
   * @param bool $requireLive
   *   Whether to run live checks even when saved transport is preview.
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   *
   * @return array<string, bool|int|string|string[]>
   *   Preflight summary.
   */
  public function check(bool $requireLive = FALSE, ?array $settings = NULL): array {
    $config = $this->configFactory->get('fresco_pages.settings');
    $required = [
      'repository_owner',
      'repository_name',
      'branch',
      'github_app_id',
      'github_installation_id',
      'api_url',
      'api_version',
    ];
    $missing = [];
    foreach ($required as $key) {
      $envKey = match ($key) {
        'github_app_id' => 'FRESCO_GITHUB_APP_ID',
        'github_installation_id' => 'FRESCO_GITHUB_INSTALLATION_ID',
        default => NULL,
      };
      $value = trim((string) ($settings[$key] ?? $config->get($key) ?? ($envKey !== NULL ? getenv($envKey) : '')));
      if ($value === '') {
        $missing[] = $key;
      }
    }
    $privateKeyConfigured = trim((string) getenv('FRESCO_GITHUB_APP_PRIVATE_KEY_B64')) !== '';
    if (!$privateKeyConfigured) {
      $missing[] = 'FRESCO_GITHUB_APP_PRIVATE_KEY_B64';
    }

    $summary = [
      'transport' => (string) ($settings['transport'] ?? $config->get('transport')),
      'site_url' => (string) ($settings['site_url'] ?? $config->get('site_url')),
      'repository' => (string) ($settings['repository_owner'] ?? $config->get('repository_owner')) . '/' . (string) ($settings['repository_name'] ?? $config->get('repository_name')),
      'branch' => (string) ($settings['branch'] ?? $config->get('branch')),
      'api_url' => (string) ($settings['api_url'] ?? $config->get('api_url')),
      'github_app_id_configured' => trim((string) ($settings['github_app_id'] ?? $config->get('github_app_id') ?? getenv('FRESCO_GITHUB_APP_ID') ?: '')) !== '',
      'github_installation_id_configured' => trim((string) ($settings['github_installation_id'] ?? $config->get('github_installation_id') ?? getenv('FRESCO_GITHUB_INSTALLATION_ID') ?: '')) !== '',
      'private_key_env_configured' => $privateKeyConfigured,
      'missing' => $missing,
      'remote_checked' => FALSE,
    ];

    if (!$requireLive && $summary['transport'] !== 'github') {
      $summary['status'] = 'skipped_preview_transport';
      return $summary;
    }
    if ($missing !== []) {
      $summary['status'] = 'incomplete_configuration';
      return $summary;
    }

    $summary['branch_head'] = $this->gitHubClient->branchHead($settings);
    $summary['remote_blob_count'] = count($this->gitHubClient->treeBlobShas($settings));
    $summary['remote_checked'] = TRUE;
    $summary['status'] = 'ok';
    return $summary;
  }

}
