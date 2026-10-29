<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Html;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\fresco_recipe\Mapper\RecipeNodeMapper;
use Drupal\fresco_recipe\Service\RecipeSvgBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports recipe contracts in portable formats.
 */
final class RecipeExportController extends ControllerBase {

  private const PDF_LEFT = 72;
  private const PDF_RIGHT = 540;
  private const PDF_TOP = 732;
  private const PDF_BOTTOM = 72;

  public function __construct(
    private readonly RecipeNodeMapper $nodeMapper,
    private readonly TransliterationInterface $transliteration,
    private readonly RecipeSvgBuilder $svgBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('fresco_recipe.node_mapper'),
      $container->get('transliteration'),
      $container->get('fresco_recipe.svg_builder'),
    );
  }

  /**
   * Exports a recipe.
   */
  public function export(NodeInterface $node, string $format): Response {
    if ($node->bundle() !== 'recipe') {
      throw new \InvalidArgumentException('Only recipes can be exported.');
    }
    $recipe = $this->nodeMapper->fromNode($node);
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $this->transliteration->transliterate($recipe->title)), '-'));
    $json = json_encode(
      $recipe->toArray(),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    [$extension, $contentType, $body] = match ($format) {
      'json' => ['json', 'application/json; charset=UTF-8', $json],
      'markdown' => ['md', 'text/markdown; charset=UTF-8', $this->markdown($recipe->toArray())],
      'html' => ['html', 'text/html; charset=UTF-8', $this->html($recipe->toArray())],
      'pdf' => ['pdf', 'application/pdf', $this->pdf($recipe->toArray())],
      'svg' => [
        'svg',
        'image/svg+xml; charset=UTF-8',
        $this->svgBuilder->build(
          $recipe->title,
          array_map(
            static fn (array $ingredient): string => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
            $recipe->ingredients,
          ),
        ),
      ],
      default => throw new \InvalidArgumentException('Unsupported export format.'),
    };
    return new Response($body, 200, [
      'Content-Type' => $contentType,
      'Content-Disposition' => 'attachment; filename="' . ($slug ?: 'fresco-recipe') . '.' . $extension . '"',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Creates a Markdown recipe document.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data.
   */
  private function markdown(array $recipe): string {
    $lines = ['# ' . $recipe['title'], '', (string) $recipe['description'], '', '## Ingredients'];
    foreach ($recipe['ingredients'] as $ingredient) {
      $lines[] = '- ' . ($ingredient['original_text'] ?? $ingredient['food'] ?? '');
    }
    $lines[] = '';
    $lines[] = '## Instructions';
    foreach ($recipe['instructions'] as $index => $instruction) {
      $lines[] = ((int) $index + 1) . '. ' . ($instruction['text'] ?? '');
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Creates a safe standalone HTML document that prints in portrait.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data.
   */
  private function html(array $recipe): string {
    $ingredients = '';
    foreach ($recipe['ingredients'] as $ingredient) {
      $ingredients .= '<li>' . Html::escape((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? '')) . '</li>';
    }
    $instructions = '';
    foreach ($recipe['instructions'] as $instruction) {
      $instructions .= '<li>' . Html::escape((string) ($instruction['text'] ?? '')) . '</li>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<title>' . Html::escape((string) $recipe['title']) . '</title>'
      . '<style>@page{size:letter portrait;margin:0.75in}'
      . 'body{max-width:42rem;margin:2rem auto;padding:0 1rem;font:16px/1.5 Helvetica,Arial,sans-serif;color:#111;background:#fff;overflow-wrap:anywhere}'
      . 'h1{font-size:1.6rem;margin:0 0 .5rem}h2{font-size:1.15rem;margin:1.5rem 0 .5rem}'
      . 'li{margin-bottom:.4rem}@media print{body{margin:0;max-width:none;padding:0;font-size:12pt}li{break-inside:avoid}}</style>'
      . '</head><body><h1>' . Html::escape((string) $recipe['title']) . '</h1>'
      . '<p>' . Html::escape((string) $recipe['description']) . '</p>'
      . '<h2>Ingredients</h2><ul>' . $ingredients . '</ul>'
      . '<h2>Instructions</h2><ol>' . $instructions . '</ol>'
      . '</body></html>';
  }

  /**
   * Creates a portrait letter-size PDF with wrapped text and page breaks.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data.
   */
  private function pdf(array $recipe): string {
    $pages = [];
    $content = '';
    $y = self::PDF_TOP;
    foreach ($this->pdfLines($recipe) as [$size, $x, $text, $marker]) {
      $leading = (int) round($size * 1.35);
      if ($content !== '' && $y - $leading < self::PDF_BOTTOM) {
        $pages[] = $content;
        $content = '';
        $y = self::PDF_TOP;
      }
      if ($marker !== '') {
        $content .= 'BT /F1 ' . $size . ' Tf 1 0 0 1 ' . self::PDF_LEFT . ' ' . $y . ' Tm (' . $this->pdfEscape($marker) . ") Tj ET\n";
      }
      if ($text !== '') {
        $content .= 'BT /F1 ' . $size . ' Tf 1 0 0 1 ' . $x . ' ' . $y . ' Tm (' . $this->pdfEscape($text) . ") Tj ET\n";
      }
      $y -= $text === '' ? (int) round($leading / 2) : $leading;
    }
    $pages[] = $content;

    $objects = [
      1 => '<< /Type /Catalog /Pages 2 0 R >>',
      3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $kids = [];
    foreach ($pages as $index => $pageContent) {
      $pageNumber = 4 + $index * 2;
      $kids[] = $pageNumber . ' 0 R';
      $objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . ($pageNumber + 1) . ' 0 R >>';
      $objects[$pageNumber + 1] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . 'endstream';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
      $offsets[$number] = strlen($pdf);
      $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
      $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";

    return $pdf;
  }

  /**
   * Builds wrapped PDF lines as font size, x offset, text, and list marker.
   *
   * An empty text entry is a half-line paragraph gap.
   *
   * @param array<string, mixed> $recipe
   *   Recipe data.
   *
   * @return array<int, array{0: int, 1: int, 2: string, 3: string}>
   *   PDF lines.
   */
  private function pdfLines(array $recipe): array {
    $lines = [];
    $add = function (string $text, int $size, string $marker = '') use (&$lines): void {
      $text = trim((string) preg_replace('/\s+/', ' ', $text));
      if ($text === '') {
        return;
      }
      $indent = $marker === '' ? 0 : 18;
      // Helvetica averages roughly half an em per character.
      $width = (int) floor((self::PDF_RIGHT - self::PDF_LEFT - $indent) / ($size * 0.52));
      foreach (explode("\n", wordwrap($text, $width, "\n", TRUE)) as $index => $line) {
        $lines[] = [$size, self::PDF_LEFT + $indent, $line, $index === 0 ? $marker : ''];
      }
      $lines[] = [$size, self::PDF_LEFT, '', ''];
    };

    $add((string) $recipe['title'], 18);
    $add((string) $recipe['description'], 11);
    $add('Ingredients', 14);
    foreach ($recipe['ingredients'] as $ingredient) {
      $add((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''), 11, '-');
    }
    $add('Instructions', 14);
    foreach ($recipe['instructions'] as $index => $instruction) {
      $add((string) ($instruction['text'] ?? ''), 11, ((int) $index + 1) . '.');
    }
    return $lines;
  }

  /**
   * Escapes PDF literal string content.
   */
  private function pdfEscape(string $text): string {
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return is_string($ascii) && $ascii !== '' ? $ascii : $text;
  }

}
