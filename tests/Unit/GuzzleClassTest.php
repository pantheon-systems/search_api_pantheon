<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use PHPUnit\Framework\TestCase;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

class GuzzleClassTest extends TestCase
{

    public function testGuzzleClient()
    {
      // create a new document
        $document = new UpdateDocument();

      // set a field value as property
        $document->id = 15;

      // set a field value as array entry
        $document['population'] = 120000;

      // set a field value with the setField method, including a boost
        $document->setField('name', 'example doc', 3);

      // add two values to a multivalue field
        $document->addField('countries', 'NL');
        $document->addField('countries', 'UK');
        $document->addField('countries', 'US');

      // example: add / remove field with methods
        $document->setField('dummy', 10);
        $document->removeField('dummy');

      // example: add / remove field with methods by setting NULL value
        $document->setField('dummy', 10);
        $document->setField('dummy', null); //this removes the field

      // set a document boost value
        $document->setFieldBoost('name', 2.5);

      // set a field boost
        $document->setFieldBoost('population', 4.5);

      // add it to the update query and also add a commit
        $query = new UpdateQuery();
        $query->addDocument($document);
        $query->addCommit();
      // run it, the result should be a new document in the Solr index
        $queryResult = SolrGuzzle::getSolrClient()->update($query);
        $this->assertTrue($queryResult->getResponse->getStatusCode() == 200);
        $indexStats = SolrGuzzle::getIndexStats();
        $this->assertGreaterThan(0, $indexStats['numDocs']);
    }
}
