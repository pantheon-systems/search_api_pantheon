<?php

namespace Drupal\search_api_pantheon\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Pantheon Event Dispatcher.
 */
class PantheonEventDispatcher extends SymfonyEventDispatcher implements EventDispatcherInterface {

  /**
   * @var array
   */
  protected $listeners = [];

  /**
   * @var array
   */
  protected $sorted = [];

  /**
   * Override to fix issues with zero listeners.
   *
   * @param null $eventName
   *   Name of the Event in question.
   *
   * @return array|mixed
   *   Any listeners or empty array.
   */
  public function getListeners($eventName = NULL) {
    if (empty($this->listeners)) {
      return [];
    }

    return parent::getListeners(
      $eventName
    );
  }

}
