<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Value;

/**
 * Creates validated recipe contracts from provider or migration data.
 */
final class RecipeContractFactory {

  /**
   * Creates a contract from normalized input.
   *
   * @param array<string, mixed> $data
   *   Normalized recipe input.
   * @param array<int, string> $ingredientHints
   *   Optional original ingredient lines used to preserve packaging state.
   */
  public function fromArray(array $data, array $ingredientHints = []): RecipeContract {
    $ingredients = is_array($data['ingredients'] ?? NULL) ? $this->completeIngredientLines($data['ingredients']) : [];
    if ($ingredientHints !== []) {
      $ingredients = $this->preserveIngredientForms($ingredients, $ingredientHints);
    }

    return new RecipeContract(
      title: $this->withoutDietLabel(trim((string) ($data['title'] ?? ''))),
      description: trim((string) ($data['description'] ?? '')),
      servings: (int) ($data['servings'] ?? 0),
      prepTimeMinutes: (int) ($data['prep_time_minutes'] ?? 0),
      cookTimeMinutes: (int) ($data['cook_time_minutes'] ?? 0),
      cuisine: trim((string) ($data['cuisine'] ?? 'Global')),
      category: trim((string) ($data['category'] ?? 'Vegan')),
      ingredients: $ingredients,
      instructions: is_array($data['instructions'] ?? NULL) ? $data['instructions'] : [],
      applianceSettings: is_array($data['appliance_settings'] ?? NULL) ? $data['appliance_settings'] : [],
      nutrition: is_array($data['nutrition'] ?? NULL) ? $data['nutrition'] : [],
      schemaVersion: (string) ($data['schema_version'] ?? RecipeContract::SCHEMA_VERSION),
    );
  }

  /**
   * Drops "vegan", "vegetarian", and "plant-based" from a title.
   *
   * Every Dresco recipe is vegan, so the label only crowds the dish name.
   */
  private function withoutDietLabel(string $title): string {
    $cleaned = preg_replace(['/\b(?:vegan|vegetarian|plant[- ]based)\b/i', '/\s{2,}/'], ['', ' '], $title) ?? $title;
    $cleaned = trim($cleaned, " \t-,:");
    return $cleaned === '' ? $title : $cleaned;
  }

  /**
   * Ensures each ingredient line names its food.
   *
   * Models sometimes put the food only in "food" and leave a bare "To taste"
   * or "2 tbsp" in the line; this rebuilds it as "salt, to taste".
   *
   * @param array<int, mixed> $ingredients
   *   Provider ingredients.
   *
   * @return array<int, mixed>
   *   Ingredients whose line mentions the food.
   */
  private function completeIngredientLines(array $ingredients): array {
    foreach ($ingredients as $index => $ingredient) {
      if (!is_array($ingredient)) {
        continue;
      }
      $food = trim((string) ($ingredient['food'] ?? ''));
      $line = trim((string) ($ingredient['original_text'] ?? ''));
      $words = preg_split('/[^a-z]+/', strtolower($food), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      $headWord = (string) end($words);
      if ($headWord === '' || str_contains(strtolower($line), rtrim($headWord, 's'))) {
        continue;
      }
      $ingredients[$index]['original_text'] = match (TRUE) {
        $line === '' => $food,
        preg_match('/^\s*[\d\/.\s]+\s*(?:[a-z]+\.?)?\s*$/i', $line) === 1 => $line . ' ' . $food,
        default => $food . ', ' . lcfirst($line),
      };
    }
    return $ingredients;
  }

  /**
   * Preserves explicit packaging state from the user's ingredient text.
   *
   * @param array<int, array<string, mixed>> $ingredients
   *   Provider-returned ingredient records.
   * @param array<int, string> $ingredientHints
   *   Raw ingredient lines from the user.
   *
   * @return array<int, array<string, mixed>>
   *   Ingredient records with preserved canned/package wording when relevant.
   */
  private function preserveIngredientForms(array $ingredients, array $ingredientHints): array {
    $cannedHints = [];
    foreach ($ingredientHints as $hint) {
      $hint = trim($hint);
      if ($hint === '') {
        continue;
      }
      if (!$this->isPackagedBeanHint($hint)) {
        continue;
      }
      $cannedHints[$this->ingredientKey($hint)] = $hint;
    }

    if ($cannedHints === []) {
      return $ingredients;
    }

    $usedIndices = [];
    foreach ($ingredients as $index => $ingredient) {
      $text = strtolower(trim((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? '')));
      if ($text === '' || !$this->looksLikeBeanIngredient($text) || $this->mentionsDryForm($text)) {
        continue;
      }

      $match = $cannedHints[$this->ingredientKey($text)] ?? NULL;
      if ($match === NULL) {
        foreach ($cannedHints as $hintIndex => $hint) {
          if (isset($usedIndices[$hintIndex])) {
            continue;
          }
          $match = $hint;
          $usedIndices[$hintIndex] = TRUE;
          break;
        }
      }
      if ($match === NULL) {
        continue;
      }

      $ingredients[$index]['food'] = $match;
      $ingredients[$index]['original_text'] = $match;
    }

    return $ingredients;
  }

  /**
   * Checks whether a hint expresses a canned/package bean form.
   */
  private function isPackagedBeanHint(string $hint): bool {
    $normalized = strtolower($hint);
    return preg_match('/\b(?:can|canned|jar|carton)\b/', $normalized) === 1
      && $this->looksLikeBeanIngredient($normalized)
      && !$this->mentionsDryForm($normalized);
  }

  /**
   * Checks whether ingredient text looks bean-like.
   */
  private function looksLikeBeanIngredient(string $text): bool {
    return preg_match('/\b(?:bean|beans|kidney bean|kidney beans|black bean|black beans|chickpea|chickpeas|lentil|lentils|pea|peas)\b/', $text) === 1;
  }

  /**
   * Checks whether ingredient text explicitly describes a dry form.
   */
  private function mentionsDryForm(string $text): bool {
    return preg_match('/\b(?:dry|dried|dehydrated|uncooked)\b/', $text) === 1;
  }

  /**
   * Builds a loose key for matching ingredient hints to provider output.
   */
  private function ingredientKey(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/\b(?:1|2|3|4|5|6|7|8|9|10|\d+\/\d+|\d+(?:\.\d+)?)\b/', ' ', $text) ?? $text;
    $text = preg_replace('/\b(?:can|canned|jar|carton|dry|dried|dehydrated|uncooked)\b/', ' ', $text) ?? $text;
    $text = preg_replace('/[^a-z]+/', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
  }

}
