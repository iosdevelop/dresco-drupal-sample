<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Exchanges a short-lived GitHub App JWT for an installation token.
 */
final class GitHubAppAuthenticator {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a short-lived installation access token.
   *
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   */
  public function installationToken(?array $settings = NULL): string {
    $config = $this->configFactory->get('fresco_pages.settings');
    $appId = trim((string) ($settings['github_app_id'] ?? $config->get('github_app_id') ?? getenv('FRESCO_GITHUB_APP_ID') ?: ''));
    $installationId = trim((string) ($settings['github_installation_id'] ?? $config->get('github_installation_id') ?? getenv('FRESCO_GITHUB_INSTALLATION_ID') ?: ''));
    $encodedKey = trim((string) getenv('FRESCO_GITHUB_APP_PRIVATE_KEY_B64'));
    if ($appId === '' || $installationId === '' || $encodedKey === '') {
      throw new \RuntimeException('GitHub App credentials are incomplete.');
    }
    $privateKey = base64_decode($encodedKey, TRUE);
    if ($privateKey === FALSE || !str_contains($privateKey, 'PRIVATE KEY')) {
      throw new \RuntimeException('The GitHub App private key is invalid.');
    }
    $now = time();
    $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = $this->base64Url(json_encode([
      'iat' => $now - 60,
      'exp' => $now + 540,
      'iss' => $appId,
    ], JSON_THROW_ON_ERROR));
    $input = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Could not sign the GitHub App JWT.');
    }
    $jwt = $input . '.' . $this->base64Url($signature);
    $response = $this->httpClient->request(
      'POST',
      rtrim((string) ($settings['api_url'] ?? $config->get('api_url')), '/') . '/app/installations/' . rawurlencode($installationId) . '/access_tokens',
      [
        'headers' => $this->headers($jwt, $settings),
        'json' => [
          'repositories' => [(string) ($settings['repository_name'] ?? $config->get('repository_name'))],
          'permissions' => ['contents' => 'write'],
        ],
        'connect_timeout' => 10,
        'timeout' => 30,
        'http_errors' => FALSE,
      ],
    );
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new \RuntimeException('GitHub rejected the installation token request.');
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['token'] ?? NULL) || $payload['token'] === '') {
      throw new \RuntimeException('GitHub returned no installation token.');
    }
    return $payload['token'];
  }

  /**
   * Returns standard versioned GitHub API headers.
   *
   * @param string $token
   *   Authentication token.
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   *
   * @return array<string, string>
   *   Request headers.
   */
  private function headers(string $token, ?array $settings = NULL): array {
    return [
      'Accept' => 'application/vnd.github+json',
      'Authorization' => 'Bearer ' . $token,
      'X-GitHub-Api-Version' => (string) ($settings['api_version'] ?? $this->configFactory->get('fresco_pages.settings')->get('api_version')),
      'User-Agent' => 'Dresco-Drupal-Publisher',
    ];
  }

  /**
   * Encodes a JWT segment without padding.
   */
  private function base64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

}
