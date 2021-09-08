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
class PostSolrSchema extends FormBase
{

    /**
     * The PantheonGuzzle service.
     *
     * @var \Drupal\search_api_pantheon\Services\SchemaPoster
     */
    protected SchemaPoster $schemaPoster;

    /**
     * Constructs a new EntityController.
     */
    public function __construct(SchemaPoster $schemaPoster)
    {
        $this->schemaPoster = $schemaPoster;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('search_api_pantheon.schema_poster'),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'search_api_solr_admin_post_schema';
    }

    /**
     * {@inheritdoc}
     *
     * @param array $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     * @param \Drupal\search_api\ServerInterface|null $search_api_server
     *   The search api server machine name.
     *
     * @return array
     *   The form structure.
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\search_api\SearchApiException
     * @throws \Drupal\search_api_solr\SearchApiSolrException
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     */
    public function buildForm(array $form, FormStateInterface $form_state, ServerInterface $search_api_server = null)
    {
        $messages = $this->schemaPoster->postSchema($search_api_server->id());
        $form['results'] = [
            '#markup' => implode('<br>', $messages),
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
    }
}
