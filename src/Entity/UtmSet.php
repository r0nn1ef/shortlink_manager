<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\shortlink_manager\UtmSetInterface;

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

}
