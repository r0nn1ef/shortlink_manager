<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Disables a shortlink.
 *
 * @Action(
 *   id = "shortlink_manager_disable_shortlink",
 *   label = @Translation("Disable shortlink"),
 *   type = "shortlink"
 * )
 */
class DisableShortlinkAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity) {
      $entity->set('status', FALSE);
      $entity->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
