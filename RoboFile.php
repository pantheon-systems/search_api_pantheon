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
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 *
 */
class RoboFile extends Tasks {
  /**
   * @var string
   */
  public static $TERMINUS_EXE = '/usr/local/bin/terminus';
  /**
   * @var \DateTime
   */
  public DateTime $started;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->started = new DateTime();
    require_once 'vendor/autoload.php';
  }

  /**
   *
   */
  public function testFull(int $drupal_version = 9, string $site_name = NULL) {
    $constraint = $this->getCurrentConstraint();
    $this->testCheckT3();
    // This is a GitHub secret.
    $options = isset($_SERVER['TERMINUS_ORG']) ? ['org' => $_SERVER['TERMINUS_ORG']] : [];
    if (empty($site_name)) {
      $site_name = substr(\uniqid('test-'), 0, 12);
      if ($_SERVER['GITHUB_RUN_NUMBER']) {
        // Ensure that 2 almost parallel runs do not collide.
        $site_name .= '-' . $_SERVER['GITHUB_RUN_NUMBER'];
      }
    }
    $this->testCreateSite($site_name, $options);
    $this->testConnectionGit($site_name, 'dev', 'git');
    $this->testCloneSite($site_name);
    if ($drupal_version === 8) {
      $this->testDowngradeToDrupal8($site_name);
    }
    $this->testRequireSolr($site_name, $constraint);
    $this->testGitPush($site_name);
    $this->testConnectionGit($site_name, 'dev', 'sftp');
    $this->testSiteInstall($site_name);
    $this->testConnectionGit($site_name, 'dev', 'git');
    try {
      // This should fail because Solr has not been enabled on the environment yet.
      $this->testModuleEnable($site_name);
    }
    catch (\Exception $e) {
      \Kint::dump($e);
      exit(1);
    }
    $this->setSiteSearch($site_name, 'enable');
    $this->testEnvSolr($site_name);
    $this->testGitPush($site_name);
    // This should succeed now that Solr has been enabled.
    $this->testModuleEnable($site_name);

    // Test all the Solr things.
    $this->testSolrEnabled($site_name);

    // Test creating Solr index.
    $this->testSolrIndexCreate($site_name, 'dev');

    // Test select query.
    $this->testSolrSelect($site_name, 'dev');

    // Finally, run Solr diagnose.
    $this->testSolrDiagnose($site_name, 'dev');

    $this->output()->write( 'All done! 🎉' );
    return ResultData::EXITCODE_OK;
  }

  /**
   *
   */
  protected function getCurrentConstraint(): string {
    $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
    if ($branch !== 'HEAD') {
      return "${branch}-dev";
    } else {
      $tag = trim(shell_exec('git describe --exact-match --tags $(git log -n1 --pretty=\'%h\')'));
      if ($tag) {
        return $tag;
      } else {
        // Maybe we are on a PR.
        $branch = $_SERVER['GITHUB_HEAD_REF'];
        $branch_parts = explode('/', $branch);
        $branch = end($branch_parts);
        if ($branch) {
          return "${branch}-dev";
        }
      }
    }
    // Final fallback, return "^8";
    return '^8';
  }

  /**
   * @return int|void
   */
  public function testCheckT3() {
    if (!file_exists(static::$TERMINUS_EXE) || !is_executable(static::$TERMINUS_EXE)) {
      $this->confirm(
            'This demo makes extensive use of the Terminus 3 phar. Can I install it for you using homebrew?'
        );
      $result = $this->taskExec('brew install pantheon-systems/external/terminus')->run();
      if (!$result->wasSuccessful()) {
        exit(1);
      }
      // @todo check for build tools plugin "wait" command.
    }
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   *
   * @return \Robo\Result
   */
  public function testCreateSite(string $site_name, array $options = ['org' => NULL]) {
    $site_info = $this->siteInfo($site_name);
    if (empty($site_info)) {
      $toReturn = $this->taskExec(static::$TERMINUS_EXE)
        ->args('site:create', $site_name, $site_name, 'drupal9');
      if ( !empty( $options['org'] ) ) {
        $toReturn->option('org', $options['org']);
      }
      $toReturn->run();
      $this->waitForWorkflow($site_name);
      $site_info = $this->siteInfo($site_name);
    }
    return $site_info;
  }

  /**
   * @param string $site_name
   */
  public function waitForWorkflow(string $site_name, string $env = 'dev') {
    $this->output()->write('Checking workflow status', true);

    exec(
      "terminus workflow:info:status $site_name.$env",
      $info
    );

    $info = $this->cleanUpInfo( $info );
    $this->output()->write( $info['workflow'], true );

    // Wait for workflow to finish only if it hasn't already. This prevents the workflow:wait command from unnecessarily running for 260 seconds when there's no workflow in progress.
    if ( $info['status'] !== 'succeeded' ) {
      $this->output()->write('Waiting for platform', true);
      exec(
            "terminus build:workflow:wait --max=260 --progress-delay=5 $site_name.$env",
            $finished,
            $status
        );
    }

    if ($this->output()->isVerbose()) {
      \Kint::dump(get_defined_vars());
    }
    $this->output()->writeln('');
  }

  /**
   * Takes the output from a workflow:info:status command and converts it into a human-readable and easily parseable array.
   *
   * @param array $info Raw output from 'terminus workflow:info:status'
   *
   * @return array An array of workflow status info.
   */
  private function cleanUpInfo( array $info ) : array {
    // Clean up the workflow status data and assign values to an array so it's easier to check.
    foreach ($info as $line => $value) {
      $ln = array_values( array_filter( explode( "  ", trim( $value ) ) ) );

      // Skip lines with only one value. This filters out the ASCII dividers output by the command.
      if ( count( $ln ) > 1  ) {
        if ( in_array( $ln[0], [ 'Started At', 'Finished At' ] ) ) {
          $ln[0] = trim( str_replace( 'At', '', $ln[0] ) );
          // Convert times to unix timestamps for easier use later.
          $ln[1] = strtotime( $ln[1] );
        }

        $info[ str_replace( ' ', '-', strtolower( $ln[0] ) ) ] = trim( $ln[1] );
      }

      // Remove the processed line.
      unset( $info[ $line ] );
    }

    return $info;
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function setSiteSearch(string $site_name, $value = 'enabled') {
    $this->taskExec(static::$TERMINUS_EXE)
      ->args('solr:' . $value, $site_name)
      ->run();
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testConnectionGit(string $site_name, string $env = 'dev', string $connection = 'git') {
    $this->taskExec('terminus')
      ->args('connection:set', $site_name . '.' . $env, $connection)
      ->run();
  }

  /**
   * @param string $site_name
   *
   * @return \Robo\Result
   */
  public function testCloneSite(string $site_name) {
    if (!is_dir($this->getSiteFolder($site_name))) {
      $toReturn = $this->taskExec(static::$TERMINUS_EXE)
        ->args('local:clone', $site_name)
        ->run();
      return $toReturn;
    }
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   *
   * @return int
   */
  public function testDowngradeToDrupal8(string $site_name) {
    $site_folder = $this->getSiteFolder($site_name);
    chdir($site_folder);

    // Remove composer lock.
    $this->taskExec('rm')
      ->args('composer.lock')
      ->run();


    $this->taskExec('composer')
      ->args(
        'require',
        '--no-update',
        'drupal/core-recommended:^8',
        'pantheon-systems/drupal-integrations:^8'
      )
      ->run();

    $this->taskExec('composer')
      ->args('update')
      ->run();
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   *
   * @return int
   */
  public function testRequireSolr(string $site_name, string $solr_branch = '^8') {
    $site_folder = $this->getSiteFolder($site_name);
    chdir($site_folder);
    $this->taskExec('composer')
      ->args(
              'require',
              'pantheon-systems/search_api_pantheon ' . $solr_branch,
              'drupal/search_api_autocomplete',
              'drupal/search_api_sorts',
              'drupal/facets',
              'drupal/redis',
              'drupal/devel',
              'drupal/devel_generate'
          )
      ->run();
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   *
   * @return string
   */
  protected function getSiteFolder(string $site_name) {
    return $_SERVER['HOME'] . '/pantheon-local-copies/' . $site_name;
  }

  /**
   * @param string $site_name
   */
  public function testGitPush(string $site_name, string $env = 'dev') {
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
    }
    catch (\Exception $e) {
      $this->output()->write($e->getMessage());
      return ResultData::EXITCODE_ERROR;
    }
    catch (\Throwable $t) {
      $this->output()->write($t->getMessage());
      return ResultData::EXITCODE_ERROR;
    }
    $this->waitForWorkflow($site_name);
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   * @param string $env
   * @param string $profile
   *
   * @return int
   */
  public function testSiteInstall(string $site_name, string $env = 'dev', string $profile = 'demo_umami') {
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
   * @param string $site_name
   * @param string $env
   * @param string $profile
   */
  public function testModuleEnable(string $site_name, string $env = 'dev', string $profile = 'demo_umami') {
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
    $this->waitForWorkflow($site_name);
    $this->taskExec(static::$TERMINUS_EXE)
      ->args(
              'drush',
              $site_name . '.' . $env,
              '--',
              'pm-enable',
              '--yes',
              'search_api_pantheon',
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
   * Run through various diagnostics to ensure that Solr8 is enabled and working.
   *
   * @param string $site_name
   * @param string $env
   *
   * @return \Robo\Result
   */
  public function testSolrEnabled( string $site_name, string $env = 'dev' ) {

    try {
      // Attempt to ping the Pantheon Solr server.
      $this->output()->write('Attempting to ping the Solr server...', true);
      $ping = $this->taskExec( static::$TERMINUS_EXE )
        ->args(
          'drush',
          "$site_name.$env",
          '--',
          'search-api-pantheon:ping'
        )
        ->run();

        if ( $ping instanceof Result && ! $ping->wasSuccessful() ) {
          \Kint::dump( $ping );
          throw new \Exception( 'An error occurred attempting to ping Solr server' );
        }

        // Check that Solr8 is enabled.
        $this->output()->write('Checking for Solr8 search API server...', true);
        exec(
          "terminus remote:drush $site_name.$env -- search-api-server-list | grep pantheon_solr8",
          $server_list
        );

        if ( stripos( $server_list[0], 'enabled' ) === false ) {
          \Kint::dump( $server_list );
          throw new \Exception( 'An error occurred checking that Solr8 was enable.d' );
        }
    }

    catch (\Exception $e) {
      $this->output()->write($e->getMessage());
      return ResultData::EXITCODE_ERROR;
    }
    catch (\Throwable $t) {
      $this->output()->write($t->getMessage());
      return ResultData::EXITCODE_ERROR;
    }

    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testSolrDiagnose( string $site_name, string $env = 'dev' ) {
    try {
      // Run a diagnose command to make sure everything is okay.
      $this->output()->write('Running search-api-pantheon:diagnose...', true);
      $diagnose = $this->taskExec( static::$TERMINUS_EXE )
        ->args(
          'drush',
          "$site_name.$env",
          '--',
          'search-api-pantheon:diagnose'
        )
        ->run();

      if ( $diagnose instanceof Result && ! $diagnose->wasSuccessful() ) {
        \Kint::dump( $diagnose );
        throw new \Exception( 'An error occurred while running Solr search diagnostics.' );
      }
    }
    catch (\Exception $e) {
      $this->output()->write($e->getMessage());
      return ResultData::EXITCODE_ERROR;
    }
    return ResultData::EXITCODE_OK;
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testEnableRedis(string $site_name, string $env = 'dev') {
    $this->taskExec(static::$TERMINUS_EXE)
      ->args('redis:enable', $site_name)
      ->run();
    $this->waitForWorkflow($site_name);
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function demoLoginBrowser(string $site_name, string $env = 'dev') {
    exec(
          'terminus drush ' . $site_name . '.' . $env . ' -- uli admin',
          $finished,
          $status
      );
    $finished = trim(join('', $finished));
    $this->output()->writeln($finished);
    $this->_exec('open ' . $finished);
  }

  /**
   * @param string $text
   */
  protected function say($text, string $step_id = 'narration') {
    $now = new \DateTime();

    $filename = 'narration-' .
            $now->diff($this->started)->format('%I-%S-%F') . '-' . $step_id . '.m4a';
    $this->output->writeln('/Users/Shared/' . $filename);
    return (new Process([
      '/usr/bin/say',
      '--voice=Daniel',
      "--output-file={$filename}",
      '--file-format=m4af',
      $text,
    ], '/Users/Shared'))
      ->enableOutput()
      ->setTty(TRUE);
  }

  /**
   * @param string $site_name
   *
   * @return mixed|null
   */
  protected function siteInfo(string $site_name) {
    try {
      exec(static::$TERMINUS_EXE . ' site:info --format=json ' . $site_name, $output, $status);
      if (!empty($output)) {
        $result = json_decode(join("", $output), TRUE, 512, JSON_THROW_ON_ERROR);
        return $result;
      }
    }
    catch (\Exception $e) {
    }
    catch (\Throwable $t) {
    }
    return NULL;
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testEnvSolr(string $site_name, string $env = 'dev') {
    $site_folder = $this->getSiteFolder($site_name);
    $pantheon_yml_contents = Yaml::parseFile($site_folder . '/pantheon.yml');
    $pantheon_yml_contents['search'] = ['version' => 8];
    $pantheon_yml_contents = Yaml::dump($pantheon_yml_contents);
    file_put_contents($site_folder . '/pantheon.yml', $pantheon_yml_contents);
    $this->output->writeln($pantheon_yml_contents);
  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testSolrIndexCreate(string $site_name, string $env = 'dev') {
      $result = $this->taskExec( static::$TERMINUS_EXE )
        ->args(
          'drush',
          "$site_name.$env",
          '--',
          'cim',
          '--partial',
          '--source=modules/composer/search_api_pantheon/.ci/config',
          '-y'
        )
        ->run();
      if (!$result->wasSuccessful()) {
        exit(1);
      }

      // Index new solr.
      $result = $this->taskExec( static::$TERMINUS_EXE )
        ->args(
          'drush',
          "$site_name.$env",
          '--',
          'sapi-i'
        )
        ->run();
      if (!$result->wasSuccessful()) {
        exit(1);
      }

  }

  /**
   * @param string $site_name
   * @param string $env
   */
  public function testSolrSelect(string $site_name, string $env = 'dev') {
      $sapi_s = new Process([
          static::$TERMINUS_EXE,
          'drush',
          "$site_name.$env",
          '--',
          'sapi-s',
          '--field=Indexed',
      ]);
      $total_indexed = 0;
      $sapi_s->run();
      if ($sapi_s->isSuccessful()) {
        $result = $sapi_s->getOutput();
        $result_parts = explode("\n", $result);
        foreach ($result_parts as $part) {
          if (is_numeric(trim($part))) {
            $total_indexed = trim($part);
          }
        }
      }

      $saps = new Process([
        static::$TERMINUS_EXE,
        'drush',
        "$site_name.$env",
        '--',
        'saps',
        '*:*'
      ]);
      $num_found = 0;
      $saps->run();
      if ($saps->isSuccessful()) {
        $result = $saps->getOutput();
        $result_parts = explode("\n", $result);
        foreach ($result_parts as $part) {
          if (strpos($part, 'numFound') !== FALSE) {
            $num_found = trim(str_replace('"numFound":', '', $part), ' ,');
            break;
          }
        }
      }

      if ($total_indexed && $num_found && $total_indexed != $num_found) {
        $this->output->writeln('Solr indexing error. Total indexed: ' . $total_indexed . ' but found: ' . $num_found);
        exit(1);
      }
  }

}
