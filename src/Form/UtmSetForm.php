<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the UTM Set add/edit forms.
 */
final class UtmSetForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $utm_set = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $utm_set->label(),
      '#description' => $this->t('Name of the UTM Set.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $utm_set->id(),
      '#machine_name' => [
        'exists' => ['\Drupal\shortlink_manager\Entity\UtmSet', 'load'],
      ],
      '#disabled' => !$utm_set->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $utm_set->get('description'),
      '#description' => $this->t('Optional description for administrative use.'),
      '#required' => TRUE,
    ];

    $form['utm_fields'] = [
      '#type' => 'details',
      '#title' => $this->t('UTM Parameters'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['utm_fields']['utm_source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTM Source'),
      '#default_value' => $utm_set->getUtmSource(),
      '#description' => $this->t('Campaign source (e.g., newsletter, facebook).'),
      '#required' => TRUE,
    ];

    $form['utm_fields']['utm_medium'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTM Medium'),
      '#default_value' => $utm_set->getUtmMedium(),
      '#description' => $this->t('Campaign medium (e.g., email, cpc, banner).'),
      '#required' => TRUE,
    ];

    $form['utm_fields']['utm_campaign'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTM Campaign'),
      '#default_value' => $utm_set->getUtmCampaign(),
      '#description' => $this->t('Campaign name (e.g., summer_sale).'),
      '#required' => TRUE,
    ];

    $form['utm_fields']['utm_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTM Term'),
      '#default_value' => $utm_set->getUtmTerm(),
      '#description' => $this->t('Campaign term for paid keywords (optional).'),
    ];

    $form['utm_fields']['utm_content'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTM Content'),
      '#default_value' => $utm_set->getUtmContent(),
      '#description' => $this->t('Campaign content for A/B testing or distinguishing ads.'),
    ];

    $custom_parameters = $utm_set->getCustomParameters();

    /*
     * We want the tree format so we can easily recreate the array before saving.
     */
    $form['custom_parameters_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom Parameter Options'),
      '#open' => !empty($custom_parameters),
      '#tree' => FALSE,
    ];

    $custom_parameters = $utm_set->getCustomParameters();
    $custom_parameters_string = implode("\n", $custom_parameters);
    $custom_parameters_string = trim($custom_parameters_string);

    $cp_description = $this->t('Enter any valid custom UTM parameters in key:value format, one per line. Tokens are supported for values.');
    $form['custom_parameters_details']['custom_parameters'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom Parameters'),
      '#default_value' => $custom_parameters_string,
      '#description' => $cp_description,
    ];


    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $utm_set->getStatus(),
      '#description' => $this->t('If unchecked, this UTM Set will not be used in automatic generation.'),
      '#weight' => 100,
    ];

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\shortlink_manager\UtmSetInterface $utm_set */
    $utm_set = $this->entity;
    $utm_set->save();

    $this->messenger()->addStatus($this->t('Saved the %label UTM Set.', [
      '%label' => $utm_set->label(),
    ]));

    $form_state->setRedirectUrl($utm_set->toUrl('collection'));
  }

  /**
   * {@inheritdoc}
   * @param \Drupal\shortlink_manager\Form\EntityInterface $entity
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // 1. Process the custom_parameters field FIRST.
    $raw_params_string = trim($form_state->getValue('custom_parameters'));

    $custom_parameters = [];
    if (!empty($raw_params_string)) {
      $raw_array = explode("\n", $raw_params_string);
      $custom_parameters = array_filter(array_map('trim', $raw_array));
    }

    // Set the property on the entity object as a clean array.
    $this->entity->setCustomParameters($custom_parameters);

    // CRITICAL FIX: Unset the raw string value from the form state.
    // This prevents the parent::copyFormValuesToEntity() call (step 3)
    // from seeing the raw string and triggering the TypeError.
    $form_state->unsetValue('custom_parameters');

    // 2. Remove the surrounding details element key if it exists in the form state
    // to prevent it from causing issues with entity properties.
    $form_state->unsetValue('custom_parameters_details');

    // 3. Call the parent method to copy all other (non-custom) form values.
    parent::copyFormValuesToEntity($form, $form_state);
  }

}
