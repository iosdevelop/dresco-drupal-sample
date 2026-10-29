<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\fresco_ai\Service\PromptBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared AI prompt instructions.
 */
#[Group('fresco_ai')]
final class PromptBuilderTest extends UnitTestCase {

  /**
   * Confirms the prompt preserves ingredient packaging state.
   */
  public function testSystemInstructionPreservesPackagingState(): void {
    $prompt = (new PromptBuilder())->systemInstruction();

    self::assertStringContainsString('Preserve the user\'s ingredient wording and packaging state', $prompt);
    self::assertStringContainsString('can', $prompt);
    self::assertStringContainsString('dry, dried', $prompt);
    self::assertStringContainsString('Treat canned or jarred beans', $prompt);
    self::assertStringContainsString('fully cooked', $prompt);
    self::assertStringContainsString('rice and peas', $prompt);
    self::assertStringContainsString('1 cup water per cup brown rice', $prompt);
    self::assertStringContainsString('22 minute Pressure Cook', $prompt);
    self::assertStringContainsString('1 cup coconut milk uses 3 cups water', $prompt);
    self::assertStringContainsString('Treat dry pasta such as spaghetti', $prompt);
    self::assertStringContainsString('Do not sauté dry pasta', $prompt);
    self::assertStringContainsString('potatoes or cauliflower', $prompt);
    self::assertStringContainsString('fork-tender', $prompt);
    self::assertStringContainsString('treat menu_item as the dish or inspiration name', $prompt);
    self::assertStringContainsString('never as an ingredient', $prompt);
    self::assertStringContainsString('restaurant-inspired noodle dishes such as chow mein', $prompt);
    self::assertStringContainsString('restaurant-inspired Indian samosas', $prompt);
    self::assertStringContainsString('spiced potatoes or vegetables', $prompt);
    self::assertStringContainsString('egg noodles become vegan egg-style noodles', $prompt);
    self::assertStringContainsString('Every primary ingredient must include a usable quantity', $prompt);
    self::assertStringContainsString('choose a clear dish outcome', $prompt);
    self::assertStringContainsString('Cornmeal added to hot liquid must be whisked or stirred in gradually', $prompt);
    self::assertStringContainsString('Potatoes and sweet potatoes must be diced', $prompt);
    self::assertStringContainsString('Estimate calories, protein, and fiber from the actual ingredient mix', $prompt);
    self::assertStringContainsString('Do not reuse a generic placeholder nutrition block', $prompt);
  }

}
