<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Deletes a shortlink.
 *
 * @Action(
 *   id = "shortlink_manager_delete_shortlink",
 *   label = @Translation("Delete shortlink"),
 *   type = "shortlink",
 *   confirm_form_route_name = "entity.shortlink.delete_form"
 * )
 */
class DeleteShortlinkAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity) {
      $entity->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('delete', $account, $return_as_object);
  }

}
