<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\Validation\Rule;

use Drupal\fresco_recipe\Validation\RecipeProfile;
use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;

/**
 * Requires aromatics, seasoning, brightness, and flavor-building technique.
 *
 * Sweet recipes instead need one flavor accent such as vanilla, warm spice,
 * citrus zest, or cocoa.
 */
final class FlavorStructureRule implements ValidationRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(RecipeContract $recipe): array {
    $text = strtolower(json_encode($recipe->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $parts = RecipeProfile::isSweet($recipe) ? [
      'sweet_accent' => [
        '/\b(?:vanilla|cinnamon|nutmeg|cardamom|ginger|cloves?|allspice|cocoa|cacao|chocolate|espresso|coffee|zest|lemon|lime|orange|almond extract|mint|sea salt|pinch of salt)\b/',
        'Add a flavor accent such as vanilla, cinnamon, cardamom, citrus zest, or cocoa.',
      ],
    ] : [
      'aromatic' => [
        '/\b(?:garlic|onions?|scallions?|ginger|shallots?|leeks?|lemongrass)\b/',
        'Add an aromatic such as garlic, onion, ginger, shallot, or scallion.',
      ],
      'seasoning' => [
        '/\b(?:allspice|thyme|curry|paprika|cumin|pepper|turmeric|herbs?|spices?|chil(?:i|e)s?|cayenne|oregano|basil|coriander|garam masala|cinnamon|soy sauce|tamari|miso|sumac)\b/',
        'Add a seasoning such as cumin, paprika, chili, dried herbs, or soy sauce.',
      ],
      'brightness' => [
        '/\b(?:lime|lemon|vinegar|citrus|zest|tamarind|fresh herbs?|cilantro|parsley)\b/',
        'Finish with something bright such as lime or lemon juice, vinegar, or fresh cilantro or parsley.',
      ],
      'technique' => [
        '/\b(?:bloom|saute|sauté|brown|toast|simmer|roast|char|air fr|caramelize|sear|stir-fr|grill|deglaze|reduc|crisp|marinat|macerat|massag|pickl|infus)\w*\b/u',
        'Include a flavor-building technique such as sautéing aromatics, toasting spices, searing, roasting, or marinating.',
      ],
    ];
    $results = [];
    foreach ($parts as $part => [$pattern, $remediation]) {
      if (preg_match($pattern, $text) !== 1) {
        $results[] = new ValidationResult(
          ruleId: 'flavor.' . $part,
          message: sprintf('Recipe needs complete flavor structure: missing %s.', $part),
          component: 'instructions',
          remediation: $remediation,
        );
      }
    }
    return $results;
  }

}
