<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\shortlink_manager\UtmSetInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the UTM Set config entity.
 *
 * @ConfigEntityType(
 *   id = "utm_set",
 *   label = @Translation("UTM Set"),
 *   label_collection = @Translation("UTM Sets"),
 *   label_singular = @Translation("utm set"),
 *   label_plural = @Translation("utm sets"),
 *   label_count = @PluralTranslation(
 *     singular = "@count UTM set",
 *     plural = "@count UTM sets",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\shortlink_manager\UtmSetListBuilder",
 *     "form" = {
 *       "add" = "Drupal\shortlink_manager\Form\UtmSetForm",
 *       "edit" = "Drupal\shortlink_manager\Form\UtmSetForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "utm_set",
 *   admin_permission = "administer utm_set",
 *   links = {
 *     "collection" = "/admin/structure/utm-set",
 *     "add-form" = "/admin/structure/utm-set/add",
 *     "edit-form" = "/admin/structure/utm-set/{utm_set}",
 *     "delete-form" = "/admin/structure/utm-set/{utm_set}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "utm_source",
 *     "utm_medium",
 *     "utm_campaign",
 *     "utm_term",
 *     "utm_content",
 *     "custom_parameters",
 *     "status",
 *   },
 * )
 */
final class UtmSet extends ConfigEntityBase implements UtmSetInterface {

  /**
   * The UTM set ID.
   */
  protected string $id;

  /**
   * The UTM set label.
   */
  protected string $label;

  /**
   * Description of this UTM set.
   */
  protected string $description = '';

  /**
   * UTM source parameter value.
   */
  protected string $utm_source = '';

  /**
   * UTM medium parameter value.
   */
  protected string $utm_medium = '';

  /**
   * UTM campaign parameter value.
   */
  protected string $utm_campaign = '';

  /**
   * UTM term parameter value.
   */
  protected string $utm_term = '';

  /**
   * UTM content parameter value.
   */
  protected string $utm_content = '';

  /**
   * @var array Custom UTM parameters in the form of 'key' => 'value'.
   */
  protected array $custom_parameters = [];

  /**
   * {@inheritDoc}
   */
  public function getStatus(): bool {
    return $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmSource(): string {
    return $this->utm_source;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmMedium(): string {
    return $this->utm_medium;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmCampaign(): string {
    return $this->utm_campaign;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmTerm(): string {
    return $this->utm_term;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmContent(): string {
    return $this->utm_content;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomParameters(): array {
    return $this->custom_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomParameters(array $custom_parameters): self {
    $this->custom_parameters = $custom_parameters;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(array $form, FormStateInterface $form_state): void {
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
