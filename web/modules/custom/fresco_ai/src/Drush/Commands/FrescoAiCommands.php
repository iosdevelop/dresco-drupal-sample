<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\fresco_ai\Eval\RecipeEvaluator;
use Drupal\fresco_ai\Service\AiPreflight;
use Drupal\fresco_ai\Service\AiRequestProcessor;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\IngredientImageAnalyzer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Dresco AI.
 */
final class FrescoAiCommands extends DrushCommands {

  public function __construct(
    private readonly AiPreflight $preflightService,
    private readonly AiUsageLedger $usageLedger,
    private readonly QueueFactory $queueFactory,
    private readonly AiRequestProcessor $processor,
    private readonly IngredientImageAnalyzer $analyzer,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly RecipeEvaluator $evaluator,
  ) {}

  /**
   * Scores recipe generation against the evaluation cases.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:ai:eval')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Option(name: 'provider', description: 'Provider id to evaluate instead of the configured one, for example local or ollama.')]
  #[CLI\Option(name: 'case', description: 'Comma-separated case names to run; defaults to all cases.')]
  #[CLI\Option(name: 'fail-under', description: 'Exit with an error when the average score is below this percentage.')]
  #[CLI\Option(name: 'format', description: 'Output format: text or json.')]
  #[CLI\Usage(name: 'drush fresco:ai:eval --provider=local', description: 'Score the deterministic fallback in seconds.')]
  #[CLI\Usage(name: 'drush fresco:ai:eval --case=berry_smoothie,banana_bread', description: 'Score selected cases with the configured provider.')]
  public function evaluate(
    array $options = [
      'provider' => NULL,
      'case' => NULL,
      'fail-under' => NULL,
      'format' => 'text',
    ],
  ): int {
    $cases = $this->evaluator->cases();
    if (is_string($options['case']) && $options['case'] !== '') {
      $wanted = array_map('trim', explode(',', $options['case']));
      $unknown = array_diff($wanted, array_keys($cases));
      if ($unknown !== []) {
        $this->logger()->error('Unknown eval cases: ' . implode(', ', $unknown));
        return self::EXIT_FAILURE;
      }
      $cases = array_intersect_key($cases, array_flip($wanted));
    }
    $provider = is_string($options['provider']) && $options['provider'] !== '' ? $options['provider'] : NULL;
    $json = $options['format'] === 'json';

    $results = [];
    foreach ($cases as $name => $case) {
      $result = $this->evaluator->evaluate($case, $provider);
      $results[$name] = $result;
      if (!$json) {
        $this->output()->writeln($this->evalLine((string) $name, $result));
      }
    }

    $count = max(1, count($results));
    $summary = [
      'provider' => $provider ?? (string) $this->configFactory->get('fresco_ai.settings')->get('provider'),
      'cases' => count($results),
      'ok' => count(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'ok')),
      'fallback' => count(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'fallback')),
      'error' => count(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'error')),
      'score_percent' => round(100 * array_sum(array_column($results, 'score')) / $count, 1),
      'average_seconds' => round(array_sum(array_column($results, 'seconds')) / $count, 1),
    ];
    if ($json) {
      $this->output()->writeln(json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    else {
      $this->output()->writeln(sprintf(
        "\nScore %s%% over %d cases (provider %s): %d ok, %d fell back, %d errors; %.1fs average.",
        $summary['score_percent'],
        $summary['cases'],
        $summary['provider'],
        $summary['ok'],
        $summary['fallback'],
        $summary['error'],
        $summary['average_seconds'],
      ));
    }

    $failUnder = is_numeric($options['fail-under']) ? (float) $options['fail-under'] : NULL;
    return $failUnder !== NULL && $summary['score_percent'] < $failUnder ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Prints the AI provider readiness report as JSON.
   */
  #[CLI\Command(name: 'fresco:ai:preflight')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:ai:preflight', description: 'Check AI provider and image analyzer readiness.')]
  public function preflight(): void {
    $this->output()->writeln(json_encode($this->preflightService->check(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Purges expired AI audit entries.
   */
  #[CLI\Command(name: 'fresco:ai:purge-audit')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:ai:purge-audit', description: 'Remove expired AI audit entries from state.')]
  public function purgeAudit(): void {
    $remaining = $this->usageLedger->purgeExpired();
    $this->output()->writeln(json_encode(['remaining' => $remaining], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Enqueues test AI requests for queue processing.
   */
  #[CLI\Command(name: 'fresco:ai:queue-test')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:ai:queue-test', description: 'Queue a generation test and an optional image-provider test.')]
  public function queueTest(): void {
    $queue = $this->queueFactory->get('fresco_ai_requests');
    $queue->createItem([
      'type' => 'generate_test',
      'request' => $this->processor->sampleGenerateRequest(),
      'uid' => (int) $this->currentUser->id(),
    ]);
    $queue->createItem([
      'type' => 'image_test',
      'provider' => (string) $this->configFactory->get('fresco_ai.settings')->get('image_provider'),
      'image_b64' => base64_encode($this->processor->sampleImageBytes()),
      'mime_type' => 'image/png',
      'uid' => (int) $this->currentUser->id(),
    ]);
    $this->output()->writeln(json_encode(['queued' => 2, 'queue' => 'fresco_ai_requests'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Runs a live vision-provider smoke test against a tiny PNG image.
   *
   * @param array{provider?: string|null} $options
   *   Command options.
   */
  #[CLI\Command(name: 'fresco:ai:vision-smoke')]
  #[CLI\Option(name: 'provider', description: 'Force the image provider id to test instead of the configured default.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Usage(name: 'drush fresco:ai:vision-smoke', description: 'Perform a live vision-provider smoke test if credentials are available.')]
  #[CLI\Usage(name: 'drush fresco:ai:vision-smoke --provider=openai', description: 'Force a live OpenAI vision smoke test when OpenAI credentials are configured.')]
  public function visionSmoke(array $options = ['provider' => NULL]): void {
    $config = $this->configFactory->get('fresco_ai.settings');
    $provider = $this->selectedProvider($options['provider'] ?? NULL, (string) $config->get('image_provider'));
    $ready = $this->liveSmokeReady($provider, $config);

    if (!$ready) {
      $this->output()->writeln(json_encode([
        'status' => 'skipped',
        'provider' => $provider,
        'reason' => 'vision provider is not configured for a live smoke test',
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      return;
    }

    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/gS8AAAAASUVORK5CYII=', TRUE);
    if (!is_string($image) || $image === '') {
      throw new \RuntimeException('Unable to create smoke-test image.');
    }

    $candidates = $this->analyzer->analyze($image, 'image/png');
    $this->output()->writeln(json_encode([
      'status' => 'ok',
      'provider' => $provider,
      'candidates' => $candidates,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Checks whether a live smoke test can run without immediately failing.
   */
  private function selectedProvider(mixed $forcedProvider, string $configuredProvider): string {
    $forcedProvider = trim((string) $forcedProvider);
    if (in_array($forcedProvider, ['openai', 'ollama'], TRUE)) {
      return $forcedProvider;
    }

    return $configuredProvider;
  }

  /**
   * Checks whether a live smoke test can run without immediately failing.
   */
  private function liveSmokeReady(string $provider, ImmutableConfig $config): bool {
    return match ($provider) {
      'openai' => trim((string) getenv('OPENAI_API_KEY')) !== '' && trim((string) $config->get('openai_vision_model')) !== '',
      'ollama' => $this->ollamaReachable((string) $config->get('ollama_base_url')) && trim((string) $config->get('ollama_model')) !== '',
      default => FALSE,
    };
  }

  /**
   * Probes Ollama for a reachable base URL before running the smoke test.
   */
  private function ollamaReachable(string $baseUrl): bool {
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('GET', rtrim($baseUrl, '/') . '/api/version', [
        'connect_timeout' => 2,
        'timeout' => 2,
        'http_errors' => FALSE,
      ]);
      return $response->getStatusCode() >= 200 && $response->getStatusCode() < 500;
    }
    catch (GuzzleException) {
      return FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Formats one evaluation result as a readable line plus failed checks.
   *
   * @param string $name
   *   Case name.
   * @param array<string, mixed> $result
   *   Evaluation result.
   */
  private function evalLine(string $name, array $result): string {
    $line = sprintf(
      '%-28s %-8s %3d%% %6.1fs  %s',
      $name,
      $result['outcome'],
      (int) round(100 * $result['score']),
      $result['seconds'],
      $result['outcome'] === 'error' ? $result['detail'] : '"' . $result['title'] . '"',
    );
    foreach ($result['checks'] as $check => $problems) {
      if (is_array($problems) && $problems !== []) {
        $line .= sprintf("\n%31s %s: %s", '', $check, implode('; ', $problems));
      }
    }
    if ($result['outcome'] === 'fallback') {
      $line .= sprintf("\n%31s fallback: %s", '', mb_strimwidth($result['detail'], 0, 160, '…'));
    }
    return $line;
  }

}
