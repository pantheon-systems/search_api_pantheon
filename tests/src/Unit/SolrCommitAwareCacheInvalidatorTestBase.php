<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Base class for SolrCommitAwareCacheInvalidator tests.
 */
abstract class SolrCommitAwareCacheInvalidatorTestBase extends TestCase {

  /**
   * In-memory state storage for testing.
   *
   * @var array
   */
  protected array $stateStorage = [];

  /**
   * The mock state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * The invalidator under test.
   *
   * @var \Drupal\Tests\search_api_pantheon\Unit\TestableSolrCommitAwareCacheInvalidator
   */
  protected TestableSolrCommitAwareCacheInvalidator $invalidator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->stateStorage = [];
    $this->state = $this->createMock(StateInterface::class);

    $this->state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStorage[$key] ?? $default;
      });
    $this->state->method('set')
      ->willReturnCallback(function (string $key, $value) {
        $this->stateStorage[$key] = $value;
      });
    $this->state->method('delete')
      ->willReturnCallback(function (string $key) {
        unset($this->stateStorage[$key]);
      });

    $this->invalidator = $this->createInvalidator(TRUE);
  }

  /**
   * Creates an invalidator with the given enabled/disabled state.
   *
   * @param bool $enabled
   *   Whether solr_commit_reinvalidation is enabled.
   *
   * @return \Drupal\Tests\search_api_pantheon\Unit\TestableSolrCommitAwareCacheInvalidator
   *   The configured invalidator.
   */
  protected function createInvalidator(bool $enabled): TestableSolrCommitAwareCacheInvalidator {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($enabled) {
        return match ($key) {
          'solr_commit_reinvalidation' => $enabled,
          'solr_commit_reinvalidation_delay' => 6,
          default => NULL,
        };
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('search_api_pantheon.settings')
      ->willReturn($config);

    return new TestableSolrCommitAwareCacheInvalidator($this->state, $configFactory);
  }

  /**
   * Creates a main request event for testing.
   *
   * @return \Symfony\Component\HttpKernel\Event\RequestEvent
   *   A request event wrapping a main request.
   */
  protected function createRequestEvent(): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST);
  }

}
