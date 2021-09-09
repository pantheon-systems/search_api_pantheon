<?php

namespace Drupal\search_api_pantheon_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Solr admin form.
 *
 * @package Drupal\search_api_pantheon\Form
 */
class PostSolrSchema extends FormBase {

  /**
   * The PantheonGuzzle service.
   *
   * @var \Drupal\search_api_pantheon\Services\SchemaPoster
   */
  protected SchemaPoster $schemaPoster;

  /**
   * Constructs a new EntityController.
   */
  public function __construct(SchemaPoster $schemaPoster) {
    $this->schemaPoster = $schemaPoster;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('search_api_pantheon.schema_poster'),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_solr_admin_post_schema';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ServerInterface $search_api_server = NULL) {
    $messages = $this->schemaPoster->postSchema($search_api_server->id());
    $form['results'] = [
          '#markup' => implode('<br>', $messages),
      ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
