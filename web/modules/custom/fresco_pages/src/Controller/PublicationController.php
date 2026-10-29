<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\fresco_pages\Entity\Publication;
use Drupal\fresco_pages\Service\PublicationManager;
use Drupal\fresco_pages\Service\SiteArtifactAssembler;
use Drupal\fresco_pages\Value\StaticArtifact;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Displays publication operations and private artifact previews.
 */
final class PublicationController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $frescoEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PublicationManager $publicationManager,
    private readonly SiteArtifactAssembler $siteAssembler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('fresco_pages.publisher'),
      $container->get('fresco_pages.site_assembler'),
    );
  }

  /**
   * Lists recent publication records and failures.
   *
   * @return array<string, mixed>
   *   Publication overview render array.
   */
  public function overview(): array {
    $storage = $this->frescoEntityTypeManager->getStorage('fresco_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, 100)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $publication) {
      if (!$publication instanceof Publication) {
        continue;
      }
      $id = (int) $publication->id();
      $error = trim((string) $publication->get('error')->value);
      $operation = (string) ($publication->get('operation')->value ?: 'publish');
      $state = (string) $publication->get('state')->value;
      $links = [
        'preview' => [
          'title' => $this->t('Preview'),
          'url' => Url::fromRoute('fresco_pages.preview', ['fresco_publication' => $id]),
        ],
        'retry' => [
          'title' => $this->t('Queue again'),
          'url' => Url::fromRoute('fresco_pages.retry', ['fresco_publication' => $id]),
        ],
      ];
      if ($operation === 'publish' && in_array($state, ['ready', 'published'], TRUE)) {
        $links['archive'] = [
          'title' => $this->t('Archive public page'),
          'url' => Url::fromRoute('fresco_pages.archive', ['fresco_publication' => $id]),
        ];
      }
      $rows[] = [
        'data' => [
          $publication->label(),
          (string) $publication->get('correlation_id')->value,
          ucfirst($operation),
          ucfirst($state),
          (int) $publication->get('attempts')->value,
          $this->dateFormatter->format((int) $publication->get('changed')->value, 'short'),
          $error !== '' ? $error : '—',
          [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ],
        ],
      ];
    }
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Each record is tied to one recipe revision and immutable content hash. Preview mode never writes to GitHub.') . '</p>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['fresco-actions']],
        'preview' => [
          '#type' => 'link',
          '#title' => $this->t('Preview complete site'),
          '#url' => Url::fromRoute('fresco_pages.site_preview'),
          '#attributes' => ['class' => ['button']],
        ],
        'queue_published' => [
          '#type' => 'link',
          '#title' => $this->t('Queue all published recipes'),
          '#url' => Url::fromRoute('fresco_pages.queue_published'),
          '#attributes' => ['class' => ['button']],
        ],
        'reconcile' => [
          '#type' => 'link',
          '#title' => $this->t('Check reconciliation'),
          '#url' => Url::fromRoute('fresco_pages.reconciliation'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Publication'), $this->t('Correlation'), $this->t('Operation'),
          $this->t('State'), $this->t('Attempts'), $this->t('Updated'), $this->t('Last error'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No recipe revisions have been queued.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Serves a private noindex preview of the stored artifact.
   */
  public function preview(Publication $fresco_publication): Response {
    $artifact = StaticArtifact::fromJson((string) $fresco_publication->get('artifact')->value);
    $htmlPath = 'recipes/' . $artifact->slug . '/index.html';
    $imagePath = 'assets/recipes/' . $artifact->slug . '.svg';
    if (!isset($artifact->files[$htmlPath])) {
      throw new \UnexpectedValueException('Publication preview files are incomplete.');
    }
    $html = $artifact->files[$htmlPath];
    if (isset($artifact->files[$imagePath])) {
      $imageData = 'data:image/svg+xml;base64,' . base64_encode($artifact->files[$imagePath]);
      $html = str_replace('../../' . $imagePath, $imageData, $html);
    }
    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'private, no-store',
      'X-Robots-Tag' => 'noindex, nofollow',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
    ]);
  }

  /**
   * Serves a private preview of the complete expected static site index.
   */
  public function sitePreview(): Response {
    $artifact = $this->siteAssembler->fullSite();
    $html = $artifact->files['index.html'];
    foreach ($artifact->files as $path => $contents) {
      if (str_starts_with($path, 'assets/recipes/') && str_ends_with($path, '.svg')) {
        $html = str_replace($path, 'data:image/svg+xml;base64,' . base64_encode($contents), $html);
      }
    }
    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'private, no-store',
      'X-Robots-Tag' => 'noindex, nofollow',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
    ]);
  }

  /**
   * Reports local state and remote-manifest drift when live mode is enabled.
   *
   * @return array<string, mixed>
   *   Reconciliation report render array.
   */
  public function reconciliation(): array {
    $summary = $this->publicationManager->reconcile();
    $rows = [];
    foreach ($summary as $key => $value) {
      if (is_array($value)) {
        $value = $value === [] ? '—' : implode(', ', array_map('strval', $value));
      }
      $rows[] = [
        str_replace('_', ' ', ucfirst((string) $key)),
        $value === NULL ? $this->t('Not checked in preview mode') : (is_bool($value) ? ($value ? $this->t('Yes') : $this->t('No')) : (string) $value),
      ];
    }
    return [
      'summary' => [
        '#type' => 'table',
        '#header' => [$this->t('Check'), $this->t('Result')],
        '#rows' => $rows,
      ],
      'repair' => [
        '#type' => 'link',
        '#title' => $this->t('Queue full-site repair'),
        '#url' => Url::fromRoute('fresco_pages.repair'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
