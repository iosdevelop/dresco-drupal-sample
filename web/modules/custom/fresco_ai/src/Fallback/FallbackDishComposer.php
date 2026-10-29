<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Fallback;

/**
 * Composes offline fallback recipes from dish templates.
 *
 * A template is chosen from the selected appliances and the ingredients, so a
 * blender request becomes a smoothie and an air-fryer request becomes crispy
 * air-fried vegetables instead of a simmered stew. Every flavor ingredient a
 * step uses is added to the ingredient list, never mentioned unlisted.
 *
 * @phpstan-type ComposedRecipe array{title: string, description: string, servings: int, prep_time_minutes: int, cook_time_minutes: int, ingredients: array<int, string>, instructions: array<int, string>, appliance_settings: array<int, array{appliance: string, mode: string, time: string}>}
 */
final class FallbackDishComposer {

  /**
   * Flavor profiles by cuisine; each item is [ingredient line, short name].
   *
   * @var array<string, array{aromatics: array<int, array{0: string, 1: string}>, spices: array<int, array{0: string, 1: string}>, bright: array{0: string, 1: string}, herb: array{0: string, 1: string}}>
   */
  private const PROFILES = [
    'mexican' => [
      'aromatics' => [['1 yellow onion, diced', 'onion'], ['3 cloves garlic, minced', 'garlic']],
      'spices' => [['1 tsp ground cumin', 'cumin'], ['1 tsp chili powder', 'chili powder']],
      'bright' => ['1 lime, juiced', 'lime juice'],
      'herb' => ['1/4 cup fresh cilantro, chopped', 'cilantro'],
    ],
    'indian' => [
      'aromatics' => [
        ['1 yellow onion, diced', 'onion'],
        ['3 cloves garlic, minced', 'garlic'],
        ['1 tbsp fresh ginger, grated', 'ginger'],
      ],
      'spices' => [['1 tsp ground cumin', 'cumin'], ['1/2 tsp ground turmeric', 'turmeric']],
      'bright' => ['1 lemon, juiced', 'lemon juice'],
      'herb' => ['1/4 cup fresh cilantro, chopped', 'cilantro'],
    ],
    'asian' => [
      'aromatics' => [
        ['3 cloves garlic, minced', 'garlic'],
        ['1 tbsp fresh ginger, grated', 'ginger'],
        ['3 scallions, sliced', 'scallions'],
      ],
      'spices' => [['1 tbsp low-sodium soy sauce', 'soy sauce'], ['1/2 tsp red chili flakes', 'chili flakes']],
      'bright' => ['1 lime, juiced', 'lime juice'],
      'herb' => ['1/4 cup fresh cilantro, chopped', 'cilantro'],
    ],
    'mediterranean' => [
      'aromatics' => [['1 shallot, minced', 'shallot'], ['3 cloves garlic, minced', 'garlic']],
      'spices' => [['1 tsp dried oregano', 'oregano'], ['1/2 tsp black pepper', 'black pepper']],
      'bright' => ['1 lemon, juiced', 'lemon juice'],
      'herb' => ['1/4 cup fresh parsley, chopped', 'parsley'],
    ],
    'caribbean' => [
      'aromatics' => [['3 cloves garlic, minced', 'garlic'], ['3 scallions, sliced', 'scallions']],
      'spices' => [['1 tsp dried thyme', 'thyme'], ['1/2 tsp ground allspice', 'allspice']],
      'bright' => ['1 lime, juiced', 'lime juice'],
      'herb' => ['1/4 cup fresh parsley, chopped', 'parsley'],
    ],
    'ethiopian' => [
      'aromatics' => [
        ['1 red onion, diced', 'onion'],
        ['3 cloves garlic, minced', 'garlic'],
        ['1 tbsp fresh ginger, grated', 'ginger'],
      ],
      'spices' => [['1 tbsp berbere spice', 'berbere'], ['1/2 tsp ground turmeric', 'turmeric']],
      'bright' => ['1 lemon, juiced', 'lemon juice'],
      'herb' => ['1/4 cup fresh parsley, chopped', 'parsley'],
    ],
    'global' => [
      'aromatics' => [['1 yellow onion, diced', 'onion'], ['3 cloves garlic, minced', 'garlic']],
      'spices' => [['1 tsp smoked paprika', 'smoked paprika'], ['1/2 tsp black pepper', 'black pepper']],
      'bright' => ['1 lemon, juiced', 'lemon juice'],
      'herb' => ['1/4 cup fresh parsley, chopped', 'parsley'],
    ],
  ];

  /**
   * Cuisine keywords mapped to a profile.
   */
  private const CUISINE_PROFILES = [
    '/mexic|tex-mex|latin|southwest/i' => 'mexican',
    '/india|south asian|pakistan|sri lank|nepal/i' => 'indian',
    '/thai|chinese|japan|korea|vietnam|asian|filipin|indonesia|malays/i' => 'asian',
    '/ital|greek|mediterr|spanish|french|middle east|lebanes|turk/i' => 'mediterranean',
    '/caribbean|jamaica|cuba|puerto|haiti|trinidad/i' => 'caribbean',
    '/ethiopia|eritrea|african|morocc/i' => 'ethiopian',
  ];

