<?php

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

use CzProject\GitPhp\Git;
use Robo\Result;
use Robo\ResultData;
use Robo\Tasks;
use Symfony\Component\Yaml\Yaml;

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
     * @var \DateTime
     */
    public DateTime $started;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->started = new DateTime();
    }

    /**
     * @param string|null $site_name
     */
    public function demoFull(string $site_name = null, string $env = 'dev')
    {
        $this->demoCheckT3();

        if (empty($site_name)) {
            $site_name = substr(\uniqid('demo-'), 0, 12);
        }

        $options = isset($_SERVER['TERMINUS_ORG']) ? ['org' => $_SERVER['TERMINUS_ORG']] : [];
        $this->demoIntro();
        $this->demoCreateSite($site_name, $options);
        $this->demoSiteSearch($site_name);
        $this->demoEnableRedis($site_name);
        $this->demoConnectionGit($site_name, 'dev', 'sftp');
        $this->demoSiteInstall($site_name);
        $this->demoCloneSite($site_name);
        $this->demoRequireSolr($site_name);
        $this->demoEnvSolr($site_name);
        $this->demoConnectionGit($site_name);
        $this->demoGitPush($site_name);
        $this->narrateCreateIndex($site_name);
        $this->narrateDisambiguation();
        $this->narrateCreatePage();
        $this->narrateOutro();
        $this->demoModuleEnable($site_name);
        $this->demoLoginBrowser($site_name);
    }

    /**
     * @return int|void
     */
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
        return ResultData::EXITCODE_OK;
    }

    /**
     * @param string $text
     */
    protected function say($text, string $step_id = 'narration')
    {
        $now = new \DateTime();

        $filename = 'narration-' .
            $now->diff($this->started)->format('%I-%S-%F') . '-' . $step_id . '.m4a';
        $this->output->writeln('/Users/Shared/' . $filename);
        return (new Symfony\Component\Process\Process([
            '/usr/bin/say',
            '--voice=Daniel',
            "--output-file={$filename}",
            '--file-format=m4af',
            $text,
        ], '/Users/Shared'))
            ->enableOutput()
            ->setTty(true);
    }

    /**
     *
     */
    public function demoIntro()
    {
        $narration_text = <<<EOF

            If you have ever set up an apache solr server, you know what a pain it is.

            Many times you are forced to copy config files by hand and edit them directly
            on the server.

            Then add the complexity of multiple cores per environment and you have an
            dev ops administrative task that can bring a project on a tight deadline to a
            grinding halt.

            Especially if the world of hosting java applications is new to you.

            Hello, we at Pantheon would like to walk through the basic
            functionality on our new solar eight service.

EOF;
        $narration = $this->say($narration_text, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCreateSite(string $site_name, array $options = ['org' => null])
    {
        $this->narrateCreateSite();
        $toReturn = $this->taskExec(static::$TERMINUS_EXE)
            ->args('site:create', $site_name, $site_name, 'drupal9');
        if ($options['org'] !== null) {
            $toReturn->option('org', $options['org']);
        }
        $result = $toReturn->run();
        $this->waitForWorkflow($site_name);
        return $result;
    }

    /**
     *
     */
    public function narrateCreateSite()
    {
        $narration_content = <<<EOF

        Now let's begin the demonstration at the beginning. While you've been listening
        to me blather on we've been creating a new site to use with this demo.

        The new site will use the Drupal 9 upstream available in our dashboard and we will
        go from there.
EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     */
    public function waitForWorkflow(string $site_name)
    {
        $this->output()->write('Waiting for platform');
        do {
            if (isset($finished)) {
                sleep(5);
                $finished = null;
            }
            exec(
                't3 workflow:info:status --format=json ' . $site_name,
                $finished,
                $status
            );
            if ($this->output()->isVerbose()) {
                \Kint::dump(get_defined_vars());
            }
        } while (empty($finished));
        $this->output()->writeln('');
    }

    /**
     * @param string $site_name
     * @param string $env
     */
    public function demoSiteSearch(string $site_name, string $env = 'dev')
    {
        $this->narrateSiteSearch();
        $this->taskExec(static::$TERMINUS_EXE)
            ->args('solr:enable', $site_name)
            ->run();
        $this->waitForWorkflow($site_name);
    }

    /**
     *
     */
    public function narrateSiteSearch()
    {
        $narration_content = <<<EOF

        Search is enabled on a site level and then configured per environment.
        I am going to use terminus to enable pantheon search for the site.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     * @param string $env
     */
    public function demoEnableRedis(string $site_name, string $env = 'dev')
    {
        $this->taskExec(static::$TERMINUS_EXE)
            ->args('redis:enable', $site_name)
            ->run();
        $this->waitForWorkflow($site_name);
    }

    /**
     * @param string $site_name
     * @param string $env
     */
    public function demoConnectionGit(string $site_name, string $env = 'dev', string $connection = 'git')
    {
        $narration = $this->say('Ensuring the connection in the environment is set to git.');
        $narration->start();
        $this->taskExec('t3')
            ->args('connection:set', $site_name . '.' . $env, $connection)
            ->run();
        $narration->wait();
        $this->waitForWorkflow($site_name);
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
        $this->narrateSiteInstall();
        $this->taskExec(static::$TERMINUS_EXE)
            ->args('drush', $site_name . '.' . $env, '--', 'site:install', $profile, '-y')
            ->options([
                'account-name' => 'admin',
                'site-name' => $site_name,
                'locale' => 'en',
            ])
            ->run();
        $this->waitForWorkflow($site_name);
        return ResultData::EXITCODE_OK;
    }

    /**
     *
     */
    public function narrateSiteInstall()
    {
        $narration_content = <<<EOF

        With the Pantheon site provisioned and upstream chosen,
        we can now install Drupal itself in the dev environment.
        Using drush we will select the "u-mom-mee" profile that
        contains sample content we can then use to search with Solr.

        This installation process takes a few minutes as database tables are built out and when a site is being
        installed for the first time, understand that to create the folders that drupal needs, you must
        have the site in SFTP mode so the drupal installer can write to the filesystem.


        And there we go, I have "u-mom-mee" installed, I am logged in. Next I will need to enable the
        Solr infrastructure itself, and I will need to add some Drupal modules.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     *
     * @return \Robo\Result
     */
    public function demoCloneSite(string $site_name)
    {
        $this->narrateCloneSite();
        $toReturn = $this->taskExec(static::$TERMINUS_EXE)
            ->args('local:clone', $site_name)
            ->run();
        return $toReturn;
    }

    /**
     *
     */
    public function narrateCloneSite()
    {
        $narration_content = <<<EOF

        To enable Solr infrastructure and add the modules I will need to add and edit code.
        That's most easily done by bringing the codebase for this site down to my local
        machine and then pushing changes back up.

        I am going to use the new 'local clone" command from terminus three which will put a
        copy of the site in a folder called "pantheon local copies" in my home folder.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     *
     * @return int
     */
    public function demoRequireSolr(string $site_name)
    {
        $this->narrateRequireSolr();
        $site_folder = $this->getSiteFolder($site_name);
        chdir($site_folder);
        $this->taskExec('composer')
            ->args(
                'require',
                'pantheon-systems/search_api_pantheon ^8',
                'drupal/facets',
                'drupal/facets_pretty_paths',
                'drupal/search_api_page',
                'drupal/search_api_autocomplete',
                'drupal/search_api_sorts',
                'drupal/redis',
                'drupal/devel',
                'drupal/devel_generate'
            )
            ->run();
        return Result::EXITCODE_OK;
    }

    /**
     *
     */
    public function narrateRequireSolr()
    {
        $narration_content = <<<EOF

        Once I have a local copy of the site's code, I can use composer to add the necessary
        modules to enable solr.

        I am requiring the Search A P I pantheon module as well as facets, autocomplete,
        sorts and the devel module to generate additional random content.

        Notice I am not worrying about the version of search A P I solar that I need.
        Search A P I Pantheon will require the version of solar it needs. You need never
        consult a grid to figure out what version you need of anything again. We're
        running solr eight, ergo you need search A P I pantheon version eight.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
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
     * @param string $env
     */
    public function demoEnvSolr(string $site_name, string $env = 'dev')
    {
        $this->narrateEnvSolr();
        $site_folder = $this->getSiteFolder($site_name);
        $pantheon_yml_contents = Yaml::parseFile($site_folder . '/pantheon.yml');
        $pantheon_yml_contents['search'] = ['version' => 8];
        $pantheon_yml_contents = Yaml::dump($pantheon_yml_contents);
        file_put_contents($site_folder . '/pantheon.yml', $pantheon_yml_contents);
        $this->output->writeln($pantheon_yml_contents);
    }

    /**
     *
     */
    public function narrateEnvSolr()
    {
        $narration_content = <<<EOF

        After we have enabled Solr for the site, we need to configure the pantheon
        dot Y M L to allow search in the individual environments.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     */
    public function demoGitPush(string $site_name, string $env = 'dev')
    {
        $this->narrateGitPush();
        $site_folder = $this->getSiteFolder($site_name);
        chdir($site_folder);
        try {
            $git = new Git();
            $repo = $git->open($site_folder);
            if ($repo->hasChanges()) {
                $repo->addAllChanges();
                $repo->commit('changes committed from demo script');
            }
            $result = $this->taskExec('git push origin master')
                ->run();
            if ($result instanceof Result && !$result->wasSuccessful()) {
                \Kint::dump($result);
                throw new \Exception("error occurred");
            }
        } catch (\Exception $e) {
            $this->output()->write($e->getMessage());
            return ResultData::EXITCODE_ERROR;
        } catch (\Throwable $t) {
            $this->output()->write($t->getMessage());
            return ResultData::EXITCODE_ERROR;
        }
        $this->waitForWorkflow($site_name);
        return ResultData::EXITCODE_OK;
    }

    /**
     *
     */
    public function narrateGitPush()
    {
        $narration_content = <<<EOF

        Next, I will switch the site over to git push mode then use git
        to commit my changes and push. As you can see in the
        terminal output here, Pantheon as a platform notices that pantheon.yml
        changed and will respond accordingly by configuring a Solr Eight instance.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     *
     */
    public function narrateCreateIndex()
    {
        $narration_content = <<<EOF

        A nice detail of the value Pantheon uniquely adds is that just by enabling
        these modules I get the Search API configuration for a server connection
        automatically configured. Now as the developer I can jump straight to
        configuring an index that determines what content is indexed and how.

        I will start just by indexing a few fields in the content and add the tags field
        to the index so we can use facets.

        Once I have fields configured, I can send all the demo content we
        installed with "U-mommie" to the solr server.


EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     *
     */
    public function narrateDisambiguation()
    {
        $narration_content = <<<EOF

        There's a lot of confusion around enabling the solr service and configuring
        an individual environment to use Solar 8. Let me attempt to clarify.

        In the dashboard panel or using terminus, we can enable solr service for all
        our environments. But the default solr service is our Heirloom solr three.

        You must use the Pantheon dot Y M L to configure solr eight in the same way
        you use it to configure the version of P H P.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     *
     */
    public function narrateCreatePage()
    {
        $narration_content = <<<EOF

        Once I have an index, I can use search A P I page to create a new search page and
        give it a path.

        I have a nice search experience that responds to requests with little muss or fuss.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     *
     */
    public function narrateOutro()
    {
        $narration_content = <<<EOF

        Thank you for watching and happy searching.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     * @param string $env
     * @param string $profile
     */
    public function demoModuleEnable(string $site_name, string $env = 'dev', string $profile = 'demo_umami')
    {
        $this->narrateModuleEnable();
        $this->taskExec(static::$TERMINUS_EXE)
            ->args(
                'drush',
                $site_name . '.' . $env,
                'cr'
            )
            ->run();
        $this->taskExec(static::$TERMINUS_EXE)
            ->args(
                'drush',
                $site_name . '.' . $env,
                'pm-uninstall',
                'search',
            )
            ->run();
        sleep(60);
        $this->taskExec(static::$TERMINUS_EXE)
            ->args(
                'drush',
                $site_name . '.' . $env,
                'cr'
            )
            ->run();
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
                'search_api_page',
                'search_api_pantheon_admin',
            )
            ->run();
        $this->taskExec(static::$TERMINUS_EXE)
            ->args(
                'drush',
                $site_name . '.' . $env,
                'cr'
            )
            ->run();
    }

    /**
     *
     */
    public function narrateModuleEnable()
    {
        $narration_content = <<<EOF

        And now I am enabling the modules necessary to run search A P I and generate dummy content.

        I want to disable the default search module then enable search A P I pantheon and a few others.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

    /**
     * @param string $site_name
     * @param string $env
     */
    public function demoLoginBrowser(string $site_name, string $env = 'dev')
    {
        exec(
            't3 drush ' . $site_name . '.' . $env . ' -- uli admin',
            $finished,
            $status
        );
        $finished = trim(join('', $finished));
        $this->output()->writeln($finished);
        $this->_exec('open ' . $finished);
    }

    /**
     *
     */
    public function narrateEnableRedis()
    {
        $narration_content = <<<EOF

        One more thing we need to do is enable the Redis caching server.

EOF;
        $narration = $this->say($narration_content, __FUNCTION__);
        $narration->start();
        $narration->wait();
    }

}
