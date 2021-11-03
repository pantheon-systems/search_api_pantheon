<?php

namespace Drupal\search_api_pantheon\Events;


use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

class PantheonEventDispatcher
  extends SymfonyEventDispatcher
  implements EventDispatcherInterface {

  protected $listeners = [];
  protected $sorted = [];

  public function getListeners($eventName = NULL) {
    if (empty($this->listeners)){
      return [];
    }
    
    return parent::getListeners(
      $eventName
    );
  }

}
