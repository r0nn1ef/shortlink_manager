<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Enables a shortlink.
 *
 * @Action(
 *   id = "shortlink_manager_enable_shortlink",
 *   label = @Translation("Enable shortlink"),
 *   type = "shortlink"
 * )
 */
class EnableShortlinkAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity) {
      $entity->set('status', TRUE);
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
