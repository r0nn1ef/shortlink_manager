<?php

namespace Drupal\shortlink_manager\Plugin\feeds\target;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\feeds\FieldTargetDefinition;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines a shortlink target.
 *
 * @FeedsTarget(
 * id = "shortlink_target",
 * field_types = {"shortlink"}
 * )
 */
class ShortlinkTarget extends FieldTargetBase {

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition) {
    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('id')
      ->addProperty('label')
      ->addProperty('path')
      ->addProperty('status')
      ->addProperty('target_entity_id')
      ->addProperty('target_entity_type')
      ->addProperty('utm_set')
      ->addProperty('destination_override')
      ->addProperty('description')
      ->setSummary('Properties for a Shortlink entity.');
  }

  /**
   * {@inheritdoc}
   */
  public function apply(ContentEntityInterface $entity, array $values) {
    // Loop through each value and apply it to the entity.
    foreach ($values as $property => $value) {
      try {
        // Handle boolean status explicitly.
        if ($property === 'status') {
          $value = (bool) $value;
        }

        // Set the property value on the entity.
        $entity->set($property, $value);

      } catch (\InvalidArgumentException $e) {
        // Log or skip properties that don't exist on the entity.
        // This prevents the import from failing due to unexpected columns.
        continue;
      }
    }
  }

}
