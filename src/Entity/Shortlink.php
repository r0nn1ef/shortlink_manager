<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Url;
use Drupal\shortlink_manager\ShortlinkInterface;
use Drupal\shortlink_manager\UtmSetInterface;

/**
 * Defines the Shortlink entity.
 *
 * @ContentEntityType(
 * id = "shortlink",
 * label = @Translation("Shortlink"),
 * label_collection = @Translation("Shortlinks"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\shortlink_manager\ShortlinkListBuilder",
 * "form" = {
 * "default" = "Drupal\shortlink_manager\Form\ShortlinkForm",
 * "add" = "Drupal\shortlink_manager\Form\ShortlinkForm",
 * "edit" = "Drupal\shortlink_manager\Form\ShortlinkForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 * },
 * "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 * },
 * },
 * base_table = "shortlink",
 * admin_permission = "administer shortlink manager",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "label" = "label",
 * "status" = "status",
 * "path" = "path",
 * },
 * links = {
 * "canonical" = "/shortlink/{shortlink}",
 * "add-form" = "/admin/config/system/shortlink/add",
 * "edit-form" = "/admin/config/system/shortlink/{shortlink}/edit",
 * "delete-form" = "/admin/config/system/shortlink/{shortlink}/delete",
 * "collection" = "/admin/config/system/shortlink"
 * }
 * )
 */
class Shortlink extends ContentEntityBase implements ShortlinkInterface {

  /**
   * {@inheritdoc}
   *
   * This is a failsafe to ensure that the UUID is always set on entity save.
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    // Ensure a UUID is set if one does not already exist.
    if ($this->isNew() && empty($this->uuid->value)) {
      $this->uuid->value = \Drupal::service('uuid')->generate();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->get('description')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription(string $description): static {
    $this->set('description', $description);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function hasUtmSet(): bool {
    return (bool) $this->get('utm_set')->count();
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmSet(): ?UtmSetInterface {
    $utm_sets = $this->get('utm_set')->referencedEntities();
    return reset($utm_sets) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUtmSet(UtmSetInterface $utm_set): static {
    $this->set('utm_set', $utm_set);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityType(): ?string {
    return $this->get('target_entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntityType(?string $entity_type): static {
    $this->set('target_entity_type', $entity_type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityId(): ?string {
    return $this->get('target_entity_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntityId(?string $entity_id): static {
    $this->set('target_entity_id', $entity_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDestinationOverride(): ?string {
    return $this->get('destination_override')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDestinationOverride(?string $url): static {
    $this->set('destination_override', $url);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveDestinationUrl(): Url {
    if (!empty($this->get('destination_override')->value)) {
      return Url::fromUri($this->get('destination_override')->value);
    }

    if ($entity = $this->getTargetEntity()) {
      return $entity->toUrl('canonical');
    }

    // Default fallback: home page.
    return Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntity(): ?EntityInterface {
    $entity_type = $this->get('target_entity_type')->value;
    $entity_id = $this->get('target_entity_id')->value;

    if ($entity_type && $entity_id) {
      $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
      return $storage->load($entity_id);
    }
    return NULL;
  }

  /**
   * Returns the Shortlink path.
   *
   * @return string
   *   The Shortlink path.
   */
  public function getPath(): string {
    return $this->get('path')->value ?? '';
  }

  /**
   * Sets the Shortlink path.
   *
   * @param string $path
   *   The Shortlink path.
   *
   * @return $this
   */
  public function setPath(string $path): static {
    $this->set('path', $path);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Shortlink entity.'));

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Shortlink label'))
      ->setDescription(t('The label for the shortlink.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Shortlink path'))
      ->setDescription(t('The unique path or slug for the shortlink.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDefaultValue('')
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('A brief description of the shortlink.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['utm_set'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('UTM Set'))
      ->setDescription(t('The UTM sets to apply to the destination URL.'))
      ->setSetting('target_type', 'utm_set')
      ->setCardinality(1)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 3,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['destination_override'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Destination override path'))
      ->setDescription(t('An optional relative path to redirect to instead of a target entity.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enabled'))
      ->setDescription(t('Whether the shortlink is active.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target entity type'))
      ->setDescription(t('The target entity type ID.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['target_entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target entity ID'))
      ->setDescription(t('The ID of the target entity.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
