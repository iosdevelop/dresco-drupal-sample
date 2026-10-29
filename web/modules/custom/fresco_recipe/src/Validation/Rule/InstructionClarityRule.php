<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Requires instructions that say what is cooked and how.
 *
 * Catches steps such as "Seal and cook for 15 minutes" that never name the
 * food, "use your favorite technique" in place of a method, and pressure
 * cookers "heated to High Pressure" before any food or liquid goes in.
 * Instant Pot steps must put the named food in the pot before the program
 * starts, so no one reads "Pressure Cook on High" and misses what goes in.
 */
final class InstructionClarityRule implements ValidationRuleInterface {

  /**
   * Cooking verbs at sentence start, or after a comma, "and", or "then".
   */
  private const COOKING_COMMAND = '/(?:^|,\s*|\band\s+|\bthen\s+)(?:pressure[- ]cook|cook|heat|simmer|boil|steam|roast|bake|saut[eé]|fry|air[- ]fry|sear|toast|warm)\b/u';

  /**
   * Staple ingredients that must be added or cooked in some step.
   */
  private const STAPLE = '/\b(?:rice|quinoa|couscous|bulgur|farro|barley|pasta|spaghetti(?! squash)|penne|macaroni|noodles?|beans?|chickpeas?|lentils?|potato(?:es)?|tofu|tempeh)\b/';

  /**
   * Verbs that put a food into the dish or cook it.
   */
  private const USE_VERB = '/\b(?:add|stir|combine|place|put|transfer|pour|toss|fold|mix|layer|cook|simmer|roast|bake|saut[eé]|fry|fries|boil|steam|pressure[- ]cook|scatter|top|spoon|arrange|spread|fill|mash|blend|heat|warm|sear|crumble|press|shape|form|coat|season|marinate|divide)\w*\b/u';

  /**
   * Words that name what is in the pot without being an ingredient line.
   */
  private const CONTENTS = '/\b(?:mixture|filling|everything|contents|vegetables|veggies|batter|dough|sauce|stew|soup|curry|chili|pilaf|stir-fry|gravy|dumplings?|patty|patties|fritters?|cakes?|pies?|wrappers?|bowls?|rundown|scramble|oil|water|broth|stock|liquid|milk)\b/';

  /**
   * Instant Pot programs that cook whatever is sealed in the pot.
   */
  private const POT_PROGRAM = '/\b(?:pressure[- ]cook|slow[- ]cook)\b/';

  /**
   * The Steam program, as opposed to the steam rack or a stovetop steamer.
   */
  private const POT_STEAM = '/\bsteam\b(?!\s+(?:rack|basket|release|valve|setting|function))/';

  /**
   * Phrases that place the step in the Instant Pot.
   */
  private const POT_CONTEXT = '/\b(?:instant pot|inner pot|steam rack|trivet|lock the lid)\b/';

  /**
   * Verbs that load food into the pot.
   */
  private const LOAD_VERB = '/\b(?:add|put|place|pour|transfer|arrange|layer|combine|return|nestle|spread|set)\b/';

  /**
   * Solid contents words; liquids alone do not tell the cook what is cooking.
   */
  private const SOLID_CONTENTS = '/\b(?:mixture|filling|everything|contents|vegetables|veggies|batter|dough|sauce|stew|soup|curry|chili|pilaf|dumplings?|patty|patties|fritters?|cakes?|pies?|rundown)\b/';

  /**
   * Liquid words that never count as the food being cooked.
   */
  private const LIQUID = '/^(?:water|broth|stock|liquid)$/';

