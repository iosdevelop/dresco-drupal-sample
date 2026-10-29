<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\fresco_ai\Form\IngredientPhotoForm;
use Drupal\fresco_ai\Service\IngredientImageAnalyzer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the photo ingredient review step.
 */
#[Group('fresco_ai')]
final class IngredientPhotoFormTest extends UnitTestCase {

  /**
   * Confirms a lone first suggestion, keyed "0", reaches the generator.
   */
  public function testFirstSuggestionIsUsed(): void {
    $formState = $this->reviewState(['0' => '0']);
    $form = [];
    $photoForm = $this->photoForm();

    $photoForm->validateSelectedIngredients($form, $formState);
    self::assertSame([], $formState->getErrors());

    $photoForm->useSelectedIngredients($form, $formState);
    $redirect = $formState->getRedirect();
    self::assertSame('fresco_recipe.generator', $redirect->getRouteName());
    self::assertSame(['ingredients' => '3/4 cup eggplant'], $redirect->getOption('query'));
  }

  /**
   * Confirms an empty selection is a validation error, not a crash.
   */
  public function testEmptySelectionIsValidationError(): void {
    $formState = $this->reviewState(['0' => 0]);
    $form = [];

    $this->photoForm()->validateSelectedIngredients($form, $formState);

    self::assertArrayHasKey('selected_ingredients', $formState->getErrors());
  }

  /**
   * Builds review-step form state with one eggplant suggestion.
   *
   * @param array<int, string|int> $selected
   *   Submitted checkbox values.
   */
  private function reviewState(array $selected): FormState {
    $formState = new FormState();
    $formState->set('ingredient_candidates', [
      ['name' => 'eggplant', 'quantity' => '3/4 cup', 'confidence' => 'medium'],
    ]);
    $formState->setValue('selected_ingredients', $selected);
    return $formState;
  }

  /**
   * Builds the form without calling the image analyzer.
   */
  private function photoForm(): IngredientPhotoForm {
    $analyzer = (new \ReflectionClass(IngredientImageAnalyzer::class))->newInstanceWithoutConstructor();
    $form = new IngredientPhotoForm(
      $analyzer,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

}
