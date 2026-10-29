<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fresco_ai\Service\AiPreflight;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures provider selection without storing API credentials.
 */
final class AiSettingsForm extends ConfigFormBase {

  public function __construct(
    protected AiPreflight $preflight,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('fresco_ai.preflight'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string>
   *   Editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['fresco_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_ai_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array<string, mixed>
   *   Settings form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('fresco_ai.settings');
    $report = $this->preflight->check();
    $labels = [
      'provider' => $this->t('Provider'),
      'provider_available' => $this->t('Provider available'),
      'provider_class' => $this->t('Provider class'),
      'openai_api_key_present' => $this->t('OpenAI API key present'),
      'image_provider' => $this->t('Image provider'),
      'image_provider_available' => $this->t('Image provider available'),
      'image_provider_configured' => $this->t('Image provider configured'),
      'rate_limit' => $this->t('Rate limit'),
      'usage' => $this->t('Usage'),
      'status' => $this->t('Status'),
      'missing' => $this->t('Missing'),
      'provider_error' => $this->t('Provider error'),
    ];
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Readiness'),
      '#open' => TRUE,
    ];
    foreach ($report as $key => $value) {
      $form['status'][$key] = [
        '#type' => 'item',
        '#title' => $labels[$key] ?? $this->t('Unknown'),
        '#markup' => $this->displayValue($value),
      ];
    }
    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Recipe provider'),
      '#options' => [
        'local' => $this->t('Deterministic local'),
        'openai' => $this->t('OpenAI'),
        'ollama' => $this->t('Ollama'),
      ],
      '#default_value' => $config->get('provider'),
      '#required' => TRUE,
    ];
    $form['openai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI model'),
      '#default_value' => $config->get('openai_model'),
      '#description' => $this->t('The API key must be provided through OPENAI_API_KEY, never Drupal configuration.'),
      '#required' => TRUE,
    ];
    $form['ollama_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ollama model'),
      '#default_value' => $config->get('ollama_model'),
      '#required' => TRUE,
    ];
    $form['ollama_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Ollama base URL'),
      '#default_value' => $config->get('ollama_base_url'),
      '#required' => TRUE,
    ];
    $form['image_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Ingredient image provider'),
      '#options' => [
        'openai' => $this->t('OpenAI'),
        'ollama' => $this->t('Ollama'),
      ],
      '#default_value' => $config->get('image_provider'),
      '#required' => TRUE,
    ];
    $form['openai_vision_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI vision model'),
      '#default_value' => $config->get('openai_vision_model'),
      '#required' => TRUE,
    ];
    $form['ollama_vision_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ollama vision model'),
      '#default_value' => $config->get('ollama_vision_model'),
      '#required' => TRUE,
    ];
    $form['request_rate_limit_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('AI request limit per hour'),
      '#default_value' => $config->get('request_rate_limit_per_hour') ?? 20,
      '#min' => 1,
      '#description' => $this->t('Applies to both recipe generation and ingredient photo analysis.'),
      '#required' => TRUE,
    ];
    $form['audit_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('AI audit retention days'),
      '#default_value' => $config->get('audit_retention_days') ?? 30,
      '#min' => 1,
      '#description' => $this->t('Older request records are purged from the lightweight audit ledger.'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('fresco_ai.settings')
      ->set('provider', $form_state->getValue('provider'))
      ->set('openai_model', trim((string) $form_state->getValue('openai_model')))
      ->set('ollama_model', trim((string) $form_state->getValue('ollama_model')))
      ->set('ollama_base_url', rtrim((string) $form_state->getValue('ollama_base_url'), '/'))
      ->set('image_provider', $form_state->getValue('image_provider'))
      ->set('openai_vision_model', trim((string) $form_state->getValue('openai_vision_model')))
      ->set('ollama_vision_model', trim((string) $form_state->getValue('ollama_vision_model')))
      ->set('request_rate_limit_per_hour', (int) $form_state->getValue('request_rate_limit_per_hour'))
      ->set('audit_retention_days', (int) $form_state->getValue('audit_retention_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Normalizes arbitrary readiness values for display.
   *
   * @param mixed $value
   *   Raw readiness value.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Readable display value.
   */
  private function displayValue(mixed $value): string|TranslatableMarkup {
    if (is_bool($value)) {
      return $value ? $this->t('Yes') : $this->t('No');
    }
    if ($value === NULL) {
      return $this->t('—');
    }
    if (is_array($value)) {
      if ($value === []) {
        return $this->t('—');
      }
      return nl2br(htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
