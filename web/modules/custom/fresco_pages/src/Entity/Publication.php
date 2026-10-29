<?php

declare(strict_types=1);

namespace Drupal\fresco_pages\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Records an immutable recipe-revision publication attempt.
 */
#[ContentEntityType(
  id: 'fresco_publication',
  label: new TranslatableMarkup('Dresco publication'),
  label_singular: new TranslatableMarkup('Dresco publication'),
  label_plural: new TranslatableMarkup('Dresco publications'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  base_table: 'fresco_publication',
  admin_permission: 'administer fresco pages',
)]
final class Publication extends ContentEntityBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Publication field definitions.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['recipe'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recipe'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);
    $fields['recipe_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Recipe revision ID'))
      ->setRequired(TRUE);
    $fields['content_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Content hash'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);
    $fields['slug'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Slug'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);
    $fields['public_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Public URL'))
      ->setRequired(TRUE);
    $fields['correlation_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Correlation ID'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);
    $fields['operation'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Operation'))
      ->setSetting('allowed_values', [
        'publish' => 'Publish',
        'archive' => 'Archive',
      ])
      ->setDefaultValue('publish')
      ->setRequired(TRUE);
    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setSetting('allowed_values', [
        'queued' => 'Queued',
        'processing' => 'Processing',
        'ready' => 'Ready for GitHub',
        'published' => 'Published',
        'archived' => 'Archived',
        'failed' => 'Failed',
      ])
      ->setDefaultValue('queued')
      ->setRequired(TRUE);
    $fields['attempts'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Attempts'))
      ->setDefaultValue(0)
      ->setRequired(TRUE);
    $fields['artifact'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Immutable artifact'))
      ->setRequired(TRUE);
    $fields['error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Last error'));
    $fields['commit_sha'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Transport identifier'))
      ->setSetting('max_length', 128);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Queued'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Updated'));
    return $fields;
  }

}
