<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Services\Endpoint;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use PHPUnit\Framework\TestCase;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

/**
 * Endpoint Class Test.
 *
 * @package \Drupal\search_api_pantheon
 */
class EndpointServiceTest extends TestCase {


  /**
   * @test
   */
  public function testURIGeneration()
  {
    $ep = new Endpoint([
                         'scheme' => 'one',
                         'host' => 'two',
                         'port' => '1234',
                         'path' => 'server-path',
                         'core' => '/core-name',
                         'schema' => '/schema-path',
                         'collection' => NULL,
                         'leader' => FALSE,
                       ]);


    $this->assertEquals('/core-name', $ep->getCore());
    $this->assertEquals('server-path', $ep->getPath());
    $this->assertEquals('one', $ep->getScheme());
    $this->assertEquals('1234', $ep->getPort());
    $this->assertEquals('one://two:1234/', $ep->getBaseUri());
    $this->assertEquals('one://two:1234/server-path/core-name/', $ep->getCoreBaseUri());
    $this->assertEquals('one://two:1234/server-path/schema-path', $ep->getSchemaUploadUri());
  }




}
