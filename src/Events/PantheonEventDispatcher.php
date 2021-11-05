<?php

namespace Drupal\search_api_pantheon\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Pantheon Event Dispatcher.
 */
class PantheonEventDispatcher extends SymfonyEventDispatcher implements EventDispatcherInterface {}
