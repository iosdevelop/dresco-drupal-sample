<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\fresco_pages\Service\GitHubPreflight;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures static publication without storing private credentials.
 */
final class PagesSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected GitHubPreflight $gitHubPreflight,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('fresco_pages.github_preflight'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string>
   *   Editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['fresco_pages.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fresco_pages_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array<string, mixed>
   *   Settings form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('fresco_pages.settings');
    $form['transport'] = [
      '#type' => 'radios',
      '#title' => $this->t('Publication transport'),
      '#options' => [
        'preview' => $this->t('Preview only (safe local default)'),
        'github' => $this->t('GitHub App — publish live'),
      ],
      '#default_value' => $config->get('transport'),
      '#required' => TRUE,
    ];
    $form['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical Pages URL'),
      '#default_value' => $config->get('site_url'),
      '#required' => TRUE,
    ];
    foreach ([
      'repository_owner' => $this->t('GitHub repository owner'),
      'repository_name' => $this->t('GitHub repository name'),
      'branch' => $this->t('Publication branch'),
      'github_app_id' => $this->t('GitHub App ID'),
      'github_installation_id' => $this->t('GitHub App installation ID'),
      'api_version' => $this->t('GitHub API version'),
    ] as $key => $title) {
      $form[$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get($key),
        '#states' => [
          'required' => [':input[name="transport"]' => ['value' => 'github']],
        ],
      ];
    }
    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('GitHub API URL'),
      '#default_value' => $config->get('api_url'),
      '#required' => TRUE,
    ];
    $form['credential'] = [
      '#type' => 'item',
      '#title' => $this->t('Private key'),
      '#markup' => $this->t('Provide the Base64-encoded PEM only through FRESCO_GITHUB_APP_PRIVATE_KEY_B64. It is never stored in Drupal configuration.'),
    ];
    $summary = $this->gitHubPreflight->check();
    $missing = $summary['missing'] === [] ? $this->t('None') : implode(', ', array_map('strval', $summary['missing']));
    $form['preflight_status'] = [
      '#type' => 'details',
      '#title' => $this->t('GitHub preflight status'),
      '#open' => TRUE,
      'status' => [
        '#markup' => '<p>' . $this->t('Status: @status', [
          '@status' => (string) $summary['status'],
        ]) . '</p>',
      ],
      'repository' => [
        '#markup' => '<p>' . $this->t('Repository: @repository · Branch: @branch', [
          '@repository' => (string) $summary['repository'],
          '@branch' => (string) $summary['branch'],
        ]) . '</p>',
      ],
      'missing' => [
        '#markup' => '<p>' . $this->t('Missing live requirements: @missing', [
          '@missing' => $missing,
        ]) . '</p>',
      ],
      'remote' => [
        '#markup' => '<p>' . $this->t('Remote checked: @checked. Run drush fresco:pages:github-preflight for JSON output.', [
          '@checked' => $summary['remote_checked'] ? $this->t('yes') : $this->t('no'),
        ]) . '</p>',
      ],
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('transport') === 'github') {
      foreach (['repository_owner', 'repository_name', 'branch', 'github_app_id', 'github_installation_id'] as $key) {
        if (trim((string) $form_state->getValue($key)) === '') {
          $form_state->setErrorByName($key, $this->t('This value is required for live GitHub publication.'));
        }
      }
      $candidate = [];
      foreach ([
        'transport', 'site_url', 'repository_owner', 'repository_name', 'branch',
        'github_app_id', 'github_installation_id', 'api_url', 'api_version',
      ] as $key) {
        $value = trim((string) $form_state->getValue($key));
        $candidate[$key] = in_array($key, ['site_url', 'api_url'], TRUE) ? rtrim($value, '/') : $value;
      }
      try {
        $summary = $this->gitHubPreflight->check(TRUE, $candidate);
        if (($summary['status'] ?? NULL) !== 'ok') {
          $form_state->setErrorByName('transport', $this->t('GitHub preflight failed: @status. Missing: @missing', [
            '@status' => (string) ($summary['status'] ?? 'unknown'),
            '@missing' => implode(', ', $summary['missing'] ?? []),
          ]));
        }
      }
      catch (\Throwable $exception) {
        $form_state->setErrorByName('transport', $this->t('GitHub preflight failed: @message', [
          '@message' => $exception->getMessage(),
        ]));
      }
    }
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
    $config = $this->configFactory->getEditable('fresco_pages.settings');
    foreach ([
      'transport', 'site_url', 'repository_owner', 'repository_name', 'branch',
      'github_app_id', 'github_installation_id', 'api_url', 'api_version',
    ] as $key) {
      $value = trim((string) $form_state->getValue($key));
      $config->set($key, in_array($key, ['site_url', 'api_url'], TRUE) ? rtrim($value, '/') : $value);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
