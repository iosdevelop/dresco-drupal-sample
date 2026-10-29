<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\fresco_ai\Exception\RecipeGenerationException;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\AiProviderInterface;
use Drupal\fresco_ai\Value\GenerationResult;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Psr\Log\LoggerInterface;

/**
 * Selects a provider, validates its result, and performs safe fallback.
 */
final class GenerationCoordinator {

  /**
   * Remote provider attempts: the first draft plus one validation repair.
   */
  private const PROVIDER_ATTEMPTS = 2;

  public function __construct(
    private readonly DefaultPluginManager $providerManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RecipeContractFactory $contractFactory,
    private readonly RecipeValidator $recipeValidator,
    private readonly AiRateLimiter $rateLimiter,
    private readonly AiUsageLedger $usageLedger,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly VeganSubstituter $veganSubstituter = new VeganSubstituter(),
    private readonly SweetenerLimiter $sweetenerLimiter = new SweetenerLimiter(),
  ) {}

  /**
   * Generates a validated recipe with deterministic fallback.
   *
   * @param array<string, mixed> $request
   *   Bounded generator input.
   * @param string|null $providerOverride
   *   Provider to use instead of the configured one, for operator evaluation.
   * @param bool $rateLimited
   *   FALSE only for operator tooling such as the eval command.
   */
  public function generate(array $request, ?string $providerOverride = NULL, bool $rateLimited = TRUE): GenerationResult {
    $providerId = $providerOverride ?? (string) $this->configFactory->get('fresco_ai.settings')->get('provider');
    $providerId = $providerId !== '' ? $providerId : 'local';
    $fallbackReason = NULL;
    $uid = (int) $this->currentUser->id();

    if ($rateLimited) {
      $this->rateLimiter->assertAllowed('generate', $uid);
    }

    $substitutions = [];
    if (is_array($request['ingredients'] ?? NULL)) {
      $vegan = $this->veganSubstituter->substitute(array_map('strval', $request['ingredients']));
      $limited = $this->sweetenerLimiter->limit($vegan['ingredients']);
      $request['ingredients'] = $limited['ingredients'];
      $substitutions = array_merge(
        array_map(static fn (array $swap): array => $swap + ['reason' => 'vegan'], $vegan['swaps']),
        array_map(static fn (array $swap): array => $swap + ['reason' => 'sweetener'], $limited['swaps']),
      );
    }

    try {
      $provider = $this->provider($providerId);
      if (!$provider->isAvailable()) {
        throw new \RuntimeException('The configured provider is unavailable.');
      }
      $attemptRequest = $request;
      $attempts = $providerId === 'local' ? 1 : self::PROVIDER_ATTEMPTS;
      for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $draft = $provider->generate($attemptRequest);
        $recipe = $this->contractFactory->fromArray(
          $draft,
          is_array($request['ingredients'] ?? NULL) ? array_map(
            static fn (mixed $ingredient): string => trim((string) $ingredient),
            $request['ingredients'],
          ) : [],
        );
        $errors = $this->errorResults($this->recipeValidator->validate($recipe));
        if ($errors === []) {
          $this->usageLedger->record('generate', $providerId, $uid, 'ok');
          return new GenerationResult($recipe, $providerId, NULL, $substitutions);
        }
        $fallbackReason = implode(' ', array_map(static fn ($error): string => $error->message, $errors));
        // Give the model one chance to correct the specific validation errors.
        $attemptRequest = $request + [
          'revision_feedback' => [
            'previous_recipe' => $draft,
            'problems' => array_map(
              static fn ($error): string => trim($error->message . ' ' . ($error->remediation ?? '')),
              $errors,
            ),
          ],
        ];
      }
    }
    catch (\Throwable $exception) {
      $fallbackReason = $exception->getMessage();
    }

    $this->logger->warning('Recipe provider {provider} fell back to local: {reason}', [
      'provider' => $providerId,
      'reason' => $fallbackReason,
    ]);
    try {
      $fallbackRecipe = $this->provider('local')->generate($request);
    }
    catch (\RuntimeException $exception) {
      $this->usageLedger->record('generate', $providerId, $uid, 'error', $exception->getMessage());
      throw new RecipeGenerationException($exception->getMessage(), [], $providerId, $fallbackReason, $exception);
    }
    $recipe = $this->contractFactory->fromArray(
      $fallbackRecipe,
      is_array($request['ingredients'] ?? NULL) ? array_map(
        static fn (mixed $ingredient): string => trim((string) $ingredient),
        $request['ingredients'],
      ) : [],
    );
    $errors = $this->errorResults($this->recipeValidator->validate($recipe));
    if ($errors !== []) {
      $this->usageLedger->record('generate', $providerId, $uid, 'error', implode(' ', array_map(static fn ($error): string => $error->message, $errors)));
      throw new RecipeGenerationException(
        implode(' ', array_map(static fn ($error): string => $error->message, $errors)),
        $errors,
        $providerId,
        $fallbackReason,
      );
    }
    $this->usageLedger->record('generate', $providerId, $uid, 'fallback', $fallbackReason);
    return new GenerationResult($recipe, 'local', $fallbackReason, $substitutions);
  }

  /**
   * Keeps non-blocking validation warnings from aborting draft generation.
   *
   * @param array<int, \Drupal\fresco_recipe\Validation\ValidationResult> $results
   *   Validation results.
   *
   * @return array<int, \Drupal\fresco_recipe\Validation\ValidationResult>
   *   Blocking validation errors.
   */
  private function errorResults(array $results): array {
    return array_values(array_filter(
      $results,
      static fn ($result): bool => $result->severity === 'error',
    ));
  }

  /**
   * Loads and verifies a provider plugin.
   */
  private function provider(string $id): AiProviderInterface {
    $provider = $this->providerManager->createInstance($id);
    if (!$provider instanceof AiProviderInterface) {
      throw new \RuntimeException('Invalid recipe provider: ' . $id);
    }
    return $provider;
  }

}
