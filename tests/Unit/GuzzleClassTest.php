<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use PHPUnit\Framework\TestCase;

class GuzzleClassTest extends TestCase
{

    public function testGuzzleClient()
    {
        $client = SolrGuzzle::getConfiguredClientInterface('true');
    }
}