  private const FRUIT = '/\b(?:banana|berr(?:y|ies)|blueberr|strawberr|raspberr|mango|apple|pear|peach|pineapple|cherr(?:y|ies)|dates?)\w*/';
  private const GRAIN = '/\b(?:rice|quinoa|farro|barley|bulgur|couscous|millet)\b/';
  private const LEGUME = '/\b(?:beans?|chickpeas?|lentils?|peas)\b/';
  private const PROTEIN = '/\b(?:tofu|tempeh|seitan)\b/';
  private const CRISP_VEG = '/\b(?:broccoli|bell peppers?|peppers?|snap peas|snow peas|bok choy|carrots?|mushrooms?|zucchini|cabbage|green beans|asparagus)\b/';
  private const LIQUID = '/\b(?:water|broth|stock|milk)\b/';
  private const FAT = '/\b(?:oil|vegan butter)\b/';
  private const SWEETENER = '/\b(?:sugar|maple syrup|agave|date syrup|molasses)\b/';
  private const CHEESE = '/\b(?:cheese|cheddar|feta|mozzarella|parmesan|ricotta)\b/';
  private const SUPPORTING = '/\b(?:oil|butter|water|broth|stock|milk|sauce|vinegar|juice|salt|black pepper|pepper flakes|spice|powder|cumin|paprika|turmeric|oregano|thyme|yeast|baking|flour|sugar|syrup|agave|vanilla|cinnamon|flax|chia|garlic|onion|scallions?|shallot|ginger|cilantro|parsley|basil|lime|lemon)\b/';

  /**
   * Composes a recipe, or returns NULL to keep the provider's own path.
   *
   * @param array<int, string> $ingredients
   *   Recipe-ready ingredient lines.
   * @param array<int, string> $appliances
   *   Selected appliances.
   * @param string $cuisine
   *   Requested cuisine, or "Global".
   *
   * @return array<string, mixed>|null
   *   Recipe parts, or NULL when no template fits (such as Instant Pot).
   *
   * @phpstan-return ComposedRecipe|null
   */
  public function compose(array $ingredients, array $appliances, string $cuisine): ?array {
    if ($ingredients === [] || $appliances === [] || $this->selected($appliances, '/instant pot|pressure/i') !== NULL) {
      return NULL;
    }
    $dish = new DishContext($ingredients, $this->profile($cuisine), $cuisine);
    $text = $dish->text();

    $noCookOnly = array_filter($appliances, static fn (string $appliance): bool => preg_match('/no[- ]cook/i', $appliance) !== 1) === [];
    $blender = $this->selected($appliances, '/blender/i');
    $oven = $this->selected($appliances, '/(?<!fryer )oven/i');
    $riceCooker = $this->selected($appliances, '/rice cooker/i');
    $stovetop = $this->selected($appliances, '/stove/i');
    $airFryer = $this->selected($appliances, '/air fryer/i');

    return match (TRUE) {
      $noCookOnly => $this->noCook($dish, $appliances[0]),
      $blender !== NULL && preg_match(self::FRUIT, $text) === 1 => $this->smoothie($dish, $blender),
      $oven !== NULL && str_contains($text, 'flour') => $this->bake($dish, $oven),
      $riceCooker !== NULL && preg_match(self::GRAIN, $text) === 1 && !str_contains($text, 'arborio') => $this->riceCookerBowl($dish, $riceCooker),
      $stovetop !== NULL => $this->stovetop($dish, $stovetop),
      $airFryer !== NULL => $this->airFry($dish, $airFryer),
      $oven !== NULL => $this->roast($dish, $oven),
      $riceCooker !== NULL => $this->riceCookerSteam($dish, $riceCooker),
      $blender !== NULL => $this->dip($dish, $blender),
      default => NULL,
    };
  }

  /**
   * Returns the flavor profile used for a cuisine.
   *
   * @return array{aromatics: array<int, array{0: string, 1: string}>, spices: array<int, array{0: string, 1: string}>, bright: array{0: string, 1: string}, herb: array{0: string, 1: string}}
   *   Flavor profile.
   */
  public function profile(string $cuisine): array {
    foreach (self::CUISINE_PROFILES as $pattern => $profile) {
      if (preg_match($pattern, $cuisine) === 1) {
        return self::PROFILES[$profile];
      }
    }
    return self::PROFILES['global'];
  }

