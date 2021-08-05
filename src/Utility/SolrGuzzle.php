<?php

namespace Drupal\search_api_pantheon\Utility;

use Drupal\search_api_pantheon\Endpoint;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Util\Exception;
use Psr\Http\Client\ClientInterface;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Client as SolrClient;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class SolrGuzzle
 * Convenience class to produce a PSR18Adapter configured correctly for our Solr server.
 *
 *
 * @package Drupal\search_api_pantheon\Utility
 */
class SolrGuzzle
{

  /**
   * @return \Solarium\Core\Client\Adapter\AdapterInterface
   */
    public static function getPsr18Adapter(bool $verbose = false): AdapterInterface
    {
        return new Psr18Adapter(
            static::getConfiguredClientInterface($verbose),
            new RequestFactory(),
            new StreamFactory()
        );
    }

  /**
   * @return \Psr\Http\Client\ClientInterface
   */
    public static function getConfiguredClientInterface(bool $verbose = false): ClientInterface
    {
        $cert = $_SERVER['HOME'] . '/certs/binding.pem';
        $guzzleConfig = [
        'base_uri' => Cores::getBaseUri(),
        'http_errors' => false,
        'debug' => $verbose,
        'verify' => false,
        ];
        if (is_file($cert)) {
            $guzzleConfig['cert'] = $cert;
        }
        return GuzzleAdapter::createWithConfig($guzzleConfig);
    }

    public static function getIndexStats(): array
    {
        $client = SolrGuzzle::getConfiguredClientInterface();
        $uri = new Uri(Cores::getBaseCoreUri() . 'admin/luke?stats=true');
        $request = new Request('get', $uri);
        $response = $client->sendRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new Exception($response->getReasonPhrase());
        }
        $stats = json_decode(
            $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $stats['index'] ?? [];
    }

  /**
   * @return \Solarium\Client
   */
    public static function getSolrClient(): SolrClient
    {
        $config = [
        'endpoint' => [],
        ];
        $solr = new SolrClient(
            static::getPsr18Adapter(true),
            new EventDispatcher(),
            $config
        );
        $endpoint = new Endpoint([
                               'collection' => null,
                               'leader' => false,
                               'timeout' => 5,
                               'solr_version' => '8',
                               'http_method' => 'AUTO',
                               'commit_within' => 1000,
                               'jmx' => false,
                               'solr_install_dir' => '',
                               'skip_schema_check' => false,
                             ]);
        $endpoint->setKey('pantheon');
        $solr->addEndpoint($endpoint);
        return $solr;
    }
}
