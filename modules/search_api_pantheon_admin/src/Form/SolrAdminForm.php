<?php

namespace Drupal\search_api_pantheon_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Solr admin form.
 *
 * @package Drupal\search_api_pantheon\Form
 */
class SolrAdminForm extends FormBase
{

  /**
   * SolrAdminForm constructor.
   */
  public function __construct()
  {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'solr_admin_form';
  }

  /**
   * {@inheritdoc}
   *
   * @FormElement('vertical_tabs');
   */
  public function buildForm(array $form, FormStateInterface $form_state, ServerInterface $search_api_server = null)
  {
    $form['message'] = [
        '#markup' => '<h3>Admin Interface Coming</h3>'
    ];
    //$form['settings_browser'] = array(
    //  '#type' => 'vertical_tabs',
    //  '#default_tab' => 'config',
    //);
    // $form['config'] = $this->getTab('config', 'config');
    // $form['system'] = $this->getTab('core', 'admin/system');

    /**
     * $form['#attached']['library'][] = 'search_api_pantheon_admin/settings_browser';
     * $form['#attached']['drupalSettings']['searchApiPantheonAdmin'] = [
     * 'serverInfo' => $server_info
     * ];
     * **/
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
  }

  protected function getTab($topic,$config_path) {
    $toReturn = [];
    $client = SolrGuzzle::getConfiguredClientInterface();
    $response = $client->get(Cores::getBaseCoreUri() . $config_path);
    if (!in_array($response->getStatusCode(), [200, 201, 202, 203, 204])) {
      $this->messenger()->addError('Could not access server url: ' . Cores::getBaseCoreUri() . 'admin/config');
      return [];
    }
    $server_info = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($server_info[$topic] as $fieldset => $items) {
      if (is_array($items)) {
        $toReturn = [
          '#type' => 'fieldset',
          '#title' => $fieldset . " - " . $topic,
          '#group' => 'settings_browser',
        ];
        foreach ($items as $key => $value) {
          $toReturn[$key] = [
            '#type' => 'markup',
            '#markup' => is_array($value) ? print_r($value, true) : $value,
          ];
        }
      }
      if (is_string($items)) {
        $toReturn[$fieldset] = [
          '#type' => 'markup',
          '#group' => 'settings_browser',
          '#markup' => vsprintf("%s  =  %s", [
            $fieldset,
            $items
          ]),
        ];
      }

    }
    return $toReturn;
  }


}
