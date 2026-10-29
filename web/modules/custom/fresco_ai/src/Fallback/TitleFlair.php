<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Fallback;

/**
 * Gives plain fallback dish names an evocative lead word.
 *
 * "Black Bean Stew" becomes something like "Hearthside Black Bean Stew": the
 * plain dish word stays last so the name still says what is being made, and
 * the lead word is picked from the title itself so it is stable per dish.
 * Words avoid cooking verbs and dish names that validators read from titles.
 */
final class TitleFlair {

  private const MAX_WORDS = 5;

  private const POOLS = [
    'sweet' => ['Sunday Morning', 'Orchard', 'Sunbeam', 'Golden Hour', 'Daybreak'],
    'crisp' => ['Firecracker', 'Crackle', 'Spark', 'Bonfire'],
    'fresh' => ['Sunlit', 'Garden Party', 'Market Day', 'Breezy', 'Seaside', 'Bright Morning'],
    'warm' => ['Hearthside', 'Ember', 'Golden Hour', 'Slow Sunday', 'Firelight', 'Harvest Moon', 'Velvet', 'Lantern'],
  ];

  /**
   * Adds a lead word, dropping the cuisine first if the name runs long.
   *
   * @param string $title
   *   Plain dish title, possibly prefixed with the cuisine.
   * @param string $cuisine
   *   Cuisine prefix that may be dropped to stay within five words.
   */
  public static function add(string $title, string $cuisine = ''): string {
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    $pool = self::POOLS[self::mood(strtolower($title))];
    $word = $pool[crc32(strtolower($title)) % count($pool)];
    foreach ([$title, self::withoutCuisine($title, $cuisine)] as $candidate) {
      if (str_word_count($word . ' ' . $candidate, 0, '-') <= self::MAX_WORDS) {
        return $word . ' ' . $candidate;
      }
    }
    return $title;
  }

  /**
   * Picks the word pool that suits the dish.
   */
  private static function mood(string $title): string {
    return match (TRUE) {
      preg_match('/\b(?:fruit salad|smoothies?|oats|oatmeal|muffins?|bread|pudding)\b/', $title) === 1 => 'sweet',
      preg_match('/\b(?:crispy|air-fried|tacos)\b/', $title) === 1 => 'crisp',
      preg_match('/\b(?:salad|dip|steamed|scramble)\b/', $title) === 1 => 'fresh',
      default => 'warm',
    };
  }

  /**
   * Removes a leading cuisine prefix.
   */
  private static function withoutCuisine(string $title, string $cuisine): string {
    if ($cuisine === '' || $cuisine === 'Global' || stripos($title, $cuisine . ' ') !== 0) {
      return $title;
    }
    return substr($title, strlen($cuisine) + 1);
  }

}
