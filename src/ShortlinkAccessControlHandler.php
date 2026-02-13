<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the Shortlink entity.
 */
class ShortlinkAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin_access = parent::checkAccess($entity, $operation, $account);
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view shortlink'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit any shortlink'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete any shortlink'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $admin_access = parent::checkCreateAccess($account, $context, $entity_bundle);
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }

    return AccessResult::allowedIfHasPermission($account, 'create shortlink');
  }

}
