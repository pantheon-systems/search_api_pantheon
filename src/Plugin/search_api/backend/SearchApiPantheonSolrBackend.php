<?php

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;



use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\search_api\data_type\value\TextValue;
use Drupal\search_api\Plugin\search_api\data_type\value\TextValueInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Utility as SearchApiUtility;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\Utility\Utility as SearchApiSolrUtility;
use Drupal\search_api_solr\Solr\SolrHelper;
use Solarium\Client;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\Helper;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Query\Query;
use Solarium\Exception\ExceptionInterface;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Result\Result;
use Solarium\QueryType\Update\Query\Document\Document;
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
   * {@inheritdoc}
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
