<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Guzzle Class Test.
 *
 * @package \Drupal\search_api_pantheon
 */
class GuzzleClassTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $logger = $this->getMockBuilder(LoggerInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->loggerFactory = $this->getMockBuilder(LoggerChannelFactoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->loggerFactory
      ->expects($this->any())
      ->method('get')
      ->with('PantheonSolr')
      ->willReturn($logger);
  }

  /**
   * Test the Pantheon Guzzle Client.
   *
   * @test
   */
  public function testGuzzleClient() {

    $mock = new MockHandler([
      new Response(200, ['X-Foo' => 'Bar'], 'Hello, World'),
      new Response(202, ['Content-Length' => 0]),
      new RequestException('Error Communicating with Server', new Request('GET', 'test')),
      ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    // The first request is intercepted with the first response.
    $response = $client->request('GET', '/');
    echo $response->getStatusCode();
    // > 200
    echo $response->getBody();
    // > Hello, World
    // The second request is intercepted with the second response.
    echo $client->request('GET', '/')->getStatusCode();
    // > 202
  }

}
