<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\search_api_pantheon\Solarium\PantheonCurl;
use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Standard Solr connector.
 *
 * @SolrConnector(
 *   id = "pantheon",
 *   label = @Translation("Pantheon"),
 *   description = @Translation("A connector for Pantheon's Solr server")
 * )
 */
class PantheonSolrConnector extends StandardSolrConnector {

  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    // While normally reading global state should be in ::create() the very
    // point of this class is to force override the configuration with these
    // environment variables. Testability is not a problem thanks to putenv()
    // and more importantly, any testing would be pointless if it didn't
    // assert these overrides.
    if (getenv('PANTHEON_ENVIRONMENT')) {
      $configuration = static::getEnvironmentVariables() + $configuration;
      $configuration['search_api_pantheon_cert'] = ($_SERVER['HOME'] ?? '') . '/certs/binding.pem';
      $configuration['context'] = '';
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->logger = $container->get('logger.channel.search_api_pantheon');
    return $plugin;
  }

  /**
   * @param string $env
   *
   * @return array
   */
  public static function getEnvironmentVariables(): array {
    return [
      'scheme' => 'https',
      'host' => getenv('PANTHEON_INDEX_HOST'),
      'port' => getenv('PANTHEON_INDEX_PORT'),
      'path' => '/' . trim(getenv('PANTHEON_INDEX_PATH'), '/'),
      'core' => trim(getenv('PANTHEON_INDEX_CORE'), '/'),
      'solr_version' => 8,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    foreach (array_keys(static::getEnvironmentVariables()) as $key) {
      if (isset($form[$key])) {
        $form[$key]['#disabled'] = TRUE;
        $form[$key]['#description'] = t('These fields are populated by Pantheon infrastructure".');
      }
    }
    $form['workarounds']['#access'] = FALSE;
    // @todo explore whether jts works.
    $form['advanced']['#access'] = FALSE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerInfo($reset = FALSE) {
    // The parent uses a system-wide endpoint which is not supported on
    // Pantheon.
    return $this->getDataFromHandler($this->configuration['core'] . '/admin/system', $reset);
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(array &$configuration) {
    $client = parent::createClient($configuration);
    if (extension_loaded('curl') && isset($this->configuration['search_api_pantheon_cert'])) {
      $adapter = new PantheonCurl($this->configuration['search_api_pantheon_cert']);
      // This line is copy-pasted from the parent method.
      $adapter->setTimeout($configuration[self::QUERY_TIMEOUT]);
      $client->setAdapter($adapter);
    }
    return $client;
  }

}
