<?php

namespace Drupal\search_api_pantheon\Solarium\EventDispatcher;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A helper to decorate the legacy EventDispatcherInterface::dispatch().
 */
final class Psr14Bridge extends ContainerAwareEventDispatcher implements EventDispatcherInterface {

  /**
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $dispatcher;

  public function __construct(ContainerAwareEventDispatcher $eventDispatcher) {
    $this->dispatcher = $eventDispatcher;
  }

  /**
   * Call magic method to account for Symfony\Contracts vs Symfony\Component method declarations.
   */
  public function __call($name, $args) {
    if ($name === 'dispatch') {
      if (count($args) >= 1) {
        return $this->doDispatch($args[0], $args[1] ?? NULL);
      }
    }
  }

  public function doDispatch($event, $null = NULL) {
    if (\is_object($event)) {
      return $this->dispatcher->dispatch(\get_class($event), new EventProxy($event));
    }
    return $this->dispatcher->dispatch($event, $null);
  }

  public function addListener($event_name, $listener, $priority = 0) {
    $this->dispatcher->addListener($event_name, $listener, $priority);
  }

}
