<?php

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

use Robo\Tasks;

/**
 *
 */
class RoboFile extends Tasks
{

    /**
     * @var string
     */
    public static $TERMINUS_EXE = '/usr/local/bin/t3';

    /**
     *
     */
    public function demoFull()
    {
        $site_name = substr(\uniqid('demo-'), 0, 12);

        // Step 1: Create new site based on drupal9 upstream
        $this->demoCreateSite($site_name);

        // Step 2: Clone the site locally
        $this->demoCloneSite($site_name);

        // Step 3: add  pantheon_systems/search_api_pantheon to the composer file
        $this->demoRequireSolr($site_name);

        // Step 4: Check in updated composer.*

        // Step 5: Do a site install

        // Step 6: Enable search_api_pantheon

        // Step 7: Generate Sample Content

        // Step 8: Create an index of generated content

        // Step 9: Create a search page for the new index

        // Step 10: Show search page returning results
    }


    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCreateSite(string $site_name)
    {
        return $this->taskExec(static::$TERMINUS_EXE)
            ->args('site:create', $site_name, $site_name, 'drupal9')
            ->option('org', '5ae1fa30-8cc4-4894-8ca9-d50628dcba17')
            ->run();
    }

    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCloneSite(string $site_name)
    {
        return $this->taskExec(static::$TERMINUS_EXE)
            ->args('local:clone', $site_name)
            ->run();
    }


    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoRequireSolr(string $site_name)
    {
        chdir($_SERVER['HOME'] . '/pantheon-local-copies/' . $site_name);
        return $this->taskExec('composer')
            ->args('require', 'pantheon-systems/search_api_pantheon', '^8')
            ->run();
    }

    /**
     * @param string $site_name
     */
    public function demoGitPush(string $site_name)
    {
        chdir($_SERVER['HOME'] . '/pantheon-local-copies/' . $site_name);

    }

}
