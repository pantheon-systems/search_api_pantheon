<?php

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\Solr\SolrHelper;

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

    $this->configuration = $this->internalDefaultConfiguration();
    $solr_helper = new SolrHelper($this->configuration);
    $this->setSolrHelper($solr_helper);
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
      $container->get('language_manager')
    );
  }

  /**
   * This configuration is needed by the parent class.
   *
   * However, as far as the Drupal Config Management sysytem is concerned
   */
  public function internalDefaultConfiguration() {
    return array(
      'scheme' => 'https',
      'host' => pantheon_variable_get('pantheon_index_host'),
      'port' => pantheon_variable_get('pantheon_index_port'),
      'path' => '/sites/self/environments/' . $_ENV['PANTHEON_ENVIRONMENT'] . '/index',
      'schema' => '',
      'core' => '',
      'username' => '',
      'password' => '',
      'excerpt' => FALSE,
      'retrieve_data' => FALSE,
      'highlight_data' => FALSE,
      'skip_schema_check' => FALSE,
      'solr_version' => '',
      'http_method' => 'AUTO',
      'site_hash' => TRUE,
      'autocorrect_spell' => TRUE,
      'autocorrect_suggest_words' => TRUE,
    );
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
    $this->configuration = $this->internalDefaultConfiguration();
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
      '#title' => $this->t('schema location'),
      '#description' => $this->t('@todo use this configuration form to set the location of the schema file. Use the the submit handler to post the schema.'),
      '#default_value' => $this->configuration['schema'],
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

    $this->configuration = array(
      'schema' => '',
    );
  }

}
