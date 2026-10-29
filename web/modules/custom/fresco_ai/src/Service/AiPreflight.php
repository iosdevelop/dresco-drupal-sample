<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\AiProviderInterface;

/**
 * Reports whether Dresco AI providers are ready for use.
 */
final class AiPreflight {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiProviderManager $providerManager,
    private readonly AiRateLimiter $rateLimiter,
    private readonly AiUsageLedger $usageLedger,
  ) {}

  /**
   * Returns a read-only readiness report.
   *
   * @return array<string, mixed>
   *   Provider readiness report.
   */
  public function check(): array {
    $config = $this->configFactory->get('fresco_ai.settings');
    $providerId = (string) ($config->get('provider') ?: 'local');
    $imageProviderId = (string) ($config->get('image_provider') ?: 'ollama');
    $provider = NULL;
    $providerError = NULL;
    try {
      $provider = $this->provider($providerId);
    }
    catch (\Throwable $exception) {
      $providerError = $exception->getMessage();
    }

    $report = [
      'provider' => $providerId,
      'provider_available' => $provider instanceof AiProviderInterface && $provider->isAvailable(),
      'provider_class' => $provider instanceof AiProviderInterface ? $provider::class : NULL,
      'provider_error' => $providerError,
      'openai_api_key_present' => trim((string) getenv('OPENAI_API_KEY')) !== '',
      'image_provider' => $imageProviderId,
      'image_provider_available' => $this->imageProviderAvailable($imageProviderId, $config->get('openai_vision_model'), $config->get('ollama_vision_model'), $config->get('ollama_base_url')),
      'image_provider_configured' => $this->imageProviderConfigured($imageProviderId, $config->get('openai_vision_model'), $config->get('ollama_vision_model'), $config->get('ollama_base_url')),
      'rate_limit' => $this->rateLimiter->summary(),
      'usage' => $this->usageLedger->summary(),
    ];
    $report['status'] = $report['provider_available'] && $report['image_provider_available'] ? 'ok' : 'needs_configuration';
    $report['missing'] = array_values(array_filter([
      $report['provider_available'] ? NULL : 'generation_provider',
      $report['image_provider_available'] ? NULL : 'image_provider',
    ]));
    return $report;
  }

  /**
   * Loads and verifies a provider plugin.
   */
  private function provider(string $id): AiProviderInterface {
    $provider = $this->providerManager->createInstance($id);
    if (!$provider instanceof AiProviderInterface) {
      throw new \RuntimeException('Invalid AI provider: ' . $id);
    }
    return $provider;
  }

  /**
   * Checks whether the configured image provider is ready.
   */
  private function imageProviderAvailable(string $providerId, mixed $openAiVisionModel, mixed $ollamaModel, mixed $ollamaBaseUrl): bool {
    return match ($providerId) {
      'openai' => trim((string) getenv('OPENAI_API_KEY')) !== '' && trim((string) $openAiVisionModel) !== '',
      'ollama' => trim((string) $ollamaModel) !== '' && trim((string) $ollamaBaseUrl) !== '',
      default => FALSE,
    };
  }

  /**
   * Confirms the provider has the minimal config to attempt a call.
   */
  private function imageProviderConfigured(string $providerId, mixed $openAiVisionModel, mixed $ollamaModel, mixed $ollamaBaseUrl): bool {
    return match ($providerId) {
      'openai' => trim((string) $openAiVisionModel) !== '',
      'ollama' => trim((string) $ollamaModel) !== '' && trim((string) $ollamaBaseUrl) !== '',
      default => FALSE,
    };
  }

}
