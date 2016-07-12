<?php
/**
 * @file
 * Override Solr connection configuration from Search API Solr module.
 */

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\search_api_pantheon\search_api_solr\PantheonSolrHelper;
use Drupal\search_api_pantheon\SchemaPoster;
use Solarium\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Apache Solr backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_pantheon_solr",
 *   label = @Translation("Solr on Pantheon"),
 *   description = @Translation("Index items using Solr on Pantheon.")
 * )
 */
class SearchApiPantheonSolrBackend extends SearchApiSolrBackend implements SolrBackendInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ModuleHandlerInterface $module_handler, Config $search_api_solr_settings, LanguageManagerInterface $language_manager, SchemaPoster $schema_poster) {
    $configuration += $this->internalConfiguration();
    parent::__construct($configuration, $plugin_id, $plugin_definition, $module_handler, $search_api_solr_settings, $language_manager);
    $solr_helper = new PantheonSolrHelper($this->configuration);
    $this->setSolrHelper($solr_helper);
    $this->schemaPoster = $schema_poster;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('config.factory')->get('search_api_solr.settings'),
      $container->get('language_manager'),
      $container->get('search_api_pantheon.schema_poster')
    );
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
    $solr_helper = new PantheonSolrHelper($this->configuration);
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
    $schema_path = drupal_get_path('module', 'search_api_solr') . '/solr-conf/4.x/0schema.xml';
    $this->schemaPoster->postSchema($schema_path);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // @todo, the schema will be set and posted here.
    // https://www.drupal.org/node/2763089
    $this->configuration = $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    if (!$this->solr) {
      $this->solr = new Client();
      // The parent method is overridden so that this alternate adapter class
      // can be set. This line is the only difference from the parent method.
      $this->solr->setAdapter('Drupal\search_api_pantheon\Solarium\PantheonCurl');
      $this->solr->createEndpoint($this->configuration + ['key' => 'core'], TRUE);
      $this->getSolrHelper()->setSolr($this->solr);
    }
  }

}
