<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Service;

use Drupal\Component\Utility\Html;

/**
 * Builds deterministic, accessible recipe artwork without external assets.
 */
final class RecipeSvgBuilder {

  /**
   * Builds SVG artwork from a title and ingredient list.
   *
   * @param string $title
   *   Recipe title.
   * @param array<int, string> $ingredients
   *   Display-ready ingredient lines.
   */
  public function build(string $title, array $ingredients): string {
    $title = trim($title) !== '' ? trim($title) : 'Dresco recipe';
    $escapedTitle = Html::escape($title);
    $displayTitle = Html::escape(mb_strimwidth($title, 0, 46, '…'));
    $ingredientText = strtolower(implode(' ', $ingredients));
    $sauce = str_contains($ingredientText, 'coconut') ? '#efe1bd' : '#cf7e3f';
    $accent = str_contains($ingredientText, 'pepper') || str_contains($ingredientText, 'bonnet')
      ? '#e24b36'
      : '#f4c95d';
    $marks = $this->ingredientMarks($ingredients, $accent);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800" role="img" aria-labelledby="title desc">
  <title id="title">{$escapedTitle}</title>
  <desc id="desc">An illustrated bowl whose colors and shapes reflect this recipe's ingredients.</desc>
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#10201b"/>
      <stop offset="1" stop-color="#2f4534"/>
    </linearGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="150%">
      <feDropShadow dx="0" dy="20" stdDeviation="24" flood-color="#09110d" flood-opacity=".42"/>
    </filter>
  </defs>
  <rect width="1200" height="800" fill="url(#bg)"/>
  <circle cx="190" cy="145" r="64" fill="{$accent}" opacity=".24"/>
  <circle cx="1015" cy="165" r="96" fill="#5ab36c" opacity=".18"/>
  <circle cx="960" cy="650" r="72" fill="#9b5632" opacity=".2"/>
  <ellipse cx="600" cy="455" rx="410" ry="255" fill="#18221f" opacity=".55"/>
  <ellipse cx="600" cy="420" rx="390" ry="245" fill="#f7f1df" filter="url(#shadow)"/>
  <ellipse cx="600" cy="420" rx="318" ry="190" fill="{$sauce}"/>
  {$marks}
  <path d="M290 615 C430 700 770 700 910 615" fill="none" stroke="#fbf8ec" stroke-width="26" stroke-linecap="round" opacity=".65"/>
  <text x="600" y="735" fill="#f7f4e8" font-family="system-ui, sans-serif" font-size="42" font-weight="800" text-anchor="middle">{$displayTitle}</text>
</svg>
SVG;
  }

  /**
   * Builds ingredient marks for the center of the bowl.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines.
   * @param string $accent
   *   Palette accent color.
   */
  private function ingredientMarks(array $ingredients, string $accent): string {
    $points = [
      [460, 330], [575, 290], [710, 330], [405, 430], [545, 420],
      [680, 440], [800, 405], [500, 535], [635, 535], [735, 525],
    ];
    $marks = [];
    foreach (array_slice(array_values($ingredients), 0, count($points)) as $index => $ingredient) {
      [$x, $y] = $points[$index];
      $radius = 36 + (($index * 7) % 18);
      $color = $this->ingredientColor(strtolower($ingredient), $accent);
      $marks[] = '<circle cx="' . $x . '" cy="' . $y . '" r="' . $radius . '" fill="' . $color . '" opacity=".96"/>';
      $marks[] = '<circle cx="' . ($x - 12) . '" cy="' . ($y - 12) . '" r="' . max(8, (int) ($radius / 4)) . '" fill="#fff" opacity=".18"/>';
    }
    $marks[] = '<circle cx="812" cy="502" r="12" fill="#9b5632"/>';
    $marks[] = '<circle cx="365" cy="370" r="10" fill="#9b5632"/>';
    $marks[] = '<circle cx="624" cy="360" r="9" fill="#9b5632"/>';
    return implode("\n  ", $marks);
  }

  /**
   * Selects a visual ingredient family color.
   */
  private function ingredientColor(string $ingredient, string $accent): string {
    return match (TRUE) {
      str_contains($ingredient, 'potato') => '#d9b45d',
      str_contains($ingredient, 'cauliflower') => '#eee6cf',
      str_contains($ingredient, 'chickpea'),
      str_contains($ingredient, 'bean'),
      str_contains($ingredient, 'lentil') => '#8a3f35',
      str_contains($ingredient, 'rice') => '#f4ead0',
      str_contains($ingredient, 'mushroom') => '#9d7b5f',
      str_contains($ingredient, 'pepper'),
      str_contains($ingredient, 'bonnet') => $accent,
      default => '#5ab36c',
    };
  }

}
