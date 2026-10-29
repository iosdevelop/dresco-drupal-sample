<?php

/**
 * @file
 * Rewrites Instant Pot valve wording for the self-sealing Instant Pot Pro lid.
 *
 * Usage: ddev drush php:script scripts/instant-pot-pro-wording.php [-- --apply]
 * Without --apply it only prints the changes. Saving a published recipe
 * queues it for GitHub Pages.
 */

declare(strict_types=1);

$apply = in_array('--apply', $extra ?? [], TRUE);

$replacements = [
  '/, set the valve to Sealing, and /i' => ' and ',
  '/\bthen vent and open\b/i' => 'then press the Venting button for Quick Release and open the lid',
  '/\bbefore venting the remaining pressure\b/i' => 'before pressing the Venting button to release the remaining pressure',
  '/\bQuick release the pressure\b/' => 'Press the Venting button for Quick Release',
  '/\bquick-release\b/i' => 'use Quick Release on the Venting button',
  '/\bon a rack, seal, and\b/i' => 'on the steam rack, lock the lid, and',
  '/\bthen seal the lid\b/i' => 'then lock the lid',
];

$storage = \Drupal::entityTypeManager()->getStorage('node');
$nids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'recipe')->execute();
$changed = 0;

foreach ($storage->loadMultiple($nids) as $node) {
  $items = $node->get('field_instructions')->getValue();
  $dirty = FALSE;
  foreach ($items as $delta => $item) {
    $new = preg_replace(array_keys($replacements), array_values($replacements), $item['value']);
    if ($new !== $item['value']) {
      printf("[%d] %s, step %d\n  - %s\n  + %s\n", $node->id(), $node->label(), $delta + 1, $item['value'], $new);
      $items[$delta]['value'] = $new;
      $dirty = TRUE;
    }
  }
  if ($dirty) {
    $changed++;
    if ($apply) {
      $node->set('field_instructions', $items);
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Instant Pot Pro wording: the lid seals itself; release with the Venting button.');
      $node->save();
    }
  }
}

printf("%d recipe(s) %s.\n", $changed, $apply ? 'updated' : 'would change');