  /**
   * Builds a no-cook salad or overnight oats.
   *
   * @phpstan-return ComposedRecipe
   */
  private function noCook(DishContext $dish, string $appliance): array {
    $text = $dish->text();
    if (preg_match('/\boats?\b/', $text) === 1) {
      $liquid = $dish->find(self::LIQUID) ?? $dish->add('1 cup oat milk', 'oat milk');
      $fruit = $dish->find(self::FRUIT);
      $base = $dish->names(fn (string $name): bool => $name !== $liquid && $name !== $fruit);
      $vanilla = $dish->add('1/2 tsp vanilla extract', 'vanilla');
      $cinnamon = $dish->add('1/2 tsp ground cinnamon', 'cinnamon');
      return $this->recipe(
        $dish,
        ($fruit !== NULL ? ucwords($this->singular($fruit)) . ' ' : '') . 'Overnight Oats',
        'Creamy no-cook overnight oats warmed with vanilla and cinnamon.',
        [
          sprintf('Stir %s, %s, %s, and %s together in a jar or bowl.', $dish->join($base), $liquid, $vanilla, $cinnamon),
          sprintf('Cover and chill for at least 6 hours or overnight until thick and creamy, then loosen with a splash more %s if needed.', $liquid),
          $fruit !== NULL ? sprintf('Top with %s just before serving.', $fruit) : 'Spoon into bowls and serve cold.',
        ],
        [$appliance, 'Prep only', '10 minutes, then chill overnight'],
        servings: 2,
        prep: 10,
      );
    }

    $fruitOnly = $dish->names(fn (string $name): bool => preg_match(self::FRUIT, $name) !== 1) === [];
    if ($fruitOnly) {
      $bright = $dish->add('1 lime, juiced', 'lime juice');
      $mint = $dish->add('2 tbsp fresh mint, chopped', 'mint');
      return $this->recipe(
        $dish,
        TitleFlair::add('Fresh Fruit Salad'),
        'A bright no-cook fruit salad with lime and mint.',
        [
          sprintf('Cut %s into bite-size pieces and add to a large bowl.', $dish->join($dish->names())),
          sprintf('Toss with %s and %s and let macerate for 10 minutes so the juices meld.', $bright, $mint),
          'Serve chilled.',
        ],
        [$appliance, 'Prep only', '15 minutes'],
      );
    }

    $garlic = $dish->garlic();
    $spice = $dish->spice(1);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $oil = $dish->find(self::FAT) ?? $dish->add('2 tbsp olive oil', 'olive oil');
    $produce = $dish->names(fn (string $name): bool => preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->singular($dish->mainName())) . ' Salad'),
      'A no-cook vegan salad tossed in a bright, garlicky dressing.',
      [
        sprintf('Whisk %s, %s, %s, and %s into a quick dressing.', $bright, $oil, $garlic, $dish->join($spice)),
        sprintf('Combine %s in a large bowl, toss with the dressing, and let marinate for 10 minutes so the flavors meld.', $dish->join($produce)),
        sprintf('Finish with %s and toss again before serving.', $herb),
      ],
      [$appliance, 'Prep only', '15 minutes'],
    );
  }

  /**
   * Builds a fruit smoothie.
   *
   * @phpstan-return ComposedRecipe
   */
  private function smoothie(DishContext $dish, string $appliance): array {
    $liquid = $dish->find(self::LIQUID) ?? $dish->add('1 cup oat milk', 'oat milk');
    $vanilla = $dish->add('1/2 tsp vanilla extract', 'vanilla');
    $cinnamon = $dish->add('1/4 tsp ground cinnamon', 'cinnamon');
    $fruits = array_slice($dish->names(fn (string $name): bool => preg_match(self::FRUIT, $name) === 1), 0, 2);
    $rest = $dish->names(fn (string $name): bool => $name !== $liquid && $name !== $vanilla && $name !== $cinnamon);
    return $this->recipe(
      $dish,
      ucwords(implode(' ', array_map(fn (string $fruit): string => $this->singular($this->lastWords($fruit, 1)), $fruits))) . ' Smoothie',
      'A creamy vegan smoothie with a hint of vanilla and cinnamon.',
      [
        sprintf('Pour %s into the blender, then add %s, %s, and %s.', $liquid, $dish->join($rest), $vanilla, $cinnamon),
        sprintf('Blend on high for 45 to 60 seconds until completely smooth, adding a splash more %s if it is too thick.', $liquid),
        'Pour into 2 glasses and serve right away.',
      ],
      [$appliance, 'Blend on high', '1 minute'],
      servings: 2,
      prep: 5,
    );
  }

  /**
   * Builds a savory blended dip.
   *
   * @phpstan-return ComposedRecipe
   */
  private function dip(DishContext $dish, string $appliance): array {
    $garlic = $dish->garlic();
    $spice = $dish->spice(1);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $oil = $dish->find(self::FAT) ?? $dish->add('2 tbsp olive oil', 'olive oil');
    $base = $dish->names(fn (string $name): bool => preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->singular($dish->mainName())) . ' Dip'),
      'A smooth, garlicky vegan dip with a bright finish.',
      [
        sprintf('Let %s macerate in %s for 5 minutes to mellow.', $garlic, $bright),
        sprintf('Add %s, %s, and %s to the blender with 2 tablespoons cold water and blend until silky, about 1 minute.', $dish->join($base), $oil, $dish->join($spice)),
        sprintf('Spoon into a bowl and finish with %s.', $herb),
      ],
      [$appliance, 'Blend on high', '1 minute'],
      servings: 6,
      prep: 10,
    );
  }

  /**
   * Builds a flatbread from a yeasted dough, or a sweet bake.
   *
   * @phpstan-return ComposedRecipe
   */
  private function bake(DishContext $dish, string $appliance): array {
    $text = $dish->text();
    if (str_contains($text, 'yeast') && preg_match(self::FRUIT, $text) !== 1 && preg_match(self::SWEETENER, $text) !== 1) {
      return $this->flatbread($dish, $appliance);
    }

    $fruit = $dish->find(self::FRUIT);
    $oil = $dish->find(self::FAT) ?? $dish->add('1/3 cup olive oil', 'olive oil');
    $leavening = $dish->find('/\bbaking (?:soda|powder)\b/') ?? $dish->add('1 tsp baking powder', 'baking powder');
    $vanilla = $dish->add('1 tsp vanilla extract', 'vanilla');
    $cinnamon = $dish->add('1 tsp ground cinnamon', 'cinnamon');
    $wet = $dish->names(fn (string $name): bool => preg_match('/\b(?:oil|butter|milk|syrup|sugar|agave|flax|applesauce)\b/', $name) === 1);
    $flour = (string) $dish->find('/\bflour\b/');
    $isBread = $fruit !== NULL && str_contains($fruit, 'banana');
    return $this->recipe(
      $dish,
      $isBread ? 'Banana Bread' : ($fruit !== NULL ? ucwords($this->singular($this->lastWords($fruit, 1))) . ' ' : '') . 'Snack Cake',
      'A tender vegan bake sweetened mostly with fruit and warmed with vanilla and cinnamon.',
      [
        'Heat the oven to 350°F and line a 9 x 5-inch loaf pan with parchment.',
        sprintf(
          '%s in a large bowl, then whisk in %s.',
          $fruit !== NULL ? 'Mash ' . $fruit : 'Whisk',
          $dish->join(array_values(array_unique([...$wet, $oil, $vanilla, $cinnamon]))),
        ),
        sprintf('Fold in %s and %s just until no dry streaks remain.', $flour, $leavening),
        'Scrape the batter into the pan and bake for 50 to 60 minutes, until a toothpick in the center comes out clean, then cool 15 minutes before slicing.',
      ],
      [$appliance, 'Bake at 350°F', '55 minutes'],
      servings: 8,
      cook: 55,
    );
  }

  /**
   * Builds a yeasted flatbread with enough water for a workable dough.
   *
   * @phpstan-return ComposedRecipe
   */
  private function flatbread(DishContext $dish, string $appliance): array {
    $flourCups = $dish->cups('flour');
    $waterCups = $dish->cups('water');
    $water = $dish->find('/\bwater\b/');
    // Dough needs at least 1 cup water per 2 cups flour.
    $neededCups = max(0.5, ceil($flourCups / 2 * 4) / 4);
    if ($water === NULL) {
      $water = $dish->add($this->cupsLine($neededCups) . ' warm water', 'warm water');
    }
    elseif ($waterCups < $flourCups / 2) {
      $dish->replace($water, $this->cupsLine($neededCups) . ' ' . $water);
    }
    $oil = $dish->find(self::FAT) ?? $dish->add('2 tbsp olive oil', 'olive oil');
    $garlic = $dish->garlic();
    $spice = $dish->spice(1);
    $bright = $dish->bright();
    $herb = $dish->herb();
    return $this->recipe(
      $dish,
      $dish->titled('Garlic Herb Flatbread'),
      'Soft, blistered vegan flatbreads brushed with garlic oil and finished bright.',
      [
        sprintf('Stir %s into the %s and let it bloom for 5 minutes until foamy.', (string) $dish->find('/\byeast\b/'), $water),
        sprintf('Mix in %s and half of the %s to form a soft dough, adding water a tablespoon at a time if dry or flour if sticky; knead 5 minutes, cover, and rest 1 hour until doubled.', (string) $dish->find('/\bflour\b/'), $oil),
        'Heat the oven to 475°F with a sheet pan inside. Divide the dough into 6 pieces, roll each thin, and bake on the hot pan for 6 to 8 minutes until puffed and browned in spots.',
        sprintf('Brush with the remaining %s mixed with %s and %s, then finish with %s and %s.', $oil, $garlic, $dish->join($spice), $bright, $herb),
      ],
      [$appliance, 'Bake at 475°F', '8 minutes per batch'],
      servings: 6,
      prep: 75,
      cook: 16,
    );
  }

  /**
   * Builds a sheet-pan roast.
   *
   * @phpstan-return ComposedRecipe
   */
  private function roast(DishContext $dish, string $appliance): array {
    $oil = $dish->find(self::FAT) ?? $dish->add('2 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    // Legumes and crumbled tofu are scattered over the pan, not cut.
    $legumes = array_values(array_unique([
      ...$dish->names(fn (string $name): bool => preg_match(self::LEGUME, $name) === 1),
      ...$dish->namesWhereLine('/\bcrumbled\b/'),
    ]));
    $cheeses = $dish->names(fn (string $name): bool => preg_match(self::CHEESE, $name) === 1);
    $toppings = [...$legumes, ...$cheeses];
    $produce = $dish->names(fn (string $name): bool => preg_match(self::SUPPORTING, $name) !== 1 && !in_array($name, $toppings, TRUE));
    return $this->recipe(
      $dish,
      $dish->titled('Roasted ' . ucwords($dish->mainName())),
      'Sheet-pan roasted vegetables with browned edges, warm spice, and a bright finish.',
      [
        'Heat the oven to 425°F and line a sheet pan with parchment.',
        sprintf('Cut %s into even 1-inch pieces, toss with %s, %s, and %s, and spread in a single layer.', $dish->join($produce ?: $legumes), $oil, $dish->join($aromatics), $dish->join($spices)),
        sprintf('Roast the %s for 25 to 30 minutes, flipping once, until browned at the edges and fork-tender%s%s.', $dish->join($produce ?: $legumes), $legumes !== [] && $produce !== [] ? sprintf('; scatter %s over the pan for the last 10 minutes', $dish->join($legumes)) : '', $cheeses !== [] ? sprintf(', sprinkling %s over the top for the last 5 minutes', $dish->join($cheeses)) : ''),
        sprintf('Finish with %s and %s.', $bright, $herb),
      ],
      [$appliance, 'Roast at 425°F', '30 minutes'],
      cook: 30,
    );
  }

  /**
   * Builds crispy air-fried vegetables or tofu.
   *
   * @phpstan-return ComposedRecipe
   */
  private function airFry(DishContext $dish, string $appliance): array {
    $text = $dish->text();
    $minutes = match (TRUE) {
      preg_match('/\bpotato/', $text) === 1 => 22,
      preg_match(self::PROTEIN, $text) === 1, preg_match('/\bchickpea/', $text) === 1 => 16,
      default => 14,
    };
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $garlic = $dish->garlic();
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $produce = $dish->names(fn (string $name): bool => preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled('Crispy Air-Fried ' . ucwords($dish->mainName())),
      'Golden, crisp air-fried vegetables tossed with garlic and spice, finished bright.',
      [
        sprintf('Cut %s into even 1-inch pieces and pat dry, then toss with %s, %s, and %s and let marinate for 5 minutes.', $dish->join($produce), $oil, $garlic, $dish->join($spices)),
        sprintf('Air fry the %s at 400°F for %d to %d minutes, shaking the basket halfway, until golden, crisp, and tender inside.', $dish->join($produce), $minutes - 3, $minutes),
        sprintf('Toss with %s and %s and serve hot.', $bright, $herb),
      ],
      [$appliance, 'Air Fry at 400°F', $minutes . ' minutes'],
      cook: $minutes,
    );
  }

  /**
   * Builds a rice-cooker grain bowl.
   *
   * @phpstan-return ComposedRecipe
   */
  private function riceCookerBowl(DishContext $dish, string $appliance): array {
    $grain = (string) $dish->find(self::GRAIN);
    $grainCups = max(1.0, $dish->cups($this->lastWords($grain, 1)));
    $isBrown = str_contains($grain, 'brown') || str_contains($grain, 'wild');
    $waterCups = $grainCups * ($isBrown ? 2.0 : 1.5);
    $liquid = $dish->find('/\b(?:broth|stock)\b/') ?? $dish->add($this->cupsLine($waterCups) . ' water', $this->cupsLine($waterCups) . ' water');
    $garlic = $dish->garlic();
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $mixIns = $dish->names(fn (string $name): bool => $name !== $grain && preg_match(self::SUPPORTING, $name) !== 1);
    $legume = $dish->find(self::LEGUME);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->lastWords($grain, 2)) . ($legume !== NULL ? ' and ' . ucwords($this->singular($this->lastWords($legume, 2))) : '') . ' Bowl'),
      'A one-pot rice cooker bowl with spiced grains and hearty mix-ins.',
      [
        sprintf('Rinse %s until the water runs clear, then add it to the rice cooker with %s, %s, and %s; stir once so the spices bloom evenly.', $grain, $liquid, $garlic, $dish->join($spices)),
        sprintf('Run the %s cycle, about %d minutes.', $isBrown ? 'brown rice' : 'white rice', $isBrown ? 45 : 25),
        $mixIns !== [] ? sprintf('As soon as the cycle ends, stir in %s, close the lid, and rest 10 minutes to heat through.', $dish->join($mixIns)) : 'Let rest with the lid closed for 10 minutes.',
        sprintf('Fluff with a fork and finish with %s and %s.', $bright, $herb),
      ],
      [$appliance, $isBrown ? 'Brown rice cycle' : 'White rice cycle', ($isBrown ? 45 : 25) . ' minutes'],
      cook: $isBrown ? 55 : 35,
    );
  }

  /**
   * Builds vegetables steamed in the rice cooker basket.
   *
   * @phpstan-return ComposedRecipe
   */
  private function riceCookerSteam(DishContext $dish, string $appliance): array {
    $water = $dish->add('1 cup water', '1 cup water');
    $garlic = $dish->garlic();
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $produce = $dish->names(fn (string $name): bool => preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled('Steamed ' . ucwords($dish->mainName()) . ' with Garlic'),
      'Rice cooker steamed vegetables tossed in garlic, spice, and citrus.',
      [
        sprintf('Add %s to the rice cooker, set %s in the steamer basket, and steam for 15 to 20 minutes until fork-tender.', $water, $dish->join($produce)),
        sprintf('Toss the hot vegetables with %s and %s and let them marinate for 5 minutes.', $garlic, $dish->join($spices)),
        sprintf('Finish with %s and %s.', $bright, $herb),
      ],
      [$appliance, 'Steam', '20 minutes'],
      cook: 20,
    );
  }

  /**
   * Chooses a stovetop dish: risotto, tacos, soup, stir-fry, pilaf, or skillet.
   *
   * @phpstan-return ComposedRecipe
   */
  private function stovetop(DishContext $dish, string $appliance): array {
    $text = $dish->text();
    return match (TRUE) {
      str_contains($text, 'arborio') => $this->risotto($dish, $appliance),
      str_contains($text, 'tortilla') => $this->tacos($dish, $appliance),
      preg_match('/\btofu\b[^|]*\bcrumbled\b/', $text) === 1 => $this->scramble($dish, $appliance),
      str_contains($text, 'lentil') || $dish->cups('broth') + $dish->cups('stock') >= 2 => $this->soup($dish, $appliance),
      preg_match(self::PROTEIN, $text) === 1 || str_contains($text, 'soy sauce') || preg_match_all(self::CRISP_VEG, $text) >= 2 => $this->stirFry($dish, $appliance),
      preg_match(self::GRAIN, $text) === 1 => $this->pilaf($dish, $appliance),
      default => $this->skillet($dish, $appliance),
    };
  }

  /**
   * Builds a stirred risotto.
   *
   * @phpstan-return ComposedRecipe
   */
  private function risotto(DishContext $dish, string $appliance): array {
    $rice = (string) $dish->find('/\barborio\b/');
    $broth = $dish->find('/\b(?:broth|stock)\b/') ?? $dish->add('5 cups vegetable broth', 'vegetable broth');
    $oil = $dish->find(self::FAT) ?? $dish->add('2 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $spice = $dish->spice(1);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $vegetables = $dish->names(fn (string $name): bool => $name !== $rice && $name !== $broth && preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled(($vegetables !== [] ? ucwords($this->singular($this->lastWords($vegetables[0], 1))) . ' ' : '') . 'Risotto'),
      'A creamy, stirred vegan risotto with browned vegetables and a bright finish.',
      [
        sprintf('Warm %s in a small pot over low heat.', $broth),
        sprintf('Sauté %s%s in %s over medium heat for 6 to 8 minutes until browned.', $dish->join($aromatics), $vegetables !== [] ? ' and ' . $dish->join($vegetables) : '', $oil),
        sprintf('Add %s and toast for 2 minutes, then add the warm broth 1/2 cup at a time, stirring until each addition is absorbed, for 18 to 20 minutes until creamy and just tender.', $rice),
        sprintf('Stir in %s, then finish with %s and %s.', $dish->join($spice), $bright, $herb),
      ],
      [$appliance, 'Medium heat, stirring', '30 minutes'],
      cook: 30,
    );
  }

  /**
   * Builds skillet tacos.
   *
   * @phpstan-return ComposedRecipe
   */
  private function tacos(DishContext $dish, string $appliance): array {
    $tortillas = (string) $dish->find('/\btortillas?\b/');
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $toppings = $dish->names(fn (string $name): bool => preg_match('/\b(?:avocado|salsa|lettuce|cabbage|radish|tomato)\w*/', $name) === 1);
    $filling = $dish->names(fn (string $name): bool => $name !== $tortillas && !in_array($name, $toppings, TRUE) && preg_match(self::SUPPORTING, $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->singular($this->lastWords($dish->mainName(), 2))) . ' Tacos'),
      'Smoky skillet tacos with a spiced filling and fresh toppings.',
      [
        sprintf('Sauté %s in %s over medium heat for 4 minutes until softened, then add %s and toast for 30 seconds until fragrant.', $dish->join($aromatics), $oil, $dish->join($spices)),
        sprintf('Add %s with 2 tablespoons water, mash about a third, and simmer for 5 minutes until thick.', $dish->join($filling)),
        sprintf('Warm %s in a dry skillet for 30 seconds per side.', $tortillas),
        sprintf('Fill the tortillas%s and finish with %s and %s.', $toppings !== [] ? ', top with ' . $dish->join($toppings) . ',' : '', $bright, $herb),
      ],
      [$appliance, 'Medium heat', '15 minutes'],
      cook: 15,
    );
  }

  /**
   * Builds a tofu scramble, the stovetop stand-in for eggs.
   *
   * @phpstan-return ComposedRecipe
   */
  private function scramble(DishContext $dish, string $appliance): array {
    $tofu = (string) $dish->find('/\btofu\b/');
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $turmeric = $dish->add('1/2 tsp ground turmeric', 'turmeric');
    $pepper = $dish->add('1/4 tsp black pepper', 'black pepper');
    $herb = $dish->herb();
    $bright = $dish->bright();
    $greens = $dish->names(fn (string $name): bool => preg_match('/\b(?:spinach|kale|chard|greens|arugula|tomato\w*|peppers?|mushrooms?)\b/', $name) === 1 && !str_contains($name, 'black pepper'));
    $milk = $dish->find('/\bmilk\b/');
    $toppings = $dish->names(fn (string $name): bool => preg_match('/\b(?:cheese|feta|cheddar|avocado|salsa)\b/', $name) === 1);
    return $this->recipe(
      $dish,
      $dish->titled(($greens !== [] ? ucwords($this->singular($this->lastWords($greens[0], 1))) . ' ' : '') . 'Tofu Scramble'),
      'A golden, savory tofu scramble with greens, ready in 15 minutes.',
      [
        sprintf('Sauté %s in %s over medium heat for 3 minutes until softened.', $dish->join($aromatics), $oil),
        sprintf('Add %s, %s, and %s and cook, stirring, for 5 to 6 minutes until hot and lightly browned%s.', $tofu, $turmeric, $pepper, $milk !== NULL ? sprintf(', splashing in %s to keep it creamy', $milk) : ''),
        $greens !== [] ? sprintf('Fold in %s and cook for 1 to 2 minutes until just wilted.', $dish->join($greens)) : 'Taste and adjust the seasoning.',
        sprintf('Finish with %s%s and %s.', $toppings !== [] ? $dish->join($toppings) . ', ' : '', $bright, $herb),
      ],
      [$appliance, 'Medium heat', '12 minutes'],
      cook: 12,
    );
  }

  /**
   * Builds a simmered soup.
   *
   * @phpstan-return ComposedRecipe
   */
  private function soup(DishContext $dish, string $appliance): array {
    $liquid = $dish->find('/\b(?:broth|stock)\b/') ?? $dish->add('4 cups vegetable broth', 'vegetable broth');
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(3);
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $body = $dish->names(fn (string $name): bool => $name !== $liquid && preg_match(self::SUPPORTING, $name) !== 1);
    $hasLentils = str_contains($dish->text(), 'lentil');
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->singular($dish->mainName())) . ' Soup'),
      'A hearty, spiced vegan soup finished with citrus and fresh herbs.',
      [
        sprintf('Sauté %s in %s over medium heat for 5 minutes until soft and golden.', $dish->join($aromatics), $oil),
        sprintf('Add %s and toast for 30 seconds until fragrant.', $dish->join($spices)),
        sprintf('Add %s and %s, bring to a boil, then simmer for 20 to 25 minutes until %s.', $dish->join($body), $liquid, $hasLentils ? 'the lentils are tender and breaking down' : 'everything is tender'),
        sprintf('Finish with %s and %s; thin with water if needed.', $bright, $herb),
      ],
      [$appliance, 'Simmer', '30 minutes'],
      cook: 30,
    );
  }

  /**
   * Builds a stir-fry, using an East Asian profile unless a cuisine was set.
   *
   * @phpstan-return ComposedRecipe
   */
  private function stirFry(DishContext $dish, string $appliance): array {
    if ($dish->cuisine === 'Global') {
      $dish->useProfile(self::PROFILES['asian']);
    }
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $sauce = $dish->find('/\b(?:soy sauce|tamari)\b/') ?? $dish->spice(1)[0];
    $bright = $dish->bright();
    $herb = $dish->herb();
    $protein = $dish->find(self::PROTEIN);
    $vegetables = $dish->names(fn (string $name): bool => $name !== $protein && $name !== $sauce && preg_match(self::SUPPORTING, $name) !== 1);
    $steps = [];
    if ($protein !== NULL) {
      $steps[] = sprintf('Press %s dry, cut into cubes, and sear in half of the %s over medium-high heat for 6 to 8 minutes until golden on all sides; set aside.', $protein, $oil);
    }
    $steps[] = sprintf('Stir-fry %s in the %s%s for 1 minute until fragrant.', $dish->join($aromatics), $protein !== NULL ? 'remaining ' : '', $oil);
    if ($vegetables !== []) {
      $steps[] = sprintf('Add %s and stir-fry for 4 to 5 minutes until crisp-tender.', $dish->join($vegetables));
    }
    $steps[] = sprintf('Return everything to the pan with %s and %s and toss for 1 minute until glossy, then finish with %s.', $sauce, $bright, $herb);
    return $this->recipe(
      $dish,
      $dish->titled(implode(' and ', array_map(
        fn (string $name): string => ucwords($this->lastWords($name, 2)),
        array_filter([$protein, $vegetables[0] ?? NULL]),
      )) . ' Stir-Fry'),
      'A quick, glossy vegan stir-fry with seared protein and crisp-tender vegetables.',
      $steps,
      [$appliance, 'Medium-high heat', '15 minutes'],
      cook: 15,
    );
  }

  /**
   * Builds a toasted grain pilaf.
   *
   * @phpstan-return ComposedRecipe
   */
  private function pilaf(DishContext $dish, string $appliance): array {
    $grain = (string) $dish->find(self::GRAIN);
    $grainCups = max(1.0, $dish->cups($this->lastWords($grain, 1)));
    $isBrown = str_contains($grain, 'brown');
    $liquid = $dish->find('/\b(?:broth|stock|water)\b/') ?? $dish->add($this->cupsLine($grainCups * ($isBrown ? 2.0 : 1.5)) . ' water', $this->cupsLine($grainCups * ($isBrown ? 2.0 : 1.5)) . ' water');
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $mixIns = $dish->names(fn (string $name): bool => $name !== $grain && $name !== $liquid && preg_match(self::SUPPORTING, $name) !== 1);
    $minutes = $isBrown ? 40 : (str_contains($grain, 'quinoa') ? 15 : 18);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->lastWords($grain, 2)) . ' Pilaf'),
      'A fragrant toasted-grain pilaf with spiced mix-ins.',
      [
        sprintf('Sauté %s in %s over medium heat for 3 minutes, then add %s and %s and toast for 2 minutes.', $dish->join($aromatics), $oil, $grain, $dish->join($spices)),
        sprintf('Add %s, bring to a boil, cover, and simmer on low for %d minutes until the liquid is absorbed.', $liquid, $minutes),
        $mixIns !== [] ? sprintf('Stir in %s, cover, and rest off the heat for 5 minutes.', $dish->join($mixIns)) : 'Rest off the heat, covered, for 5 minutes.',
        sprintf('Fluff and finish with %s and %s.', $bright, $herb),
      ],
      [$appliance, 'Simmer, covered', $minutes . ' minutes'],
      cook: $minutes + 5,
    );
  }

  /**
   * Builds a simple sautéed skillet.
   *
   * @phpstan-return ComposedRecipe
   */
  private function skillet(DishContext $dish, string $appliance): array {
    $oil = $dish->find(self::FAT) ?? $dish->add('1 tbsp olive oil', 'olive oil');
    $aromatics = $dish->aromatic(2);
    $spices = $dish->spice(2);
    $bright = $dish->bright();
    $herb = $dish->herb();
    $greens = $dish->names(fn (string $name): bool => preg_match('/\b(?:spinach|kale|chard|greens|arugula)\b/', $name) === 1);
    $rest = $dish->names(fn (string $name): bool => !in_array($name, $greens, TRUE) && $name !== $oil && preg_match('/\b(?:garlic|onion|scallions?|shallot|ginger|cilantro|parsley|lime|lemon|cumin|paprika|turmeric|oregano|thyme|black pepper|spice|powder)\b/', $name) !== 1);
    return $this->recipe(
      $dish,
      $dish->titled(ucwords($this->singular($dish->mainName())) . ' Skillet'),
      'A quick one-pan vegan skillet with layered aromatics and a bright finish.',
      [
        sprintf('Sauté %s in %s over medium heat for 4 minutes until softened, then add %s and toast for 30 seconds.', $dish->join($aromatics), $oil, $dish->join($spices)),
        sprintf('Add %s and cook, stirring, for 8 to 10 minutes until tender and lightly browned.', $dish->join($rest)),
        $greens !== [] ? sprintf('Fold in %s and cook for 1 to 2 minutes until just wilted.', $dish->join($greens)) : 'Taste and adjust the seasoning.',
        sprintf('Finish with %s and %s.', $bright, $herb),
      ],
      [$appliance, 'Medium heat', '15 minutes'],
      cook: 15,
    );
  }

  /**
   * Assembles the recipe parts.
   *
   * @param \Drupal\fresco_ai\Fallback\DishContext $dish
   *   The dish whose ingredient lines are used.
   * @param string $title
   *   Recipe title.
   * @param string $description
   *   Recipe description.
   * @param array<int, string> $instructions
   *   Instruction texts.
   * @param array{0: string, 1: string, 2: string} $setting
   *   Appliance, mode, and time.
   * @param int $servings
   *   Servings.
   * @param int $prep
   *   Prep minutes.
   * @param int $cook
   *   Cook minutes.
   *
   * @phpstan-return ComposedRecipe
   */
  private function recipe(DishContext $dish, string $title, string $description, array $instructions, array $setting, int $servings = 4, int $prep = 15, int $cook = 0): array {
    return [
      'title' => $title,
      'description' => $description,
      'servings' => $servings,
      'prep_time_minutes' => $prep,
      'cook_time_minutes' => $cook,
      'ingredients' => $dish->lines(),
      'instructions' => array_values(array_filter($instructions)),
      'appliance_settings' => [['appliance' => $setting[0], 'mode' => $setting[1], 'time' => $setting[2]]],
    ];
  }

  /**
   * Returns the first selected appliance matching a pattern.
   *
   * @param array<int, string> $appliances
   *   Selected appliances.
   * @param string $pattern
   *   Regular expression for the appliance label.
   */
  private function selected(array $appliances, string $pattern): ?string {
    foreach ($appliances as $appliance) {
      if (preg_match($pattern, $appliance) === 1) {
        return $appliance;
      }
    }
    return NULL;
  }

  /**
   * Returns the last N words of a name.
   */
  private function lastWords(string $name, int $count): string {
    $words = preg_split('/\s+/', trim($name)) ?: [];
    return implode(' ', array_slice($words, -$count));
  }

  /**
   * Singularizes the final word of a name.
   */
  private function singular(string $name): string {
    return preg_replace(['/ies$/', '/oes$/', '/([^su])s$/'], ['y', 'o', '$1'], $name) ?? $name;
  }

  /**
   * Formats cups as an ingredient amount, such as "1 1/2 cups".
   */
  private function cupsLine(float $cups): string {
    $whole = (int) floor($cups);
    $fraction = match ((int) round(($cups - $whole) * 4)) {
      1 => '1/4',
      2 => '1/2',
      3 => '3/4',
      default => '',
    };
    $amount = trim(($whole > 0 ? $whole . ' ' : '') . $fraction);
    return $amount . ($cups > 1 ? ' cups' : ' cup');
  }

}
