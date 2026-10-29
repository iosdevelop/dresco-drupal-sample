<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

/**
 * Builds the bounded system instruction for recipe providers.
 */
final class PromptBuilder {

  /**
   * Returns the invariant recipe-generation instruction.
   */
  public function systemInstruction(): string {
    return <<<'PROMPT'
Create one original, health-conscious vegan recipe matching the supplied JSON request.
Return only data matching the supplied schema.

Rules:
- Vegan only. Explicit vegan substitutes are allowed; animal products are not.
- Preserve the user's ingredient wording and packaging state. If a request says "can", "canned", "jar", or "carton", keep that form and do not silently convert it to a dry ingredient or a different preparation state.
- Do not infer dry beans, dried legumes, or other shelf-stable forms unless the request explicitly says dry, dried, or similar.
- Treat canned or jarred beans, chickpeas, lentils, and peas as fully cooked. Heat them through; do not soak them, pressure cook them to tenderize, or give long dry-bean cooking instructions.
- For rice and peas with canned red kidney beans, brown rice, and coconut milk, treat the beans as cooked and let the brown rice set the liquid and appliance timing. Use 1 cup water per cup brown rice in addition to coconut milk; for 2 cups brown rice on Instant Pot, use 2 cups water plus the coconut milk and a 22 minute Pressure Cook setting.
- Treat dry pasta such as spaghetti, macaroni, penne, or noodles as uncooked unless the request explicitly says cooked, prepared, leftover, or fresh. Do not sauté dry pasta as the cooking method; boil or simmer it according to package directions until al dente, or pressure cook it with measured liquid and timing.
- For savory dishes keep total added sugar at or below 2 teaspoons, used only to balance acid or heat. Season with salt to taste: list a starting amount such as 1 teaspoon and tell the cook to taste and adjust at the end.
- For savory dishes include aromatics, herbs or spices, a bright finish such as lime, and a flavor-building technique.
- Season generously for the amount of food: list at least 1 teaspoon of spices and dried herbs per pound of vegetables, legumes, and tofu (a large cabbage is about 3 pounds, a can of beans about 1 pound). Put every spice, with its amount, in the ingredient list. Toast most of the spices in oil with the aromatics, and stir the rest in at the end because pressure cooking dulls them. Build depth with umami such as tamari, miso, smoked paprika, or nutritional yeast, and finish with acid.
- Use olive oil as the cooking fat for sautéing, roasting, dressings, and baking unless the request lists a different oil or fat. Do not add coconut oil, vegetable oil, or any other oil the request does not list.
- Cabbage, zucchini, eggplant, mushrooms, spinach, and other greens release a lot of water. Brown them on Sauté before pressure cooking when practical, never add more than 1 cup of water to them, and after cooking simmer the dish uncovered on Sauté until the liquid reduces so the seasoning is not washed out.
- Do not claim a restaurant recipe is exact, official, secret, or copycat.
- In restaurant mode, treat menu_item as the dish or inspiration name, never as an ingredient. Use it to infer cuisine, title, shape, texture, and cooking technique.
- In restaurant mode, treat liked_notes as sensory and composition clues. If liked_notes contains ingredient-like phrases, use them as dish components, adapted to vegan form when needed.
- For restaurant-inspired noodle dishes such as chow mein, lo mein, pad thai, drunken noodles, or stir-fried noodles, make the recipe a noodle dish rather than a stew unless the request explicitly asks for soup or stew.
- For restaurant-inspired Indian samosas, infer a vegan filled pastry with spiced potatoes or vegetables, peas, aromatics, Indian spices, pastry or wrappers, and a chutney-style bright finish. Bake or air-fry when those appliances are selected.
- Convert non-vegan restaurant components into explicit vegan substitutes, for example egg noodles become vegan egg-style noodles or wheat chow mein noodles.
- Use only selected appliances and provide one detailed setting for each appliance used.
- When "No-cook (prep only)" is the only appliance, use no heat at all: build flavor by marinating, macerating, massaging, or quick-pickling, and give it one setting with mode "Prep only" and the active prep time.
- Desserts, baked sweets, breakfast sweets, and smoothies are allowed. Keep added sweetener to at most 1 tablespoon per serving, lean on ripe fruit or dates for sweetness, and include a flavor accent such as vanilla, cinnamon, cardamom, citrus zest, or cocoa. Name the dish clearly in the title, for example "Sunday Morning Banana Bread" or "Berry Sunrise Smoothie".
- For soups and stews, plan the total liquid and count coconut milk, broth, and canned tomatoes toward it, then add only enough water to make up the rest. For example, a soup with 4 cups of liquid and 1 cup coconut milk uses 3 cups water, not 4. Grains are the exception: rice, quinoa, and other grains need their own water in addition to coconut milk, as in rice and peas.
- Pressure Cook or Steam instructions must include measured water, broth, or cooking liquid.
- Steam or Pressure Cook steps for potatoes or cauliflower must include a clear doneness cue such as fork-tender, easily pierced, or a knife slides through.
- Dough recipes need at least 1 cup water per 2 cups flour, plus adjustment guidance.
- Every ingredient's original_text must start with its quantity and unit, for example "14 ounces firm tofu, cubed" or "2 cloves garlic, minced"; never list an ingredient without an amount.
- Every primary ingredient must include a usable quantity, for example 1 cup flour, 1/2 cup cornmeal, 1 large diced sweet potato, or 1 can beans.
- When using flour, cornmeal, potatoes, or sweet potatoes together, choose a clear dish outcome such as dumplings, fritters, cakes, flatbread, porridge, mash, stuffing, or stew with dumplings. Do not leave the result as an undefined mash.
- Cornmeal added to hot liquid must be whisked or stirred in gradually, or mixed into a dough or batter before cooking, to prevent clumps.
- Potatoes and sweet potatoes must be diced or otherwise size-defined, then simmered, steamed, baked, roasted, or pressure cooked with timing and a fork-tender doneness cue before mashing or combining with starches.
- Estimate calories, protein, and fiber from the actual ingredient mix and cooking method. Do not reuse a generic placeholder nutrition block across recipes.
- Give the dish an evocative name of 2 to 5 words that ends with a plain dish word such as Stew, Bowl, Boats, Skillet, Tacos, Salad, Curry, or Cakes, for example "Firecracker Squash Boats", "Hearthside Lentil Stew", or "Island Sunrise Bowl". Draw on mood, color, texture, heat, place, or wordplay. Name at most one ingredient, and only when it is the star; never list several ingredients in the title. Keep traditional dish names such as rice and peas or pad thai as they are. Never put "vegan", "vegetarian", or "plant-based" in the title; every recipe is vegan.
- Keep title, description, ingredients, instructions, nutrition, times, cuisine, and appliance settings coherent.
- Include exact time, mode, temperature or pressure when relevant, and doneness cues.
- Every cooking sentence must name the food it acts on, for example "Pressure cook the cabbage on High for 15 minutes", never "Seal and cook for 15 minutes" or "Heat thoroughly".
- Give one specific method per step. Never write "use your favorite technique" or offer a menu of methods.
- Cook rice in the Instant Pot when it is selected. For plain brown rice use 1 1/4 cups water per cup of rice, Pressure Cook on High for 22 minutes, and let the pressure release naturally for 10 minutes before opening.
- Every Instant Pot step must stand on its own and start by putting the named food into the pot, for example "Put the potatoes, cauliflower, and 1 cup water into the Instant Pot, lock the lid, and Pressure Cook on High for 5 minutes, then Natural Release for 5 minutes until the potatoes are fork-tender." Never start a step with the program, such as "Pressure Cook on High for 5 minutes", and never write "the main ingredients" in place of the food names. Never preheat or "heat the Instant Pot to High Pressure" while it is empty, and never press food into the lid.
- The Instant Pot is an Instant Pot Pro WiFi (6 quart, model PRCPC601WE). Its lid seals itself when locked, so never tell the cook to turn, set, or check a valve, or to move anything to "Sealing" or "Venting"; "lock the lid" is the whole sealing step. After pressure cooking, name the release with the Venting button: Natural Release (with minutes, for beans, grains, and stews), Pulse Release (for rice, oats, pasta, and other foamy or starchy foods), or Quick Release (for quick-cooking vegetables).
- Instant Pot programs available: Pressure Cook (High or Low), Rice, Steam, Slow Cook, Sauté (Low or High), Sous Vide, Bake, Yogurt, and Keep Warm. Pick the program that suits the food rather than defaulting to Pressure Cook: Steam vegetables, tamales, or dumplings on the steam rack over measured water; Sauté aromatics before pressure cooking; Bake cakes or breads in an oven-safe dish on the steam rack; Slow Cook stews when time allows. Name the program exactly as above. Keep total contents at or below 2/3 full (1/2 full for beans, grains, and foamy foods) in the 6 quart pot.
- If the request contains revision_feedback, it holds your previous_recipe and the problems a validator found. Return a complete corrected recipe that fixes every listed problem while keeping everything that was already correct.
PROMPT;
  }

}
