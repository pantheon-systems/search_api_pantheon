<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon_cache\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_pantheon_cache\EventSubscriber\SolrCommitAwareCacheInvalidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @coversDefaultClass \Drupal\search_api_pantheon_cache\EventSubscriber\SolrCommitAwareCacheInvalidator
 * @group search_api_pantheon
 */
class SolrCommitAwareCacheInvalidatorTest extends TestCase {

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
   * @var \Drupal\Tests\search_api_pantheon_cache\Unit\TestableSolrCommitAwareCacheInvalidator
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

    $this->invalidator = new TestableSolrCommitAwareCacheInvalidator($this->state);
  }

  /**
   * @covers ::invalidateTags
   * @covers ::scheduleReInvalidation
   */
  public function testSearchApiListTagsScheduleReInvalidation(): void {
    $this->invalidator->invalidateTags([
      'node:5',
      'search_api_list:primary',
      'node_list',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertArrayNotHasKey('node:5', $pending);
    $this->assertArrayNotHasKey('node_list', $pending);
  }

  /**
   * @covers ::invalidateTags
   * @covers ::scheduleReInvalidation
   */
  public function testNonSearchTagsIgnored(): void {
    $this->invalidator->invalidateTags([
      'node:5',
      'node_list',
      'config:views.view.search',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertEmpty($pending);
  }

  /**
   * @covers ::onItemsIndexed
   */
  public function testItemsIndexedSchedulesReInvalidation(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('primary');

    $event = new ItemsIndexedEvent($index, ['item_1', 'item_2']);
    $this->invalidator->onItemsIndexed($event);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
  }

  /**
   * @covers ::scheduleReInvalidation
   */
  public function testRapidEditsUpdateTimestamp(): void {
    $this->invalidator->invalidateTags(['search_api_list:primary']);
    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY];
    $first_timestamp = $pending['search_api_list:primary'];

    $this->invalidator->invalidateTags(['search_api_list:primary']);
    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY];
    $second_timestamp = $pending['search_api_list:primary'];

    $this->assertGreaterThanOrEqual($first_timestamp, $second_timestamp);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestDoesNotFireEarly(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() + 6,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestFiresWhenDue(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayNotHasKey('search_api_list:primary', $pending);
    $this->assertContains('search_api_list:primary', $this->invalidator->reInvalidatedTags);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestPreservesNotDueEntries(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
      'search_api_list:secondary' => time() + 60,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayNotHasKey('search_api_list:primary', $pending);
    $this->assertArrayHasKey('search_api_list:secondary', $pending);
    $this->assertContains('search_api_list:primary', $this->invalidator->reInvalidatedTags);
    $this->assertNotContains('search_api_list:secondary', $this->invalidator->reInvalidatedTags);
  }

  /**
   * @covers ::onRequest
   */
  public function testSubRequestIgnored(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST);
    $this->invalidator->onRequest($event);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestEmptyStateNoop(): void {
    $this->invalidator->onRequest($this->createRequestEvent());
    $this->assertArrayNotHasKey(SolrCommitAwareCacheInvalidator::STATE_KEY, $this->stateStorage);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEvents(): void {
    $events = SolrCommitAwareCacheInvalidator::getSubscribedEvents();
    $this->assertArrayHasKey('search_api.items_indexed', $events);
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
  }

  /**
   * @covers ::scheduleReInvalidation
   */
  public function testMultipleIndexesIndependent(): void {
    $this->invalidator->invalidateTags([
      'search_api_list:primary',
      'search_api_list:products',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertArrayHasKey('search_api_list:products', $pending);
    $this->assertCount(2, $pending);
  }

  /**
   * @covers ::invalidateTags
   * @covers ::onRequest
   */
  public function testReInvalidationDoesNotReschedule(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertEmpty($pending);
  }

  /**
   * Creates a main request event for testing.
   */
  protected function createRequestEvent(): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST);
  }

}
