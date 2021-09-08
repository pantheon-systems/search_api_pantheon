<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class RoboFile extends \Robo\Tasks
{
    public function testInSite()
    {
        $site_under_test = substr(\uniqid('sap-test-'), 0, 12);

        $this->_exec("drush site:install --account-name=demo --site-name={$project} --locale=en --yes  {$profile}");
        $this->_exec('drush pm-enable --yes  redis search_api search_api_solr search_api_pantheon search_api_pantheon_admin devel devel_generate');


    }
}
