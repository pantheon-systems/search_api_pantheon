<?php

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\Solr\SolrHelper;
use Solarium\Client;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;

/**
 * Apache Solr backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_pantheon_solr",
 *   label = @Translation("Solr on Pantheon"),
 *   description = @Translation("Index items using an Apache Solr search server on Pantheon.")
 * )
 */
class SearchApiPantheonSolrBackend extends SearchApiSolrBackend implements SolrBackendInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ModuleHandlerInterface $module_handler, Config $search_api_solr_settings, LanguageManagerInterface $language_manager) {

    parent::__construct( $configuration, $plugin_id, $plugin_definition, $module_handler, $search_api_solr_settings, $language_manager);

    $this->configuration = $this->internalConfiguration();
    $solr_helper = new SolrHelper($this->configuration);
    $this->setSolrHelper($solr_helper);
  }

  /**
   * This configuration is needed by the parent class.
   *
   * However, as far as the Drupal Config Management sysytem is concerned
   * the only exportable, user-changable configuration is the schema file.
   */
  protected function internalConfiguration() {
    $pantheon_specific_configuration = [];
    if (!empty($_ENV['PANTHEON_ENVIRONMENT'])) {
      $pantheon_specific_configuration = [
        'scheme' => 'https',
        'host' => pantheon_variable_get('pantheon_index_host'),
        'port' => pantheon_variable_get('pantheon_index_port'),
        'path' => '/sites/self/environments/' . $_ENV['PANTHEON_ENVIRONMENT'] . '/index',
      ];
    }

    return $pantheon_specific_configuration + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'schema' => '',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $this->internalConfiguration();
    // Update the configuration of the solrHelper as well by replacing it by a
    // new instance.
    $solr_helper = new SolrHelper($this->configuration);
    $this->setSolrHelper($solr_helper);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['schema'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Schema location (This field is not yet used)'),
      '#description' => $this->t('@todo use this configuration form to set the location of the schema file. Use the the submit handler to post the schema. https://www.drupal.org/node/2763089'),
      '#default_value' => $this->configuration['schema'],
      '#disabled' => TRUE,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // @todo, the schema will be set and posted here.
    https://www.drupal.org/node/2763089
    $this->configuration = $this->defaultConfiguration();
  }

  /**
   * Creates a connection to the Solr server as configured in $this->configuration.
   */
  protected function connect() {
    if (!$this->solr) {
      $this->solr = new Client();
    //  $this->solr->setAdapter('Solarium\Core\Client\Adapter\Curl');

    $this->solr->setAdapter('Drupal\search_api_pantheon\PantheonCurl');


      $this->solr->createEndpoint($this->configuration + ['key' => 'core'], TRUE);
      $this->getSolrHelper()->setSolr($this->solr);
    }
  }

}
