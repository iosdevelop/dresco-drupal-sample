<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

/**
 * Rewrites non-vegan ingredient lines into explicit vegan substitutes.
 *
 * Quantities and preparation notes are kept; only the animal product is
 * replaced. Lines that already name a plant-based form are left alone.
 */
final class VeganSubstituter {

  /**
   * Ordered substitutions; more specific phrases come first.
   *
   * @var array<int, array{0: string, 1: string}>
   */
  private const SUBSTITUTIONS = [
    ['/\b(?:chicken|beef|turkey)\s+(broth|stock|bouillon)\b/i', 'vegetable $1'],
    ['/\bground\s+(?:beef|turkey|pork|chicken|lamb)\b/i', 'plant-based ground'],
    ['/\bfish\s+sauce\b/i', 'vegan fish sauce'],
    ['/\boyster\s+sauce\b/i', 'vegan oyster sauce'],
    ['/\bworcestershire(?:\s+sauce)?\b/i', 'vegan worcestershire sauce'],
    ['/\begg\s+noodles?\b/i', 'vegan egg-style noodles'],
    ['/\bcream\s+cheese\b/i', 'vegan cream cheese'],
    ['/\bsour\s+cream\b/i', 'vegan sour cream'],
    ['/\bice\s+cream\b/i', 'vegan ice cream'],
    ['/\bbuttermilk\b/i', 'vegan buttermilk (oat milk with 1 tbsp lemon juice per cup)'],
    ['/\b(?:heavy|whipping|double|single|light)\s+cream\b|\bcream\b(?!\s+of\s+tartar)/i', 'coconut cream'],
    ['/\b(?:greek\s+)?yogh?urt\b/i', 'plain coconut yogurt'],
    ['/(?:\b(?:whole|skim|dairy)\s+|\b2%\s+)?\bmilk\b/i', 'oat milk'],
    ['/\bgoat\s+cheese\b/i', 'vegan cheese (goat-style)'],
    [
      '/\b(cheddar|mozzarella|parmesan|parmigiano(?:-reggiano)?|feta|ricotta|gouda|swiss|monterey jack|pepper jack|provolone)(?:\s+cheese)?\b/i',
      'vegan $1',
    ],
    ['/\bcheese\b/i', 'vegan cheese'],
    ['/\bghee\b|\bbutter\b(?!\s+beans?)/i', 'vegan butter'],
    ['/\blard\b/i', 'vegetable shortening'],
    ['/\bhoney\b/i', 'maple syrup'],
    ['/\bgelatine?\b/i', 'agar powder'],
    ['/\bmayo(?:nnaise)?\b/i', 'vegan mayonnaise'],
    ['/\banchov(?:y|ies)\b/i', 'capers'],
    ['/\bbacon\b/i', 'tempeh bacon'],
    ['/\bsausages?\b/i', 'plant-based sausage'],
    [
      '/\b(?:boneless\s+)?(?:skinless\s+)?chicken(?:\s+(?:breasts?|thighs?|tenders?|wings?|drumsticks?))?\b/i',
      'extra-firm tofu',
    ],
    ['/\b(?:beef|steak|lamb|pork(?:\s+(?:chops?|loin|shoulder))?|ham)\b/i', 'seitan'],
    ['/\b(?:shrimp|prawns?|scallops?)\b/i', 'king oyster mushrooms'],
    ['/\b(?:salmon|tuna|cod|tilapia|halibut|white\s+fish|fish(?:\s+fillets?)?)\b/i', 'firm tofu'],
    ['/\begg\s+whites?\b/i', 'aquafaba (3 tbsp chickpea liquid each)'],
    ['/\begg\s+yolks\b/i', 'flax eggs (1 tbsp ground flaxseed + 3 tbsp water each)'],
    ['/\begg\s+yolk\b/i', 'flax egg (1 tbsp ground flaxseed + 3 tbsp water)'],
    ['/\beggs\b(?![- ]?(?:free|less|style))/i', 'flax eggs (1 tbsp ground flaxseed + 3 tbsp water each)'],
    ['/\begg\b(?![- ]?(?:free|less|style|plant))/i', 'flax egg (1 tbsp ground flaxseed + 3 tbsp water)'],
  ];

  /**
   * Words that, directly before a match, mark it as already plant-based.
   *
   * "Vegan" and its synonyms may sit one word back, as in "vegan ice cream".
   */
  private const PLANT_QUALIFIER = '/(?:(?:vegan|plant[- ]based|dairy[- ]free|non[- ]dairy)(?:[\s-]+[a-z]+)?|oat|almond|soy|coconut|cashew|rice|hemp|peanut|nut|seed|sunflower|cocoa|shea|apple|tofu|tempeh|seitan|mushroom|jackfruit|flax|chickpea|pea|vegetable|meatless|impossible|beyond)[\s-]*$/i';

  /**
   * Substitutes vegan forms into ingredient lines.
   *
   * @param array<int, string> $ingredients
   *   Ingredient lines as entered.
   *
   * @return array{ingredients: array<int, string>, swaps: array<int, array{from: string, to: string}>}
   *   Rewritten lines and the original-to-vegan pairs that changed.
   */
  public function substitute(array $ingredients): array {
    $result = ['ingredients' => [], 'swaps' => []];
    // Flax eggs bind batters; outside baking, eggs are better as tofu.
    $baking = preg_match('/\b(?:flour|baking (?:soda|powder)|oats)\b/i', implode(' ', array_map('strval', $ingredients))) === 1;
    foreach ($ingredients as $line) {
      $line = (string) $line;
      $vegan = $baking ? $line : $this->eggsAsTofu($line);
      foreach (self::SUBSTITUTIONS as [$pattern, $replacement]) {
        $vegan = preg_replace_callback(
          $pattern,
          static function (array $match) use ($vegan, $replacement): string {
            [$text, $offset] = $match[0];
            if (preg_match(self::PLANT_QUALIFIER, substr($vegan, 0, $offset)) === 1) {
              return $text;
            }
            return preg_replace_callback(
              '/\$(\d)/',
              static fn (array $group): string => (string) ($match[(int) $group[1]][0] ?? ''),
              $replacement,
            ) ?? $replacement;
          },
          $vegan,
          -1,
          $count,
          PREG_OFFSET_CAPTURE,
        ) ?? $vegan;
      }
      $vegan = trim(preg_replace('/\s+/', ' ', $vegan) ?? $vegan);
      $result['ingredients'][] = $vegan;
      if ($vegan !== trim(preg_replace('/\s+/', ' ', $line) ?? $line)) {
        $result['swaps'][] = ['from' => trim($line), 'to' => $vegan];
      }
    }
    return $result;
  }

  /**
   * Rewrites a counted egg line as crumbled firm tofu, about 3.5 oz per egg.
   */
  private function eggsAsTofu(string $line): string {
    if (preg_match('/^\s*(\d+)\s+(?:(?:large|medium|small|whole)\s+)?eggs?\b/i', $line, $matches) !== 1) {
      return $line;
    }
    return sprintf('%d oz firm tofu, crumbled', (int) round((int) $matches[1] * 3.5));
  }

}
