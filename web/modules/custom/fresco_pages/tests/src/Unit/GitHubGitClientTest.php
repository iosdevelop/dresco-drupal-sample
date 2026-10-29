<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_pages\Unit;

use Drupal\fresco_pages\Service\GitHubAppAuthenticator;
use Drupal\fresco_pages\Service\GitHubGitClient;
use Drupal\fresco_pages\Value\StaticArtifact;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the atomic GitHub Git Data API commit.
 */
#[Group('fresco_pages')]
final class GitHubGitClientTest extends UnitTestCase {

  /**
   * Confirms deletions of files already gone from GitHub are skipped.
   *
   * GitHub answers 422 BadObjectState when a tree deletes a missing path, which
   * blocked every publish after old recipe artwork had been removed.
   */
  public function testPublishSkipsDeletionsMissingFromBaseTree(): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    self::assertNotFalse($key);
    openssl_pkey_export($key, $pem);
    putenv('FRESCO_GITHUB_APP_PRIVATE_KEY_B64=' . base64_encode($pem));

    $json = static fn (array $body): Response => new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR));
    $mock = new MockHandler([
      $json(['token' => 'installation-token']),
      $json(['object' => ['sha' => 'parent-sha']]),
      $json(['tree' => ['sha' => 'base-tree']]),
      $json(['sha' => 'blob-sha']),
      $json([
        'truncated' => FALSE,
        'tree' => [
          ['path' => 'assets/recipes/still-here.svg', 'type' => 'blob', 'sha' => 'a'],
          ['path' => 'recipes/stew/index.html', 'type' => 'blob', 'sha' => 'b'],
        ],
      ]),
      $json(['sha' => 'new-tree']),
      $json(['sha' => 'new-commit']),
      $json(['object' => ['sha' => 'new-commit']]),
    ]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'fresco_pages.settings' => [
        'repository_owner' => 'iosdevelop',
        'repository_name' => 'fresco-recipes',
        'branch' => 'main',
        'api_url' => 'https://api.github.com',
        'api_version' => '2022-11-28',
        'github_app_id' => '123',
        'github_installation_id' => '456',
      ],
    ]);
    $client = new GitHubGitClient($http, $config, new GitHubAppAuthenticator($http, $config));

    $sha = $client->publish(new StaticArtifact(
      'stew',
      'https://example.com/recipes/stew/',
      ['recipes/stew/index.html' => '<h1>Stew</h1>'],
      str_repeat('a', 64),
      ['assets/recipes/already-gone.svg', 'assets/recipes/still-here.svg'],
    ), 'Publish stew');

    self::assertSame('new-commit', $sha);
    $treeRequest = $history[5]['request'];
    self::assertStringEndsWith('/git/trees', $treeRequest->getUri()->getPath());
    $entries = json_decode((string) $treeRequest->getBody(), TRUE)['tree'];
    $deleted = array_column(array_filter($entries, static fn (array $entry): bool => $entry['sha'] === NULL), 'path');
    self::assertSame(['assets/recipes/still-here.svg'], $deleted);
    putenv('FRESCO_GITHUB_APP_PRIVATE_KEY_B64');
  }

}
