<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining a UTM Set config entity.
 */
interface UtmSetInterface extends ConfigEntityInterface {

  /**
   * Whether the UTM Set is enabled.
   *
   * @return bool
   *   TRUE if the UTM set is enabled, FALSE otherwise.
   */
  public function getStatus(): bool;

  /**
   * Gets the UTM source value.
   *
   * @return string
   *   The UTM source parameter.
   */
  public function getUtmSource(): string;

  /**
   * Gets the UTM medium value.
   *
   * @return string
   *   The UTM medium parameter.
   */
  public function getUtmMedium(): string;

  /**
   * Gets the UTM campaign value.
   *
   * @return string
   *   The UTM campaign parameter.
   */
  public function getUtmCampaign(): string;

  /**
   * Gets the UTM term value.
   *
   * @return string
   *   The UTM term parameter.
   */
  public function getUtmTerm(): string;

  /**
   * Gets the UTM content value.
   *
   * @return string
   *   The UTM content parameter.
   */
  public function getUtmContent(): string;

}
