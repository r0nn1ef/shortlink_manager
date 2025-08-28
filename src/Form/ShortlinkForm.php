<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Shortlink form.
 */
final class ShortlinkForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    // Let the parent form build all the fields defined in baseFieldDefinitions.
    $form = parent::form($form, $form_state);

    $form['label']['widget'][0]['value']['#title'] = $this->t('Label');

    /*
     * Set the form states to correctly reference the fields in their
     * new location.
     */
    $form['target_entity_type']['#states'] = [
      'disabled' => [
        ':input[name="target[destination_override]"]' => ['filled' => TRUE],
      ],
    ];

    $form['target_entity_id']['#states'] = [
      'disabled' => [
        ':input[name="target[destination_override]"]' => ['filled' => TRUE],
      ],
    ];

    $form['destination_override']['#states'] = [
      'disabled' => [
        ':input[name="target[target_entity_type]"]' => ['!value' => ''],
      ],
    ];

    /*
     * @todo Wrap target entity type/id and destination override in details sec.
     */

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // The form values are now nested inside the 'target' key.
    $target_entity_type = $form_state->getValue('target_entity_type')[0]['value'];
    $target_entity_id = $form_state->getValue('target_entity_id')[0]['value'];
    $destination_override = $form_state->getValue('destination_override')[0]['value'];

    /*
     * Ensure that either a target entity or a destination override is set, but
     * not both.
     */
    $has_target_entity = !(empty($target_entity_type) && empty($target_entity_id));
    $has_destination_override = !(empty($destination_override));

    if ($has_target_entity && $has_destination_override) {
      $form_state->setErrorByName('target[destination_override]', $this->t('You cannot set both a destination override path and a target entity. Please choose one.'));
    }

    if (!$has_target_entity && !$has_destination_override) {
      $form_state->setErrorByName('target[destination_override]', $this->t('You must set either a destination override path or a target entity.'));
      $form_state->setErrorByName('target[target_entity_type]', $this->t('You must set either a destination override path or a target entity.'));
    }

    // If a target entity is set, validate that it's a valid entity.
    if ($has_target_entity) {
      $entity = $this->entityTypeManager->getStorage($target_entity_type)->load($target_entity_id);
      if (!$entity) {
        $form_state->setErrorByName('target[target_entity_id]', $this->t('The selected entity of type %type with ID %id does not exist.', [
          '%type' => $target_entity_type,
          '%id' => $target_entity_id,
        ]));
      }
    }
    else {
      // The destination override is set so let it go.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new shortlink %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated shortlink %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
