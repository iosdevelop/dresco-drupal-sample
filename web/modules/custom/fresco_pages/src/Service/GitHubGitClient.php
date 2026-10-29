<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fresco_pages\Value\StaticArtifact;
use GuzzleHttp\ClientInterface;

/**
 * Atomically commits static artifacts through GitHub's Git Data API.
 */
final class GitHubGitClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GitHubAppAuthenticator $authenticator,
  ) {}

  /**
   * Creates blobs, a tree and commit, then fast-forwards the Pages branch.
   */
  public function publish(StaticArtifact $artifact, string $message): string {
    $config = $this->configFactory->get('fresco_pages.settings');
    $owner = rawurlencode((string) $config->get('repository_owner'));
    $repository = rawurlencode((string) $config->get('repository_name'));
    $branch = (string) $config->get('branch');
    if ($owner === '' || $repository === '' || $branch === '') {
      throw new \RuntimeException('GitHub repository settings are incomplete.');
    }
    $token = $this->authenticator->installationToken();
    $repositoryPath = '/repos/' . $owner . '/' . $repository;
    $reference = $this->request('GET', $repositoryPath . '/git/ref/heads/' . rawurlencode($branch), $token);
    $parentSha = $this->stringAt($reference, ['object', 'sha']);
    $commit = $this->request('GET', $repositoryPath . '/git/commits/' . rawurlencode($parentSha), $token);
    $baseTree = $this->stringAt($commit, ['tree', 'sha']);

    $entries = [];
    foreach ($artifact->files as $path => $contents) {
      $blob = $this->request('POST', $repositoryPath . '/git/blobs', $token, [
        'content' => $contents,
        'encoding' => 'utf-8',
      ]);
      $entries[] = [
        'path' => $path,
        'mode' => '100644',
        'type' => 'blob',
        'sha' => $this->stringAt($blob, ['sha']),
      ];
    }
    // GitHub rejects a tree that deletes a path the base tree lacks, so skip
    // deletions of files an earlier publication already removed.
    $deletedPaths = $artifact->deletedPaths === [] ? [] : array_intersect($artifact->deletedPaths, $this->treePaths($repositoryPath, $baseTree, $token));
    foreach ($deletedPaths as $path) {
      $entries[] = [
        'path' => $path,
        'mode' => '100644',
        'type' => 'blob',
        'sha' => NULL,
      ];
    }
    $tree = $this->request('POST', $repositoryPath . '/git/trees', $token, [
      'base_tree' => $baseTree,
      'tree' => $entries,
    ]);
    $newCommit = $this->request('POST', $repositoryPath . '/git/commits', $token, [
      'message' => $message,
      'tree' => $this->stringAt($tree, ['sha']),
      'parents' => [$parentSha],
    ]);
    $commitSha = $this->stringAt($newCommit, ['sha']);
    $this->request('PATCH', $repositoryPath . '/git/refs/heads/' . rawurlencode($branch), $token, [
      'sha' => $commitSha,
      'force' => FALSE,
    ]);
    return $commitSha;
  }

  /**
   * Returns the current configured branch head.
   *
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   */
  public function branchHead(?array $settings = NULL): string {
    $config = $this->configFactory->get('fresco_pages.settings');
    $token = $this->authenticator->installationToken($settings);
    $path = '/repos/' . rawurlencode((string) ($settings['repository_owner'] ?? $config->get('repository_owner')))
      . '/' . rawurlencode((string) ($settings['repository_name'] ?? $config->get('repository_name')))
      . '/git/ref/heads/' . rawurlencode((string) ($settings['branch'] ?? $config->get('branch')));
    return $this->stringAt($this->request('GET', $path, $token, NULL, $settings), ['object', 'sha']);
  }

  /**
   * Lists blob SHAs for the configured branch tree.
   *
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   *
   * @return array<string, string>
   *   Git blob SHA keyed by repository path.
   */
  public function treeBlobShas(?array $settings = NULL): array {
    $config = $this->configFactory->get('fresco_pages.settings');
    $token = $this->authenticator->installationToken($settings);
    $repositoryPath = '/repos/' . rawurlencode((string) ($settings['repository_owner'] ?? $config->get('repository_owner')))
      . '/' . rawurlencode((string) ($settings['repository_name'] ?? $config->get('repository_name')));
    $reference = $this->request('GET', $repositoryPath . '/git/ref/heads/' . rawurlencode((string) ($settings['branch'] ?? $config->get('branch'))), $token, NULL, $settings);
    $parentSha = $this->stringAt($reference, ['object', 'sha']);
    $commit = $this->request('GET', $repositoryPath . '/git/commits/' . rawurlencode($parentSha), $token, NULL, $settings);
    $treeSha = $this->stringAt($commit, ['tree', 'sha']);
    $tree = $this->request('GET', $repositoryPath . '/git/trees/' . rawurlencode($treeSha) . '?recursive=1', $token, NULL, $settings);
    if (($tree['truncated'] ?? FALSE) === TRUE) {
      throw new \RuntimeException('GitHub returned a truncated repository tree; drift cannot be trusted.');
    }
    if (!is_array($tree['tree'] ?? NULL)) {
      throw new \RuntimeException('GitHub returned an invalid repository tree.');
    }
    $files = [];
    foreach ($tree['tree'] as $entry) {
      if (!is_array($entry) || ($entry['type'] ?? NULL) !== 'blob') {
        continue;
      }
      if (is_string($entry['path'] ?? NULL) && is_string($entry['sha'] ?? NULL)) {
        $files[$entry['path']] = $entry['sha'];
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Lists the file paths in a repository tree.
   *
   * @return array<int, string>
   *   Blob paths, such as "assets/recipes/stew.svg".
   */
  private function treePaths(string $repositoryPath, string $treeSha, string $token): array {
    $tree = $this->request('GET', $repositoryPath . '/git/trees/' . rawurlencode($treeSha) . '?recursive=1', $token);
    if (($tree['truncated'] ?? FALSE) === TRUE || !is_array($tree['tree'] ?? NULL)) {
      throw new \RuntimeException('GitHub returned an incomplete repository tree; deletions cannot be checked.');
    }
    $paths = [];
    foreach ($tree['tree'] as $entry) {
      if (is_array($entry) && ($entry['type'] ?? NULL) === 'blob' && is_string($entry['path'] ?? NULL)) {
        $paths[] = $entry['path'];
      }
    }
    return $paths;
  }

  /**
   * Reads a UTF-8 repository file from the configured branch.
   */
  public function fileContents(string $path): string {
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
      throw new \InvalidArgumentException('A safe repository path is required.');
    }
    $config = $this->configFactory->get('fresco_pages.settings');
    $token = $this->authenticator->installationToken();
    $apiPath = '/repos/' . rawurlencode((string) $config->get('repository_owner'))
      . '/' . rawurlencode((string) $config->get('repository_name'))
      . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
      . '?ref=' . rawurlencode((string) $config->get('branch'));
    $payload = $this->request('GET', $apiPath, $token);
    if (($payload['encoding'] ?? NULL) !== 'base64' || !is_string($payload['content'] ?? NULL)) {
      throw new \RuntimeException('GitHub returned invalid repository file content.');
    }
    $contents = base64_decode(str_replace(["\r", "\n"], '', $payload['content']), TRUE);
    if ($contents === FALSE) {
      throw new \RuntimeException('GitHub returned malformed repository file content.');
    }
    return $contents;
  }

  /**
   * Makes a bounded, versioned GitHub API request.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path.
   * @param string $token
   *   Short-lived installation token.
   * @param array<string, mixed>|null $body
   *   Optional JSON body.
   * @param array<string, string>|null $settings
   *   Optional candidate settings.
   *
   * @return array<string, mixed>
   *   Decoded JSON response.
   */
  private function request(string $method, string $path, string $token, ?array $body = NULL, ?array $settings = NULL): array {
    $config = $this->configFactory->get('fresco_pages.settings');
    $options = [
      'headers' => [
        'Accept' => 'application/vnd.github+json',
        'Authorization' => 'Bearer ' . $token,
        'X-GitHub-Api-Version' => (string) ($settings['api_version'] ?? $config->get('api_version')),
        'User-Agent' => 'Dresco-Drupal-Publisher',
      ],
      'connect_timeout' => 10,
      'timeout' => 45,
      'http_errors' => FALSE,
    ];
    if ($body !== NULL) {
      $options['json'] = $body;
    }
    $response = $this->httpClient->request($method, rtrim((string) ($settings['api_url'] ?? $config->get('api_url')), '/') . $path, $options);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      // GitHub's message names the problem, such as a missing tree path.
      $error = json_decode((string) $response->getBody(), TRUE);
      $reason = is_array($error) && is_string($error['message'] ?? NULL) ? ': ' . mb_substr($error['message'], 0, 200) : '';
      throw new \RuntimeException(sprintf('GitHub Git Data API %s %s failed with status %d%s.', $method, preg_replace('#^/repos/[^/]+/[^/]+#', '', $path), $response->getStatusCode(), $reason));
    }
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
      throw new \RuntimeException('GitHub returned an invalid response.');
    }
    return $payload;
  }

  /**
   * Reads a required nested response string.
   *
   * @param array<string, mixed> $payload
   *   API payload.
   * @param array<int, string> $path
   *   Nested keys.
   */
  private function stringAt(array $payload, array $path): string {
    $value = $payload;
    foreach ($path as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        throw new \RuntimeException('GitHub response omitted required Git data.');
      }
      $value = $value[$key];
    }
    if (!is_string($value) || $value === '') {
      throw new \RuntimeException('GitHub response contained invalid Git data.');
    }
    return $value;
  }

}
