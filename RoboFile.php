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
use Symfony\Component\Finder\Finder;

define('STATUS_JQ_FILTER', 'jq -r \'.pantheon_search.status\'');

/**
 * RoboFile is the main entry point for Robo commands.
 */
class RoboFile extends Tasks {

  /**
   * @var string
   *   The terminus executable path.
   */
  public static $TERMINUS_EXE = '/usr/local/bin/terminus';

  /**
   * @var \DateTime
   *   When this run started.
   */
  public DateTime $started;

  public array $search_index;
  public array $search_server;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->started = new DateTime();
    require_once 'vendor/autoload.php';
    $this->getInstallConfigs();
  }

  public function test() {
    $this->output()->writeln('RoboFile constructor: ' . $this->started->format('Y-m-d H:i:s'));
    $this->output()->writeln('Search index: ' . print_r($this->search_index, TRUE));
    $this->output()->writeln('Search server: ' . print_r($this->search_server, TRUE));
    $this->output()->writeln('Hello world!');
  }

  public function getInstallConfigs(): void {
    $finder = new Finder();
    try {
      $finder->files()
        ->in('./config/install')
        ->name(['*.yml', '*.yaml'])
        ->sortByName();
      if (!$finder->hasResults()) {
        throw new \RuntimeException(
          'No YAML files found in the specified directory.'
        );
      }

      foreach ($finder as $file) {
        $filePath = $file->getRealPath();
        $fileName = $file->getBasename('.yml');

        // Remove .yaml extension if present
        $fileName = str_replace('.yaml', '', $fileName);

        try {
          switch (substr($fileName, 0, 16)) {
            case 'search_api.index':
              $this->search_index = Yaml::parseFile($filePath);
              break;
            case 'search_api.serve':
              $this->search_server = Yaml::parseFile($filePath);
              break;
            default:
              break;
          }
        }
        catch (\Exception $e) {
          throw new \RuntimeException(
            sprintf(
              'Error parsing YAML file %s: %s',
              $filePath,
              $e->getMessage()
            )
          );
        }
      }
    }
    catch (\Exception $e) {
      throw new \RuntimeException(
        sprintf(
          'Error accessing directory %s: %s',
          $directoryPath,
          $e->getMessage()
        )
      );
    }
  }

  /**
   * Delete sites created in this test run.
   */
  public function testDeleteSites() {
    $home = $_SERVER['HOME'];
    $file_contents = file_get_contents("$home/.robo-sites-created");
    $filenames = explode("\n", $file_contents);
    foreach ($filenames as $site_name) {
      if ($site_name) {
        $this->output()->writeln("Deleting site $site_name.");
        $this->taskExec(static::$TERMINUS_EXE)
          ->args('site:delete', '-y', $site_name)
          ->run();
      }
    }
  }

  /**
   * Run the full test suite for this project.
   */
  public function testFull(int $drupal_version = 10, string $site_name = NULL) {
    // Get the right constraint based on current branch/tag.
    $constraint = $this->getCurrentConstraint();
    // Ensure terminus 3 is installed.
    $this->testCheckT3();

    // This is a GitHub secret.
    $options = isset($_SERVER['TERMINUS_ORG']) ? ['org' => $_SERVER['TERMINUS_ORG']] : [];

    // Prepare the site name if not already set.
    if (empty($site_name)) {
      $site_name = substr(\uniqid('test-'), 0, 12);
      if ($_SERVER['GITHUB_RUN_NUMBER']) {
        // Ensure that 2 almost parallel runs do not collide.
        $site_name .= '-' . $drupal_version . '-' . $_SERVER['GITHUB_RUN_NUMBER'];
      }
    }
    $options['drupal_version'] = $drupal_version;

    // Create site, set connection mode to git and clone it to local.
    $this->testCreateSite($site_name, $options);
    $this->testConnectionGit($site_name, 'dev', 'git');
    $this->testCloneSite($site_name);
    $this->testAllowPlugins($site_name, $drupal_version);
    $this->testPhpVersion($site_name, $drupal_version);

    // Composer require the corresponding modules, push to Pantheon and install the site.
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

    // Enable Solr for this site and set solr version to 8, then try enabling the module again.
    $this->setSiteSearch($site_name, 'enable');
    $this->testEnvSolr($site_name);
    $this->testGitPush($site_name, 'Changes to pantheon.yml file.');
    // This should succeed now that Solr has been enabled.
    $this->testModuleEnable($site_name);

    // Test all the Solr things.
    $this->testSolrEnabled($site_name);
    // Test select query.
    $this->testSolrSelect($site_name, 'dev');

    // Finally, run Solr diagnose.
    $this->testSolrDiagnose($site_name, 'dev');

    $this->output()->write('All done! 🎉');
    return ResultData::EXITCODE_OK;
  }

  /**
   * Get current composer constraint depending on whether we're on a tag, a
   * branch or a PR.
   */
  protected function getCurrentConstraint(): string {
    $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
    if ($branch !== 'HEAD') {
      return "{$branch}-dev";
    }
    else {
      $tag = trim(
        shell_exec(
          'git describe --exact-match --tags $(git log -n1 --pretty=\'%h\')'
        )
      );
      if ($tag) {
        return $tag;
      }
      else {
        // Maybe we are on a PR.
        $branch = $_SERVER['GITHUB_HEAD_REF'];
        $branch_parts = explode('/', $branch);
        $branch = end($branch_parts);
        if ($branch) {
          return "{$branch}-dev";
        }
      }
    }
    // Final fallback, return "^8";
    return '^8';
  }

  /**
   * Ensure terminus 3 is installed, otherwise offer installing it using
   * Homebrew.
   */
  public function testCheckT3() {
    if (!file_exists(static::$TERMINUS_EXE) || !is_executable(
        static::$TERMINUS_EXE
      )) {
      $this->confirm(
        'This demo makes extensive use of the Terminus 3 phar. Can I install it for you using homebrew?'
      );
      $result = $this->taskExec(
        'brew install pantheon-systems/external/terminus'
      )->run();
      if (!$result->wasSuccessful()) {
        exit(ResultData::EXITCODE_ERROR);
      }
      // @todo check for build tools plugin "wait" command.
    }
    return ResultData::EXITCODE_OK;
  }

  /**
   * Create site in Pantheon if it doesn't exist. Return site info.
   *
   * @param string $site_name
   *   The machine name of the site to create.
   *
   * @return \Robo\Result
   */
  public function testCreateSite(
    string $site_name,
    array $options = ['org' => NULL]
  ) {
    $site_info = $this->siteInfo($site_name);
    if (empty($site_info)) {
      $home = $_SERVER['HOME'];
      $toReturn = $this->taskExec(static::$TERMINUS_EXE)
        ->args(
          'site:create',
          $site_name,
          $site_name,
          sprintf('drupal-%d-composer-managed', $options['drupal_version'])
        );
      if (!empty($options['org'])) {
        $toReturn->option('org', $options['org']);
      }
      $toReturn->run();
      $this->waitForWorkflow($site_name);
      $site_info = $this->siteInfo($site_name);
      // Write to $HOME/.robo-sites-created to delete them later.
      exec("echo $site_name >> $home/.robo-sites-created");
    }
    return $site_info;
  }

  /**
   * Wait for the given workflow to finish.
   *
   * @param string $site_name
   */
  public function waitForWorkflow(string $site_name, string $env = 'dev') {
    $this->output()->write('Checking workflow status', TRUE);

    exec(
      "terminus workflow:info:status $site_name.$env",
      $info
    );

    $info = $this->cleanUpInfo($info);
    $this->output()->write($info['workflow'], TRUE);

    // Wait for workflow to finish only if it hasn't already. This prevents the workflow:wait command from unnecessarily running for 260 seconds when there's no workflow in progress.
    if ($info['status'] !== 'succeeded') {
      $this->output()->write('Waiting for platform', TRUE);
      exec(
        "terminus build:workflow:wait --max=260 $site_name.$env",
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
   * Takes the output from a workflow:info:status command and converts it into
   * a human-readable and easily parseable array.
   *
   * @param array $info
   *   Raw output from 'terminus workflow:info:status'
   *
   * @return array An array of workflow status info.
   */
  private function cleanUpInfo(array $info): array {
    // Clean up the workflow status data and assign values to an array so it's easier to check.
    foreach ($info as $line => $value) {
      $ln = array_values(array_filter(explode("  ", trim($value))));

      // Skip lines with only one value. This filters out the ASCII dividers output by the command.
      if (count($ln) > 1) {
        if (in_array($ln[0], ['Started At', 'Finished At'])) {
          $ln[0] = trim(str_replace('At', '', $ln[0]));
          // Convert times to unix timestamps for easier use later.
          $ln[1] = strtotime($ln[1]);
        }

        $info[str_replace(' ', '-', strtolower($ln[0]))] = trim($ln[1]);
      }

      // Remove the processed line.
      unset($info[$line]);
    }

    return $info;
  }

  /**
   * Run "terminus solr:enable" or "terminus solr:disable" on the given site.
   *
   * @param string $site_name
   *   The machine name of the site to enable/disable Solr on.
   * @param string $value
   *   The value to pass to the command.
   */
  public function setSiteSearch(string $site_name, $value = 'enable') {
    $this->taskExec(static::$TERMINUS_EXE)
      ->args('solr:' . $value, $site_name)
      ->run();
  }

  /**
   * Set environment connection mode to git or sftp.
   *
   * @param string $site_name
   *   The machine name of the site to set the connection mode.
   * @param string $env
   *   The environment to set the connection mode.
   * @param string $connection
   *   The connection mode to set (git/sftp).
   */
  public function testConnectionGit(
    string $site_name,
    string $env = 'dev',
    string $connection = 'git'
  ) {
    $this->taskExec('terminus')
      ->args('connection:set', $site_name . '.' . $env, $connection)
      ->run();
  }

  /**
   * Use terminus local:clone to get a copy of the remote site.
   *
   * @param string $site_name
   *   The machine name of the site to clone.
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
   * Add allow plugins section to composer.
   *
   * @param string $site_name
   *   The machine name of the site to add the allow plugins section to.
   * @param int $drupal_version
   *   The major version of Drupal to use.
   */
  public function testAllowPlugins(string $site_name, int $drupal_version) {
    $plugins = [
      'drupal/core-project-message',
    ];
    if ($drupal_version === 10) {
      $plugins[] = 'phpstan/extension-installer';
      // @todo Remove once all of the modules have been correctly upgraded.
      $plugins[] = 'mglaman/composer-drupal-lenient';
    }
    if (count($plugins)) {
      $site_folder = $this->getSiteFolder($site_name);
      chdir($site_folder);

      foreach ($plugins as $plugin_name) {
        $this->taskExec('composer')
          ->args(
            'config',
            '--no-interaction',
            'allow-plugins.' . $plugin_name,
            'true'
          )
          ->run();
      }
    }
  }

  /**
   * Composer require the Solr related modules.
   *
   * @param string $site_name
   *   The machine name of the site to require the Solr modules.
   * @param string $constraint
   *   The constraint to use for the search_api_pantheon module.
   */
  public function testRequireSolr(
    string $site_name,
    string $constraint = '^8'
  ) {
    $site_folder = $this->getSiteFolder($site_name);
    chdir($site_folder);
    // Always test again latest version of search_api_solr.
    $this->taskExec('composer')
      ->args(
        'require',
        'drupal/search_api_solr:dev-4.x',
      )
      ->run();
    $this->taskExec('composer')
      ->args(
        'require',
        'pantheon-systems/search_api_pantheon ' . $constraint,
      )
      ->run();
    return ResultData::EXITCODE_OK;
  }

  /**
   * Return folder in local machine for given site name.
   *
   * @param string $site_name
   *   The machine name of the site to get the folder for.
   *
   * @return string
   *   Full path to the site folder.
   */
  protected function getSiteFolder(string $site_name) {
    return $_SERVER['HOME'] . '/pantheon-local-copies/' . $site_name;
  }

  /**
   * Add all changes to the git repository, commit and push.
   *
   * @param string $site_name
   *   The machine name of the site to commit and push.
   * @param string $commit_msg
   *   The commit message to use.
   */
  public function testGitPush(
    string $site_name,
    string $commit_msg = 'Changes committed from demo script.'
  ) {
    $site_folder = $this->getSiteFolder($site_name);
    chdir($site_folder);
    try {
      $git = new Git();
      $repo = $git->open($site_folder);
      if ($repo->hasChanges()) {
        $repo->addAllChanges();
        $repo->commit($commit_msg);
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
   * Install the Drupal site in Pantheon.
   *
   * @param string $site_name
   *   The machine name of the site to install.
   * @param string $env
   *   The environment to install the site in.
   * @param string $profile
   *   The Drupal profile to use during site installation.
   */
  public function testSiteInstall(
    string $site_name,
    string $env = 'dev',
    string $profile = 'demo_umami'
  ) {
    $this->taskExec(static::$TERMINUS_EXE)
      ->args(
        'drush',
        $site_name . '.' . $env,
        '--',
        'site:install',
        $profile,
        '-y'
      )
      ->options([
        'account-name' => 'admin',
        'site-name'    => $site_name,
        'locale'       => 'en',
      ])
      ->run();
    $this->waitForWorkflow($site_name);
    return ResultData::EXITCODE_OK;
  }

  /**
   * Enable solr modules in given Pantheon site.
   *
   * @param string $site_name
   *   The machine name of the site to enable solr modules.
   * @param string $env
   *   The environment to enable the modules in.
   */
  public function testModuleEnable(string $site_name, string $env = 'dev') {
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
        'search_api_solr_admin'
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
   * Run through various diagnostics to ensure that Solr8 is enabled and
   * working and an index has been created.
   *
   * @param string $site_name
   *   The machine name of the site to run the diagnostics on.
   * @param string $env
   *   The environment to run the diagnostics on.
   */
  public function testSolrEnabled(string $site_name, string $env = 'dev') {
    try {
      // Attempt to ping the Pantheon Solr server.
      $this->output()->write('Attempting to ping the Solr server...', TRUE);
      $ping = $this->taskExec(static::$TERMINUS_EXE)
        ->args(
          'drush',
          "$site_name.$env",
          '--',
          'search-api-pantheon:ping'
        )
        ->run();

      if ($ping instanceof Result && !$ping->wasSuccessful()) {
        \Kint::dump($ping);
        throw new \Exception(
          'An error occurred attempting to ping Solr server'
        );
      }

      // Check that Solr8 is enabled.
      $this->output()->write('Checking for Solr8 search API server...', TRUE);
      exec(
        "terminus remote:drush $site_name.$env -- search-api-server-list --format=json | " . STATUS_JQ_FILTER,
        $server_list,
      );
      if (empty($server_list)) {
        \Kint::dump($server_list);
        throw new \Exception(
          'No Servers Available. The default server was not imported when the module was enabled.'
        );
      }
      if (stripos($server_list, 'enabled') === FALSE) {
        \Kint::dump($server_list);
        throw new \Exception(
          'An error occurred checking that Solr8 was enabled: ' . print_r(
            $server_list,
            TRUE
          )
        );
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

    $this->output()->write('👍👍👍 Solr8 is enabled and working.', TRUE);
    return ResultData::EXITCODE_OK;
  }

  /**
   * Run drush search-api-pantheon:diagnose to complete the Solr8 diagnostic.
   *
   * @param string $site_name
   *   The machine name of the site to run the diagnostics on.
   * @param string $env
   *   The environment to run the diagnostics on.
   */
  public function testSolrDiagnose(string $site_name, string $env = 'dev') {
    try {
      // Run a diagnose command to make sure everything is okay.
      $this->output()->write('Running search-api-pantheon:diagnose...', TRUE);
      $diagnose = $this->taskExec(static::$TERMINUS_EXE)
        ->args(
        'drush',
        "$site_name.$env",
        '--',
        'search-api-pantheon:diagnose',
        '-v'
        )
        ->run();

      if ($diagnose instanceof Result && !$diagnose->wasSuccessful()) {
        \Kint::dump($diagnose);
        throw new \Exception(
          'An error occurred while running Solr search diagnostics.'
        );
      }
    }
    catch (\Exception $e) {
      $this->output()->write($e->getMessage());
      return ResultData::EXITCODE_ERROR;
    }
    return ResultData::EXITCODE_OK;
  }

  /**
   * Helper function to demo login in to the Drupal site using a login link.
   *
   * @param string $site_name
   *   The machine name of the site to login to.
   * @param string $env
   *   The environment to login to.
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
   * Get information about the given site.
   *
   * @param string $site_name
   *   The machine name of the site to get information about.
   *
   * @return mixed|null
   */
  protected function siteInfo(string $site_name) {
    try {
      exec(
      static::$TERMINUS_EXE . ' site:info --format=json ' . $site_name,
      $output,
      $status
      );
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
   * Set correct PHP version for the given site.
   *
   * @param string $site_name
   *   The machine name of the site to set the Solr version for.
   */
  public function testPhpVersion(string $site_name, int $drupal_version) {
    $site_folder = $this->getSiteFolder($site_name);
    $pantheon_yml_contents = Yaml::parseFile($site_folder . '/pantheon.yml');
    if ($drupal_version === 10) {
      $pantheon_yml_contents['php_version'] = 8.2;
    }
    else {
      $pantheon_yml_contents['php_version'] = 8.3;
    }
    $pantheon_yml_contents = Yaml::dump($pantheon_yml_contents);
    file_put_contents($site_folder . '/pantheon.yml', $pantheon_yml_contents);
    $this->output->writeln($pantheon_yml_contents);
  }

  /**
   * Set correct Solr version for the given site.
   *
   * @param string $site_name
   *   The machine name of the site to set the Solr version for.
   */
  public function testEnvSolr(string $site_name) {
    $site_folder = $this->getSiteFolder($site_name);
    $pantheon_yml_contents = Yaml::parseFile($site_folder . '/pantheon.yml');
    $pantheon_yml_contents['search'] = ['version' => 8];
    $pantheon_yml_contents = Yaml::dump($pantheon_yml_contents);
    file_put_contents($site_folder . '/pantheon.yml', $pantheon_yml_contents);
    $this->output->writeln($pantheon_yml_contents);
  }

  /**
   * Create a Solr index based on the configuration under .ci/config.
   *
   * @param string $site_name
   *   The machine name of the site to create the index in.
   * @param string $env
   *   The environment to create the index in.
   */
  public function testSolrIndexCreate(string $site_name, string $env = 'dev') {


    // Index new solr.
    $result = $this->taskExec(static::$TERMINUS_EXE)
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
   * Use search-api-pantheon:select command to ensure both Drupal index and the
   * actual Solr index have the same amount of items.
   *
   * @param string $site_name
   *   The machine name of the site to run the tests on.
   * @param string $env
   *   The environment to run the tests on.
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
    '*:*',
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
      $this->output->writeln(
      'Solr indexing error. Total indexed: ' . $total_indexed . ' but found: ' . $num_found
      );
      exit(1);
    }
  }

}
