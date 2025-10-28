<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\shortlink_manager\UtmSetInterface;

/**
 * Defines the UTM Set config entity.
 *
 * @ConfigEntityType(
 *   id = "this",
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
 *   config_prefix = "this",
 *   admin_permission = "administer this",
 *   links = {
 *     "collection" = "/admin/structure/utm-set",
 *     "add-form" = "/admin/structure/utm-set/add",
 *     "edit-form" = "/admin/structure/utm-set/{this}",
 *     "delete-form" = "/admin/structure/utm-set/{this}/delete",
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
  public function getUtmParameters(): array {
    $parameters = [];

    if (!empty($this->getUtmSource())) {
      $parameters['utm_source'] = $this->getUtmSource();
    }
    if (!empty($this->getUtmMedium())) {
      $parameters['utm_medium'] = $this->getUtmMedium();
    }
    if (!empty($this->getUtmCampaign())) {
      $parameters['utm_campaign'] = $this->getUtmCampaign();
    }
    if (!empty($this->getUtmTerm())) {
      $parameters['utm_term'] = $this->getUtmTerm();
    }
    if (!empty($this->getUtmContent())) {
      $parameters['utm_content'] = $this->getUtmContent();
    }

    if(!empty($this->getCustomParameters())) {
      $custom_parameters = $this->getCustomParameters();
      foreach ($custom_parameters as $parameter_string) {
        $matches = [];
        // Regex: Matches everything before the FIRST colon (the key) and
        // everything after it (the value).
        $pattern = '/^([^:]+):(.+)$/';

        if (preg_match($pattern, $parameter_string, $matches)) {
          $key = trim($matches[1]);
          $value = trim($matches[2]);
          // Assign the split key/value to the parameters.
          // NOTE: The token replacement for $value must happen later
          // in your code!
          $parameters[$key] = $value;
        }
        // TODO: Add logging/error handling for misformatted parameters here.
      }
    }

    return $parameters;
  }

}
