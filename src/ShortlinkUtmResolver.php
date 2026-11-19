<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;

/**
 * Resolves the query parameters a shortlink adds to its destination.
 *
 * Shared by the redirect controller and the click statistics, so the values
 * reported by the stats endpoint are exactly the values a redirect sends.
 */
class ShortlinkUtmResolver {

  /**
   * Constructs a ShortlinkUtmResolver service.
   *
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected Token $token,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the entity a shortlink redirects to, if it has no override.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The target entity, or NULL when the shortlink uses a destination
   *   override or its target is missing.
   */
  public function loadTargetEntity(ShortlinkInterface $shortlink): ?EntityInterface {
    if (!empty($shortlink->getDestinationOverride())) {
      return NULL;
    }
    $entity_type_id = $shortlink->getTargetEntityType();
    $entity_id = $shortlink->getTargetEntityId();
    if (empty($entity_type_id) || empty($entity_id) || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
  }

  /**
   * Builds the token data a redirect uses.
   *
   * The shortlink is always available. The target entity is added only when
   * the shortlink redirects to it (no destination override), as in a redirect.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink.
   * @param \Drupal\Core\Entity\EntityInterface|null $target
   *   The target entity, if any.
   *
   * @return array
   *   Token data keyed by token type.
   */
  public function buildTokenData(ShortlinkInterface $shortlink, ?EntityInterface $target = NULL): array {
    $data = ['shortlink' => $shortlink];
    if ($target) {
      $data[$target->getEntityTypeId()] = $target;
    }
    return $data;
  }

  /**
   * Resolves a shortlink's UTM set into the query parameters it sends.
   *
   * Tokens are replaced, then each value is lowercased, runs of characters
   * outside [a-z0-9_] become one underscore, and leading or trailing
   * underscores are trimmed. Empty values are kept as they are.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink.
   * @param array $data
   *   Token data, normally from buildTokenData().
   * @param array $options
   *   Options for the token service (e.g. 'clear' => TRUE).
   *
   * @return array
   *   Query parameters keyed by name, or an empty array without a UTM set.
   */
  public function resolve(ShortlinkInterface $shortlink, array $data, array $options = []): array {
    if (!$shortlink->hasUtmSet()) {
      return [];
    }
    $utm_set = $shortlink->getUtmSet();
    $query_params = is_null($utm_set) ? [] : $utm_set->getUtmParameters();

    foreach ($query_params as $key => $value) {
      if (empty($value)) {
        continue;
      }
      $value = (string) $value;
      // Only run replacement if the value looks like it contains a token.
      if (str_contains($value, '[')) {
        $value = $this->token->replace($value, $data, $options);
      }
      $value = preg_replace('/[^a-z0-9_]+/i', '_', $value);
      $value = preg_replace('/_+/', '_', $value);
      $query_params[$key] = strtolower(trim($value, '_'));
    }

    return $query_params;
  }

}
