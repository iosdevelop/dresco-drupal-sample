<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fresco_ai\Exception\RecipeGenerationException;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Validation\ValidationResult;

/**
 * Generates a recipe and saves it as an unpublished draft.
 *
 * The outcome is plain data so it can travel between Batch API requests.
 */
final class RecipeDraftCreator {

  use StringTranslationTrait;

  public function __construct(
    private readonly GenerationCoordinator $generationCoordinator,
    private readonly RecipeValidator $recipeValidator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Generates and saves a draft recipe.
   *
   * @param array<string, mixed> $request
   *   Generator request.
   *
   * @return array{nid: int|null, messages: array<int, array{type: string, text: string|\Drupal\Component\Render\MarkupInterface}>, feedback: array<int, string>}
   *   The saved node ID, messages to show, and generation feedback when no
   *   draft could be created. Translated messages stay markup objects so
   *   placeholders render; model-derived text stays a plain, escaped string.
   */
  public function create(array $request): array {
    try {
      $result = $this->generationCoordinator->generate($request);
    }
    catch (RecipeGenerationException $exception) {
      return $this->failure($this->feedbackMessages($exception->getValidationResults(), $exception->getMessage()));
    }
    catch (\RuntimeException $exception) {
      $message = (string) $this->t('Recipe generation could not complete: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $this->failure([$message]);
    }

    $recipe = $result->recipe;
    $validationResults = $this->recipeValidator->validate($recipe);
    $errors = array_values(array_filter($validationResults, static fn (ValidationResult $item): bool => $item->severity === 'error'));
    if ($errors !== []) {
      return $this->failure(array_map(static fn (ValidationResult $item): string => $item->message, $errors));
    }

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'recipe',
      'title' => $recipe->title,
      'uid' => $this->currentUser->id(),
      'status' => 0,
      'field_recipe_description' => $recipe->description,
      'field_servings' => $recipe->servings,
      'field_prep_minutes' => $recipe->prepTimeMinutes,
      'field_cook_minutes' => $recipe->cookTimeMinutes,
      'field_cuisine' => $recipe->cuisine,
      'field_category' => $recipe->category,
      'field_ingredients' => array_map(static fn (array $ingredient): array => [
        'value' => (string) ($ingredient['original_text'] ?? $ingredient['food'] ?? ''),
      ], $recipe->ingredients),
      'field_instructions' => array_map(static fn (array $instruction): array => [
        'value' => (string) ($instruction['text'] ?? ''),
      ], $recipe->instructions),
      'field_appliance_settings' => array_map(static fn (array $setting): array => [
        'value' => implode(' | ', array_filter([
          (string) ($setting['appliance'] ?? ''),
          (string) ($setting['mode'] ?? ''),
          (string) ($setting['time'] ?? ''),
        ])),
      ], $recipe->applianceSettings),
      'field_nutrition' => json_encode($recipe->nutrition, JSON_THROW_ON_ERROR),
      'field_provider' => $result->provider,
      'field_generation_context' => json_encode($request + [
        'fallback_reason' => $result->fallbackReason,
        'vegan_substitutions' => $result->substitutions,
      ], JSON_THROW_ON_ERROR),
    ]);
    $node->save();

    $messages = [['type' => 'status', 'text' => $this->t('Generated %title as a draft.', ['%title' => $recipe->title])]];
    foreach ($validationResults as $warning) {
      if ($warning->severity !== 'error') {
        $messages[] = ['type' => 'warning', 'text' => $warning->message];
      }
    }
    if ($result->fallbackReason !== NULL) {
      $messages[] = [
        'type' => 'warning',
        'text' => $this->t('The configured provider was unavailable or invalid, so Dresco used the deterministic local provider.'),
      ];
    }
    foreach ($result->substitutions as $swap) {
      $arguments = ['%from' => $swap['from'], '%to' => $swap['to']];
      $messages[] = [
        'type' => 'status',
        'text' => $swap['reason'] === 'sweetener'
          ? $this->t('Reduced sweetener: %from → %to', $arguments)
          : $this->t('Made vegan: %from → %to', $arguments),
      ];
    }
    return ['nid' => (int) $node->id(), 'messages' => $messages, 'feedback' => []];
  }

  /**
   * Builds a failed outcome whose feedback is also shown as errors.
   *
   * @param array<int, string> $feedback
   *   Feedback lines.
   *
   * @return array{nid: null, messages: array<int, array{type: string, text: string}>, feedback: array<int, string>}
   *   The failed outcome.
   */
  private function failure(array $feedback): array {
    return [
      'nid' => NULL,
      'messages' => array_map(static fn (string $text): array => ['type' => 'error', 'text' => $text], $feedback),
      'feedback' => $feedback,
    ];
  }

  /**
   * Turns validation results into feedback, with remediation when present.
   *
   * @param array<int, \Drupal\fresco_recipe\Validation\ValidationResult> $validationResults
   *   Validation results.
   * @param string $fallbackMessage
   *   Message to use when there are no results.
   *
   * @return array<int, string>
   *   Feedback lines.
   */
  private function feedbackMessages(array $validationResults, string $fallbackMessage): array {
    if ($validationResults !== []) {
      return array_values(array_map(
        static fn (ValidationResult $result): string => $result->remediation === NULL || trim($result->remediation) === ''
          ? $result->message
          : $result->message . ' ' . $result->remediation,
        $validationResults,
      ));
    }
    return trim($fallbackMessage) !== '' ? [$fallbackMessage] : [];
  }

}
