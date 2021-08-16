<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Schema Poster Test.
 *
 * @package \Drupal\search_api_pantheon
 */
class SchemaPosterTest extends TestCase {

  /**
   * The Logger Factory service mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|mixed|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

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
   * Test the Schema Poster by.
   *
   * @test
   */
  public function testSchemaView() {
    $schema_poster = new SchemaPoster($this->loggerFactory, new PantheonGuzzle());

    $schema = $schema_poster->viewSchema();
    $loggerMock = $this->getMockBuilder(LoggerChannelFactoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    //TODO: replace the mock response
    $upload_response = new GuzzleHttp\Psr7\Response(
      200,
      [],
      '{"couchdb":"Welcome","version":"2.0.0","vendor":{"name":"The Apache Software Foundation"}}'
    );
    $mock = new GuzzleHttp\Handler\MockHandler([$upload_response, $upload_response]);

    $handler = GuzzleHttp\HandlerStack::create($mock);
    $client = new GuzzleHttp\Client(['handler' => $handler]);
    $schemaPoster = new SchemaPoster(
      $loggerMock,
      new PantheonGuzzle($loggerMock)
    );
    // userland code starts
    $server = new \PHPCouchDB\Server(['client' => $client]);
    $this->assertEquals('2.0.0', $server->getVersion());
    $this->assertIsString($schema);
    $this->assertGreaterThan(0, strlen($schema));
  }

}
