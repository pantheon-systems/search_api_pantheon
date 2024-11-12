<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Services\Endpoint;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint Class Test.
 *
 * @package \Drupal\search_api_pantheon
 */
class EndpointServiceTest extends TestCase {

  protected $entityTypeManager;

  protected function setUp(): void {
    parent::setUp();

    // Mock the EntityTypeManagerInterface.
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // Define any specific behavior for methods you expect to call.
    // For example, if you are loading storage for a particular entity type:
    // $storageMock = $this->createMock(EntityStorageInterface::class);
    // $this->entityTypeManager
    //   ->method('getStorage')
    //   ->with('search_api_server')
    //   ->willReturn($storageMock);
  }

  /**
   * Test the endpoint class's ability to generate correct URL's.
   *
   * @test
   */
  // @codingStandardsIgnoreLine
  public function testURIGeneration() {
    $ep = new Endpoint([
      'scheme' => 'one',
      'host' => 'two',
      'port' => '1234',
      'path' => 'server-path',
      'core' => '/core-name',
      'schema' => '/schema-path',
      'collection' => NULL,
      'leader' => FALSE,
      'reload_path' => "/reload-path",
    ], $this->entityTypeManager);

    $this->assertEquals('/core-name', $ep->getCore());
    $this->assertEquals('server-path', $ep->getPath());
    $this->assertEquals('one', $ep->getScheme());
    $this->assertEquals('1234', $ep->getPort());
    $this->assertEquals('one://two:1234/', $ep->getBaseUri());
    $this->assertEquals(
      'one://two:1234/server-path/core-name/',
      $ep->getCoreBaseUri()
    );
    $this->assertEquals(
      'one://two:1234/server-path/schema-path',
      $ep->getSchemaUploadUri()
    );
    $this->assertEquals(
      'one://two:1234/server-path/schema-path',
      $ep->getSchemaUploadUri()
    );
    $this->assertEquals(
      'one://two:1234/server-path/reload-path',
      $ep->getReloadUri()
    );
  }

  public function testReloadPath() {
    $ep = new Endpoint(["reload_path" => "/reload"], $this->entityTypeManager);
    $this->assertEquals("/reload", $ep->getReloadPath());
  }

}
