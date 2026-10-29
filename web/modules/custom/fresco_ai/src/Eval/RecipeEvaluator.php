<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Eval;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\fresco_ai\Service\GenerationCoordinator;

/**
 * Runs evaluation cases through the real generation pipeline and scores them.
 */
final class RecipeEvaluator {

  public function __construct(
    private readonly GenerationCoordinator $generationCoordinator,
    private readonly RecipeQualityChecker $checker,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Loads the evaluation cases.
   *
   * @return array<string, array{request: array<string, mixed>, title?: string}>
   *   Cases keyed by name.
   */
  public function cases(): array {
    $path = $this->moduleList->getPath('fresco_ai') . '/eval/cases.yml';
    $cases = Yaml::decode((string) file_get_contents($path));
    return is_array($cases) ? $cases : [];
  }

  /**
   * Evaluates one case.
   *
   * @param array{request: array<string, mixed>, title?: string} $case
   *   The case definition.
   * @param string|null $provider
   *   Provider override, or NULL for the configured provider.
   *
   * @return array{outcome: string, provider: string, seconds: float, title: string, checks: array<string, array<int, string>|null>, detail: string, score: float}
   *   The scored result. Outcome is "ok", "fallback", or "error".
   */
  public function evaluate(array $case, ?string $provider = NULL): array {
    $started = microtime(TRUE);
    try {
      $result = $this->generationCoordinator->generate($case['request'], $provider, FALSE);
    }
    catch (\Throwable $exception) {
      return [
        'outcome' => 'error',
        'provider' => $provider ?? '',
        'seconds' => microtime(TRUE) - $started,
        'title' => '',
        'checks' => [],
        'detail' => $exception->getMessage(),
        'score' => 0.0,
      ];
    }

    $request = $case['request'];
    if (is_array($request['ingredients'] ?? NULL)) {
      $swapped = array_column($result->substitutions, 'to', 'from');
      $request['ingredients'] = array_map(
        static fn (mixed $line): string => $swapped[(string) $line] ?? (string) $line,
        $request['ingredients'],
      );
    }
    $checks = $this->checker->check($request, $result->recipe, $case['title'] ?? NULL);
    $applicable = array_filter($checks, static fn (?array $problems): bool => $problems !== NULL);
    $passed = count(array_filter($applicable, static fn (array $problems): bool => $problems === []));

    return [
      'outcome' => $result->fallbackReason === NULL ? 'ok' : 'fallback',
      'provider' => $result->provider,
      'seconds' => microtime(TRUE) - $started,
      'title' => $result->recipe->title,
      'checks' => $checks,
      'detail' => (string) $result->fallbackReason,
      // A valid recipe earns one point, plus one per passed quality check.
      'score' => (1 + $passed) / (1 + count($applicable)),
    ];
  }

}
