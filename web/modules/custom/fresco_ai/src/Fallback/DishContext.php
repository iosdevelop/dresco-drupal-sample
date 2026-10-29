<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Fallback;

/**
 * The ingredient list a fallback template is building, with flavor helpers.
 *
 * Templates refer to ingredients by short names ("black beans") and add any
 * flavor ingredient they use, so the method never names an unlisted food.
 */
final class DishContext {

  private const AROMATIC = '/\b(?:garlic|onions?|shallots?|scallions?|green onions?|leeks?|ginger|lemongrass)\b/';
  private const SPICE = '/\b(?:cumin|paprika|chil(?:i|e)|curry|turmeric|oregano|thyme|allspice|berbere|garam masala|coriander|cayenne|soy sauce|tamari|pepper flakes|italian seasoning|za\'?atar|spices?|black pepper)\b/';
  private const BRIGHT = '/\b(?:lime|lemon|vinegar)\b/';
  private const HERB = '/\b(?:cilantro|parsley|basil|mint|dill|chives)\b/';
  private const UNITS = '/^(?:(?:cups?|c|tbsp|tsp|tablespoons?|teaspoons?|oz|ounces?|lbs?|pounds?|grams?|g|cans?|jars?|cartons?|packages?|packets?|cloves?|heads?|bunch(?:es)?|large|medium|small|of)\s+)+/';

  /**
   * Ingredient lines paired with their short names.
   *
   * @var array<int, array{line: string, name: string}>
   */
  private array $items = [];

  /**
   * Constructs the context.
   *
   * @param array<int, string> $lines
   *   Recipe-ready ingredient lines.
   * @param array{aromatics: array<int, array{0: string, 1: string}>, spices: array<int, array{0: string, 1: string}>, bright: array{0: string, 1: string}, herb: array{0: string, 1: string}} $profile
   *   Cuisine flavor profile.
   * @param string $cuisine
   *   Requested cuisine, or "Global".
   */
  public function __construct(array $lines, private array $profile, public readonly string $cuisine) {
    foreach ($lines as $line) {
      $name = self::shortName($line);
      if ($name !== '') {
        $this->items[] = ['line' => $line, 'name' => $name];
      }
    }
  }

  /**
   * Switches the flavor profile, for dishes with a strong default style.
   *
   * @param array{aromatics: array<int, array{0: string, 1: string}>, spices: array<int, array{0: string, 1: string}>, bright: array{0: string, 1: string}, herb: array{0: string, 1: string}} $profile
   *   Flavor profile.
   */
  public function useProfile(array $profile): void {
    $this->profile = $profile;
  }

  /**
   * Returns all ingredient lines, including any added by the template.
   *
   * @return array<int, string>
   *   Ingredient lines.
   */
  public function lines(): array {
    return array_column($this->items, 'line');
  }

  /**
   * Returns the lowercase text of every ingredient line.
   */
  public function text(): string {
    return strtolower(implode(' ', $this->lines()));
  }

  /**
   * Returns short names, optionally filtered.
   *
   * @return array<int, string>
   *   Short names in list order.
   */
  public function names(?callable $filter = NULL): array {
    $names = array_column($this->items, 'name');
    return array_values($filter === NULL ? $names : array_filter($names, $filter));
  }

  /**
   * Returns short names of ingredients whose full line matches a pattern.
   *
   * @return array<int, string>
   *   Short names in list order.
   */
  public function namesWhereLine(string $pattern): array {
    return array_column(array_filter(
      $this->items,
      static fn (array $item): bool => preg_match($pattern, strtolower($item['line'])) === 1,
    ), 'name');
  }

  /**
   * Returns the short name of the first ingredient matching a pattern.
   */
  public function find(string $pattern): ?string {
    foreach ($this->items as $item) {
      if (preg_match($pattern, strtolower($item['line'])) === 1) {
        return $item['name'];
      }
    }
    return NULL;
  }

  /**
   * Adds an ingredient line unless one with the same name is listed.
   *
   * @param string $line
   *   Full ingredient line, such as "1 tbsp olive oil".
   * @param string $name
   *   Short name used in instructions, such as "olive oil".
   *
   * @return string
   *   The short name of the listed or added ingredient.
   */
  public function add(string $line, string $name): string {
    $core = self::shortName($line);
    foreach ($this->items as $item) {
      if ($item['name'] === $core || str_contains($item['name'], $core)) {
        return $item['name'];
      }
    }
    $this->items[] = ['line' => $line, 'name' => $name];
    return $name;
  }

  /**
   * Replaces the line of a listed ingredient, keeping its short name.
   */
  public function replace(string $name, string $line): void {
    foreach ($this->items as $index => $item) {
      if ($item['name'] === $name) {
        $this->items[$index]['line'] = $line;
        return;
      }
    }
  }

  /**
   * Returns up to N aromatics, adding profile aromatics when none are listed.
   *
   * @return array<int, string>
   *   Short names.
   */
  public function aromatic(int $count): array {
    return $this->flavor(self::AROMATIC, $this->profile['aromatics'], $count);
  }

