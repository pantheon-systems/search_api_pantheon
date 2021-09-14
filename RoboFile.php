<?php

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

use Robo\Result;
use Robo\ResultData;
use Robo\Tasks;
use Spatie\Async\Pool;

/**
 *
 */
class RoboFile extends Tasks
{
    /**
     * @var \Spatie\Async\Pool
     */
    protected Pool $pool;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->pool = new Pool();
        $speech = $this->say('Starting the demo...');
        $speech->start();
        $speech->wait();
    }

    /**
     * @var string
     */
    public static $TERMINUS_EXE = '/usr/local/bin/t3';

    /**
     * @param string|null $site_name
     */
    public function demoFull(string $site_name = null, string $env = 'dev')
    {


        if (empty($site_name)) {
            $site_name = substr(\uniqid('demo-'), 0, 12);
        }

        $options = isset($_SERVER['TERMINUS_ORG']) ? ['org' => $_SERVER['TERMINUS_ORG']] : [];

        // Step 1: Create new site based on drupal9 upstream
        $this->demoCreateSite($site_name, $options);

        // Step 2: Clone site locally
        $this->demoCloneSite($site_name);

        // Step 3: add pantheon_systems/search_api_pantheon to the composer file
        $this->demoRequireSolr($site_name);

        // Step 4: Check in updated composer.*
        $this->demoGitPush($site_name);

        // Step 5: Do a site install & Enable search_api_pantheon
        $this->demoSiteInstall($site_name);

        // Step 7: Generate Sample Content

        // Step 8: Create an index of generated content

        // Step 9: Create a search page for the new index

        // Step 10: Show search page returning results
    }

    /**
     * @param string $text
     */
    protected function say($text)
    {
        return new Symfony\Component\Process\Process([
            '/usr/bin/say',
            '-f Daniel',
            $text
        ]);
    }

    public function demoSetSearch(string $site_name, string $env = 'dev')
    {
        $text = <<<EOF

        When pantheon_search goes into general availability you will be able to
        set the search type using the pantheon dot Y M L file.
        During the pre-launch of Pantheon Search, we have to use a couple of commands
        behind the scenes. Pardon our dust.

EOF;
        $narration = $this->say($text);
        $narration->start();
        $toPost = json_encode([
            'type' => 'change_search_version',
            'params' => [
                'kind' => 'solr',
                'version' => '8',
            ],
        ]);
        $site_id = exec(static::$TERMINUS_EXE . ' site:info ' . $site_name . ' --format=json | jq -r .id');

        $command = sprintf("ygg /sites/%s/environments/%s/workflows -X POST '%s'", $site_id, $env, $toPost);
        $narration->wait();
        \Kint::dump(get_defined_vars());
        exit();
        return $this->taskSshExec($_SERVER['PANTHEON_YGG'], $_SERVER['PANTHEON_YGG_USER'])
            ->exec($command)
            ->run();
    }


    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCreateSite(string $site_name, array $options = ['org' => null])
    {
        $text = sprintf('I am generating a demo site we will use for this demo. The site name is: %s', $site_name);
        $narration = $this->say($text);
        $narration->start();
        $toReturn = $this->taskExec(static::$TERMINUS_EXE)
            ->args('site:create', $site_name, $site_name, 'drupal9');
        if ($options['org'] !== null) {
            $toReturn->option('org', $options['org']);
        }
        $result = $toReturn->run();
        $narration->wait();
        return $result;
    }

    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCloneSite(string $site_name)
    {
        $narration = $this->say(
            'I am using the new local terminus command to clone a copy of the sites codebase into my local folder'
        );
        $narration->start();
        $toReturn = $this->taskExec(static::$TERMINUS_EXE)
            ->args('local:clone', $site_name)
            ->run();
        $narration->wait();
        return $toReturn;
    }

    /**
     * @param string $site_name
     *
     * @return int
     */
    public function demoRequireSolr(string $site_name)
    {

        $narration = $this->say(
            'I am requiring the Search A P I Pantheon module for this site with composer'
        );
        $narration->start();
        $site_folder = $this->getSiteFolder($site_name);
        chdir($site_folder);
        $this->taskExec('composer')
            ->args(
                'require',
                'pantheon-systems/search_api_pantheon ^8',
                'drupal/redis',
                'drupal/devel',
                'drupal/devel_generate'
            )
            ->run();
        $narration->wait();
        return Result::EXITCODE_OK;
    }

    /**
     * @param string $site_name
     *
     * @return string
     */
    protected function getSiteFolder(string $site_name)
    {
        return $_SERVER['HOME'] . '/pantheon-local-copies/' . $site_name;
    }

    /**
     * @param string $site_name
     */
    public function demoGitPush(string $site_name, string $env = 'dev')
    {
        $narration = $this->say(
            'I am adding the changes from the composer file to the git repo...'
        );
        $site_folder = $this->getSiteFolder($site_name);
        chdir($site_folder);
        try {
            $narration->start();
            $git = new \CzProject\GitPhp\Git();
            $repo = $git->open($site_folder);
            $repo->addAllChanges();
            $narration->wait();
            $narration = $this->say('committing the changes');
            $narration->start();
            $repo->commit('changes committed from demo script');
            $narration->wait();
            $narration = $this->say('Ensuring the connection in the environment is set to git.');
            $narration->start();
            $this->taskExec('t3')
                ->args('connection:set', $site_name . '.' . $env, 'git')
                ->run();
            $narration->wait();
            $narration = $this->say('And then pushing them back up to pantheon.');
            $narration->start();
            $this->_exec('git push origin master');
            $narration->wait();
        } catch (\Exception $e) {
            $this->output()->write($e->getMessage());
            return ResultData::EXITCODE_ERROR;
        } catch (\Throwable $t) {
            $this->output()->write($t->getMessage());
            return ResultData::EXITCODE_ERROR;
        }
        return ResultData::EXITCODE_OK;
    }

    /**
     * @param string $site_name
     * @param string $env
     * @param string $profile
     *
     * @return int
     */
    public function demoSiteInstall(string $site_name, string $env = 'dev', string $profile = 'demo_umami')
    {
        $text = sprintf(
            'In order to build a new site, is it ok if I erase everything in the database on %s %s environment?',
            $site_name,
            $env
        );
        $narration = $this->say($text);
        $narration->start();
        $this->confirm(
            "Type 'y' to erase the database {$site_name}.${env} with '{$profile}' profile"
        );
        if (!isset($_ENV['PANTHEON_ENVIRONMENT'])) {
            $narration = $this->say('I am installing a default demo umahmey site.');
            $narration->start();
            $this->taskExec(static::$TERMINUS_EXE)
                ->args('drush', $site_name . '.' . $env, '--', 'site:install', $profile, '-y')
                ->options([
                    'account-name' => 'admin',
                    'site-name' => $site_name,
                    'locale' => 'en'
                ])
            ->run();
            $narration->wait();
            $narration = $this->say(
                'And now I am enabling the modules necessary to run search A P I and generate dummy content.'
            );
            $narration->start();
            $this->taskExec(static::$TERMINUS_EXE)
                ->args(
                    'drush',
                    $site_name . '.' . $env,
                    'pm-enable',
                    'redis',
                    'devel',
                    'devel_generate',
                    'search_api',
                    'search_api_solr',
                    'search_api_pantheon',
                    'search_api_pantheon_admin',
                )
            ->run();
            $narration->wait();
        }
        if (isset($_ENV['PANTHEON_ENVIRONMENT'])) {
            $this->_exec(
                "drush site:install --account-name=admin --site-name={$site_name} --locale=en --yes  {$profile}"
            );
            $this->_exec(
                'drush pm-enable --yes  redis search_api search_api_solr search_api_pantheon search_api_pantheon_admin devel devel_generate'
            );
        }
        return ResultData::EXITCODE_OK;
    }

    public function demoCheckT3()
    {
        if (!file_exists(static::$TERMINUS_EXE) || !is_executable(static::$TERMINUS_EXE)) {
            $narration = $this->say('This demo makes extensive use of the Terminus 3 phar. Can I install it for you?');
            $narration->start();
            $this->confirm(
                'This demo makes extensive use of the Terminus 3 phar. Can I install it for you using homebrew?'
            );
            $narration->wait();
            $result = $this->taskExec('brew install pantheon-systems/external/t3')->run();
            if (!$result->wasSuccessful()) {
                $narration = $this->say(
                    'Unfortunately the install was not successful and we will need to stop the demo at this time.'
                );
                $narration->start();
                $narration->wait();
                exit(1);
            }
        }
        return Result::EXITCODE_OK;
    }
}
