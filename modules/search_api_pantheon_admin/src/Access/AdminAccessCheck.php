<?php

namespace Drupal\search_api_pantheon_admin\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Stub access checker for backwards compatibility during module deprecation.
 *
 * This class exists only to prevent errors when old cached routes
 * reference the access checker. It always denies access.
 *
 * @deprecated in drupal:8.4.0. This module
 *   is obsolete and no longer needed.
 *
 * @see https://www.drupal.org/project/search_api_pantheon
 */
class AdminAccessCheck implements AccessInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs an AdminAccessCheck object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * Checks access - always denies.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Always returns forbidden.
   */
  public function access() {
    return AccessResult::forbidden('This module is obsolete.');
  }

}
