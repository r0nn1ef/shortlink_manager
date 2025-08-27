<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides an interface for the Shortlink content entity.
 */
interface ShortlinkInterface extends ContentEntityInterface {

  /**
   * Gets the shortlink description.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string;

  /**
   * Sets the shortlink description.
   *
   * @param string $description
   *   The description to set.
   *
   * @return $this
   */
  public function setDescription(string $description): static;

  /**
   * Gets the machine name of the associated UTM set.
   *
   * @return array
   *   The UTM sets.
   */
  public function getUtmSet(): ?UtmSetInterface;

  /**
   * Sets the UTM set.
   *
   * @param \Drupal\shortlink_manager\UtmSetInterface $utm_set
   *   The UTM Set.
   *
   * @return $this
   */
  public function setUtmSet(UtmSetInterface $utm_set): static;

  /**
   * Whether this Shortlink has a UTM Set or not.
   *
   * @return bool
   *   Boolean true or false.
   */
  public function hasUtmSet(): bool;

  /**
   * Gets the target entity type ID.
   *
   * @return string|null
   *   The target entity type, or NULL if not set.
   */
  public function getTargetEntityType(): ?string;

  /**
   * Sets the target entity type.
   *
   * @param string|null $entity_type
   *   The entity type ID.
   *
   * @return $this
   */
  public function setTargetEntityType(?string $entity_type): static;

  /**
   * Gets the target entity ID.
   *
   * @return string|null
   *   The target entity ID, or NULL if not set.
   */
  public function getTargetEntityId(): ?string;

  /**
   * Sets the target entity ID.
   *
   * @param string|null $entity_id
   *   The target entity ID.
   *
   * @return $this
   */
  public function setTargetEntityId(?string $entity_id): static;

  /**
   * Gets the destination override URL.
   *
   * @return string|null
   *   The override URL, or NULL if not set.
   */
  public function getDestinationOverride(): ?string;

  /**
   * Sets the destination override URL.
   *
   * @param string|null $url
   *   The override URL.
   *
   * @return $this
   */
  public function setDestinationOverride(?string $url): static;

  /**
   * Gets the status of the shortlink.
   *
   * @return bool
   *   TRUE if the shortlink is enabled, FALSE otherwise.
   */
  public function isEnabled(): bool;

  /**
   * Resolves the destination URL for the shortlink.
   *
   * This applies token replacement and UTM parameters if needed.
   *
   * @return \Drupal\Core\Url
   *   The resolved redirect URL.
   */
  public function resolveDestinationUrl(): Url;

  /**
   * Gets the target entity object, if any.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The referenced entity, or NULL if none found.
   */
  public function getTargetEntity(): ?EntityInterface;

}