  /**
   * Returns garlic, or another listed aromatic, adding garlic when none are.
   */
  public function garlic(): string {
    $listed = $this->find('/\bgarlic\b/') ?? $this->names(static fn (string $name): bool => preg_match(self::AROMATIC, $name) === 1)[0] ?? NULL;
    if ($listed !== NULL) {
      return $listed;
    }
    foreach ($this->profile['aromatics'] as $aromatic) {
      if ($aromatic[1] === 'garlic') {
        return $this->add(...$aromatic);
      }
    }
    return $this->add('2 cloves garlic, minced', 'garlic');
  }

  /**
   * Returns up to N seasonings, adding profile spices when none are listed.
   *
   * @return array<int, string>
   *   Short names.
   */
  public function spice(int $count): array {
    return $this->flavor(self::SPICE, $this->profile['spices'], $count);
  }

  /**
   * Returns a bright finishing ingredient, adding one when none is listed.
   */
  public function bright(): string {
    $listed = $this->find(self::BRIGHT);
    return $listed !== NULL ? $this->juiceName($listed) : $this->add(...$this->profile['bright']);
  }

  /**
   * Returns a fresh herb, adding one when none is listed.
   */
  public function herb(): string {
    return $this->find(self::HERB) ?? $this->add(...$this->profile['herb']);
  }

  /**
   * Returns the dish's main ingredient name for its title.
   */
  public function mainName(): string {
    $supporting = '/\b(?:oil|water|broth|stock|milk|sauce|vinegar|juice|salt|black pepper|pepper flakes|spice|powder|yeast|baking|flour|sugar|syrup|agave|vanilla|cinnamon|flax|chia|garlic|onions?|scallions?|shallots?|ginger|cilantro|parsley|basil|lime|lemon|cumin|paprika|turmeric|oregano|thyme|vegan (?:cheddar|cheese|feta|butter))\b/';
    foreach ($this->items as $item) {
      if (preg_match($supporting, $item['name']) !== 1) {
        return trim(preg_replace('/\b(?:ripe|fresh|frozen|raw|whole|dried|uncooked|canned)\b/', '', $item['name']) ?? $item['name']);
      }
    }
    return $this->items[0]['name'] ?? 'vegetables';
  }

  /**
   * Prefixes the requested cuisine and an evocative lead word to a title.
   */
  public function titled(string $title): string {
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    $title = $this->cuisine === 'Global' || stripos($title, $this->cuisine) !== FALSE ? $title : $this->cuisine . ' ' . $title;
    return TitleFlair::add($title, $this->cuisine);
  }

  /**
   * Sums measured cups across lines that mention a word.
   */
  public function cups(string $word): float {
    $total = 0.0;
    foreach ($this->lines() as $line) {
      $line = strtolower($line);
      if (str_contains($line, $word) && preg_match('/^\s*((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?)\s*(?:cups?|c)\b/', $line, $matches) === 1) {
        foreach (preg_split('/\s+/', $matches[1]) ?: [] as $part) {
          [$numerator, $denominator] = str_contains($part, '/')
            ? array_map('floatval', explode('/', $part, 2))
            : [(float) $part, 1.0];
          $total += $denominator > 0 ? $numerator / $denominator : 0.0;
        }
      }
    }
    return $total;
  }

  /**
   * Joins names as "a", "a and b", or "a, b, and c".
   *
   * @param array<int, string> $names
   *   Names to join.
   */
  public function join(array $names): string {
    $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    return match (count($names)) {
      0 => '',
      1 => $names[0],
      2 => $names[0] . ' and ' . $names[1],
      default => implode(', ', array_slice($names, 0, -1)) . ', and ' . end($names),
    };
  }

  /**
   * Reduces an ingredient line to its short name.
   *
   * "2 cans chickpeas, drained" becomes "chickpeas"; "14 oz firm tofu"
   * becomes "firm tofu".
   */
  public static function shortName(string $line): string {
    $name = strtolower(trim($line));
    $name = preg_replace('/\s*[(,].*$/', '', $name) ?? $name;
    $name = preg_replace('/^[\d\s\/.\-]+/', '', $name) ?? $name;
    $name = preg_replace(self::UNITS, '', $name) ?? $name;
    // "broccoli crown" is broccoli.
    $name = preg_replace('/\s+(?:crowns?|heads?|bunch(?:es)?|stalks?|florets?)$/', '', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
  }

  /**
   * Returns listed flavor ingredients, adding from the profile when none are.
   *
   * @param string $pattern
   *   Pattern for listed ingredients of this kind.
   * @param array<int, array{0: string, 1: string}> $defaults
   *   Profile lines and short names.
   * @param int $count
   *   Maximum number to return.
   *
   * @return array<int, string>
   *   Short names.
   */
  private function flavor(string $pattern, array $defaults, int $count): array {
    $listed = array_slice($this->names(static fn (string $name): bool => preg_match($pattern, $name) === 1), 0, $count);
    if ($listed !== []) {
      return $listed;
    }
    return array_map(fn (array $default): string => $this->add(...$default), array_slice($defaults, 0, $count));
  }

  /**
   * Names a listed lime or lemon as its juice.
   */
  private function juiceName(string $name): string {
    return preg_match('/^(?:lime|lemon)s?$/', $name) === 1 ? preg_replace('/s$/', '', $name) . ' juice' : $name;
  }

}
