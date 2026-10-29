<?php

declare(strict_types=1);

namespace Drupal\fresco_ai\Batch;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Batch API callbacks that run recipe generation behind a progress page.
 *
 * A local model can take minutes per recipe; running it as a batch shows a
 * progress page instead of a frozen form and prevents double submission.
 */
final class RecipeGeneratorBatch {

  /**
   * Private tempstore collection holding a failed request's form values.
   */
  public const TEMPSTORE = 'fresco_recipe_generator';

  /**
   * Generates and saves the draft.
   *
   * @param array<string, mixed> $request
   *   Generator request.
   * @param array<string, mixed> $values
   *   Submitted form values, restored on failure.
   * @param array<string, mixed>|\ArrayAccess<string, mixed> $context
   *   Batch context.
   */
  public static function generate(array $request, array $values, array|\ArrayAccess &$context): void {
    $context['results']['outcome'] = \Drupal::service('fresco_ai.recipe_draft_creator')->create($request);
    $context['results']['values'] = $values;
    $context['message'] = t('Recipe drafted.');
  }

  /**
   * Shows the outcome and redirects to the draft or back to the generator.
   *
   * @param bool $success
   *   Whether the batch completed without a fatal error.
   * @param array<string, mixed> $results
   *   Batch results.
   */
  public static function finished(bool $success, array $results): RedirectResponse {
    $messenger = \Drupal::messenger();
    $outcome = $results['outcome'] ?? NULL;
    if (!$success || !is_array($outcome)) {
      $messenger->addError(t('Recipe generation stopped unexpectedly. Please try again.'));
      return new RedirectResponse(Url::fromRoute('fresco_recipe.generator')->toString());
    }

    foreach ($outcome['messages'] as $message) {
      $messenger->addMessage($message['text'], $message['type']);
    }
    if ($outcome['nid'] !== NULL) {
      return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $outcome['nid']])->toString());
    }

    \Drupal::service('tempstore.private')->get(self::TEMPSTORE)->set('failed', [
      'values' => $results['values'] ?? [],
      'feedback' => $outcome['feedback'],
    ]);
    return new RedirectResponse(Url::fromRoute('fresco_recipe.generator')->toString());
  }

}
