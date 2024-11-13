<?php

namespace Drupal\search_api_pantheon\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Cron\CronEvent;
use Drupal\Core\Cron\CronEvents;

class CoreStatusSubscriber implements EventSubscriberInterface {
  protected $coreMonitor;
  protected $state;

  public function __construct(
    CoreStatusMonitor $core_monitor,
    StateInterface $state
  ) {
    $this->coreMonitor = $core_monitor;
    $this->state = $state;
  }

  public static function getSubscribedEvents() {
    return [
      CronEvents::CRON => ['onCron', 0],
    ];
  }

  public function onCron(CronEvent $event) {
    $this->coreMonitor->checkCoreStatus();
  }
}