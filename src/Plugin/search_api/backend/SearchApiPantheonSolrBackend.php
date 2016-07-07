<?php

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;



use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Solarium\Client;

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
  public function getConfiguration() {
    return $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    $pantheon_specific_configuration = [
      'scheme' => 'https',
      'host' => pantheon_variable_get('pantheon_index_host'),
      'port' => pantheon_variable_get('pantheon_index_port'),
      'path' => '/sites/self/environments/' . $_ENV['PANTHEON_ENVIRONMENT'] . '/index',
    ];

    return $pantheon_specific_configuration + parent::defaultConfiguration();
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


  }

}