  /**
   * Ingredient-line words that never identify a food.
   */
  private const NON_FOOD = '/^(?:cups?|cans?|jars?|cartons?|packages?|bags?|boxes|ounces?|pounds?|lbs?|grams?|tablespoons?|teaspoons?|tbsp|tsp|cloves?|heads?|bunch(?:es)?|pinch|dash|large|medium|small|whole|fresh|raw|dried|frozen|canned|cooked|uncooked|drained|rinsed|peeled|chopped|diced|minced|sliced|shredded|grated|cubed|halved|trimmed|finely|roughly|thinly|and|or|the|for|with|into|plus|taste|divided|optional|about|each|more|serving)$/';

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $results = [];
    // The title names the dish, such as "dumplings", which no ingredient does.
    $foodWords = $this->foodWords([...$recipe->ingredients, ['original_text' => $recipe->title]]);
    foreach ($recipe->instructions as $index => $instruction) {
      $text = strtolower((string) ($instruction['text'] ?? ''));
      $step = (int) $index + 1;
      if (preg_match('/\b(?:your (?:favou?rite|preferred) (?:technique|method)|any (?:technique|method)|(?:technique|method) of your choice|however you like)\b/', $text) === 1) {
        $results[] = new ValidationResult(
          ruleId: 'instructions.vague_method',
          message: sprintf('Step %d leaves the cooking method up to the cook instead of giving one.', $step),
          component: 'instructions',
          remediation: 'Name one method with its food, time, and doneness cue, for example "Sauté the onions for 5 minutes until soft."',
        );
      }
      if (preg_match('/\b(?:pre)?heat\b[^.]*\binstant pot\b[^.]*\bto high pressure\b/', $text) === 1) {
        $results[] = new ValidationResult(
          ruleId: 'appliance.pressure_preheat',
          message: sprintf('Step %d heats the Instant Pot to High Pressure before anything is in it.', $step),
          component: 'instructions',
          remediation: 'Add the food and measured liquid, lock the lid, then Pressure Cook the named food on High for a set time.',
        );
      }
      if (preg_match('/\b(?:valve|sealing position)\b|\b(?:to|on) ["“]?sealing\b/', $text) === 1) {
        $results[] = new ValidationResult(
          ruleId: 'appliance.sealing_valve',
          message: sprintf('Step %d sets a sealing valve, but the Instant Pot Pro lid seals itself when locked.', $step),
          component: 'instructions',
          remediation: 'Say "lock the lid" and, after cooking, name Natural, Pulse, or Quick Release with the Venting button.',
        );
      }
      if (!$this->loadsPotBeforeProgram($text, $foodWords)) {
        $results[] = new ValidationResult(
          ruleId: 'appliance.unloaded_pot',
          message: sprintf('Step %d starts the Instant Pot without first saying what food goes into it.', $step),
          component: 'instructions',
          remediation: 'Start the step by putting the named food and measured liquid into the pot, for example "Put the potatoes, cauliflower, and 1 cup water into the Instant Pot, lock the lid, and Pressure Cook on High for 5 minutes." Never refer to "the main ingredients".',
        );
      }
      foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
        if (preg_match(self::COOKING_COMMAND, trim($sentence)) === 1 && !$this->namesFood($sentence, $foodWords) && !$this->preheatsAppliance($sentence)) {
          $results[] = new ValidationResult(
            ruleId: 'instructions.unnamed_food',
            message: sprintf('Step %d says "%s" without naming the food being cooked.', $step, trim($sentence)),
            component: 'instructions',
            remediation: 'Name the food in every cooking sentence, for example "Pressure cook the cabbage on High for 15 minutes."',
          );
        }
      }
    }
    foreach ($this->staplesNeverUsed($recipe) as $staple) {
      $results[] = new ValidationResult(
        ruleId: 'instructions.ingredient_never_added',
        message: sprintf('The %s is never added to the dish or cooked in any step.', $staple),
        component: 'instructions',
        remediation: sprintf('Add a step that puts the %s in the pot or pan, for example "Add the %s and 2 1/2 cups water to the inner pot."', $staple, $staple),
      );
    }
    return $results;
  }

  /**
   * Lists staple ingredients no step adds, cooks, or combines.
   *
   * Rinsing, draining, setting aside, and fluffing do not count, which
   * catches rice that is rinsed in step 1 and never reaches the pot.
   *
   * @return array<int, string>
   *   Staple names, such as "rice".
   */
  private function staplesNeverUsed(RecipeContract $recipe): array {
    $sentences = [];
    foreach ($recipe->instructions as $instruction) {
      foreach (preg_split('/(?<=[.!?])\s+/', strtolower((string) ($instruction['text'] ?? ''))) ?: [] as $sentence) {
        if (preg_match(self::USE_VERB, $sentence) === 1) {
          $sentences[] = $sentence;
        }
      }
    }
    $missing = [];
    foreach ($recipe->ingredients as $ingredient) {
      $line = strtolower((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''));
      if (preg_match(self::STAPLE, $line, $match) !== 1) {
        continue;
      }
      $staple = $this->singular($match[0]);
      $used = array_filter($sentences, fn (string $sentence): bool => preg_match('/\b' . preg_quote($staple, '/') . '(?:e?s)?\b/', $sentence) === 1);
      if ($used === []) {
        $missing[$staple] = $staple;
      }
    }
    return array_values($missing);
  }

  /**
   * Checks that an Instant Pot step loads named food before its program.
   *
   * @param string $text
   *   Lowercase instruction step.
   * @param array<string, true> $foodWords
   *   Food words keyed by their singular form.
   *
   * @return bool
   *   TRUE when the step runs no program or puts named food in first.
   */
  private function loadsPotBeforeProgram(string $text, array $foodWords): bool {
    // The last program mention is the one that starts cooking; earlier ones
    // can be headings such as "Steam the potatoes:".
    $offset = NULL;
    if (preg_match_all(self::POT_PROGRAM, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
      $offset = end($matches[0])[1];
    }
    if (preg_match(self::POT_CONTEXT, $text) === 1 && preg_match_all(self::POT_STEAM, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
      $offset = max($offset ?? 0, end($matches[0])[1]);
    }
    if ($offset === NULL) {
      return TRUE;
    }
    $before = substr($text, 0, $offset);
    if (preg_match(self::LOAD_VERB, $before, $match, PREG_OFFSET_CAPTURE) !== 1) {
      return FALSE;
    }
    $loaded = substr($before, $match[0][1]);
    if (preg_match(self::SOLID_CONTENTS, $loaded) === 1) {
      return TRUE;
    }
    preg_match_all('/\p{L}{3,}/u', $loaded, $matches);
    foreach ($matches[0] as $word) {
      $singular = $this->singular($word);
      if (isset($foodWords[$singular]) && preg_match(self::LIQUID, $singular) !== 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks whether a sentence only preheats an oven, air fryer, or pan.
   */
  private function preheatsAppliance(string $sentence): bool {
    return preg_match('/^\s*(?:pre)?heat (?:the |a )?(?:oven|air fryer|grill|griddle|skillet|pan|wok|sheet pan)\b/', $sentence) === 1;
  }

  /**
   * Collects singular food words from the ingredient lines.
   *
   * @param array<int, array<string, mixed>> $ingredients
   *   Ingredient records.
   *
   * @return array<string, true>
   *   Food words keyed by their singular form.
   */
  private function foodWords(array $ingredients): array {
    $words = [];
    foreach ($ingredients as $ingredient) {
      $line = strtolower((string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''));
      preg_match_all('/\p{L}{3,}/u', $line, $matches);
      foreach ($matches[0] as $word) {
        if (preg_match(self::NON_FOOD, $word) !== 1) {
          $words[$this->singular($word)] = TRUE;
        }
      }
    }
    return $words;
  }

  /**
   * Checks whether a sentence names an ingredient or the pot's contents.
   *
   * @param string $sentence
   *   Lowercase instruction sentence.
   * @param array<string, true> $foodWords
   *   Food words keyed by their singular form.
   */
  private function namesFood(string $sentence, array $foodWords): bool {
    if (preg_match(self::CONTENTS, $sentence) === 1) {
      return TRUE;
    }
    preg_match_all('/\p{L}{3,}/u', $sentence, $matches);
    foreach ($matches[0] as $word) {
      if (isset($foodWords[$this->singular($word)])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reduces a plural word to a comparable singular form.
   */
  private function singular(string $word): string {
    return preg_replace(['/ies$/', '/(o|ch|sh)es$/', '/(?<=[^s])s$/'], ['y', '$1', ''], $word) ?? $word;
  }

}
