<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

class CoreStatusMonitor {
  use StringTranslationTrait;

  protected $state;
  protected $logger;
  protected $messenger;
  
  const SCHEMA_VERSION_KEY = 'search_api_pantheon.schema_version';
  const CORE_STATUS_KEY = 'search_api_pantheon.core_status';
  const CHECK_INTERVAL = 3600; // 1 hour

  public function __construct(
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->state = $state;
    $this->logger = $logger_factory->get('search_api_pantheon');
    $this->messenger = $messenger;
  }

  public function checkCoreStatus($force = FALSE) {
    $last_check = $this->state->get('search_api_pantheon.last_status_check', 0);
    
    if (!$force && (time() - $last_check) < self::CHECK_INTERVAL) {
      return;
    }

    try {
      $status = $this->getCurrentStatus();
      $this->state->set(self::CORE_STATUS_KEY, $status);
      $this->state->set('search_api_pantheon.last_status_check', time());
      
      $this->validateStatus($status);
      
    } catch (\Exception $e) {
      $this->logger->error('Core status check failed: @message', [
        '@message' => $e->getMessage()
      ]);
      $this->messenger->addError($this->t('Solr core status check failed. Please check logs.'));
    }
  }

  protected function validateStatus(array $status) {
    // Check schema version
    $current_version = $status['schema']['version'] ?? null;
    $expected_version = $this->state->get(self::SCHEMA_VERSION_KEY);
    
    if ($current_version !== $expected_version) {
      $this->logger->warning('Schema version mismatch. Expected: @expected, Found: @current', [
        '@expected' => $expected_version,
        '@current' => $current_version
      ]);
      $this->messenger->addWarning($this->t('Solr schema version mismatch detected.'));
    }

    // Check core status
    if (($status['core']['uptime'] ?? 0) < 1) {
      $this->messenger->addError($this->t('Solr core is not responding properly.'));
    }
  }
}