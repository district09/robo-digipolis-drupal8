<?php

namespace DigipolisGent\Robo\Drupal8;

use Consolidation\AnnotatedCommand\CommandError;
use DigipolisGent\Robo\Helpers\AbstractRoboFile;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use RandomLib\Factory;
use Robo\Result;
use SecurityLib\Strength;

class RoboFileBase extends AbstractRoboFile
{
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \DigipolisGent\Robo\Task\Package\Drupal8\loadTasks;
    use \DigipolisGent\Robo\Task\CodeValidation\loadTasks;

    /**
     * File backup subdirs.
     *
     * @var string[]
     */
    protected $fileBackupSubDirs = ['public', 'private'];

    /**
     * Files or directories to exclude from the backup.
     *
     * @var string[]
     */
    protected $excludeFromBackup = ['php', 'js/*', 'css/*', 'styles/*'];

    protected $siteInstalled = null;

    protected $siteInstalledTested;

    public function setSiteInstalled($installed, $uri = false)
    {
        if (!$uri) {
            $this->siteInstalled = $installed;
            $this->siteInstalledTested = false;
            return;
        }
        if (is_null($this->siteInstalled)) {
            $this->siteInstalled = [];
            $this->siteInstalledTested = [];
        }
        $this->siteInstalled[$uri] = $installed;
        $this->siteInstalledTested[$uri] = false;
    }

    protected function isSiteInstalled($worker, AbstractAuth $auth, $remote)
    {
        if (!is_null($this->siteInstalled) && !$remote['aliases']) {
            return $this->siteInstalled;
        }
        if ($remote['aliases'] && is_array($this->siteInstalled) && count($this->siteInstalled) >= 1) {
            // A site is installed if every single alias is installed.
            return count(array_filter($this->siteInstalled)) === count($this->siteInstalled);
        }
        $aliases = $remote['aliases'] ?: [0 => false];
        foreach ($aliases as $uri => $alias) {
            $currentWebRoot = $remote['currentdir'];
            $count = 0;
            $result = $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->exec('../vendor/bin/drush ' . ($alias ? '--uri=' . escapeshellarg($uri) : '') . ' sql-query "SHOW TABLES" | wc --lines', function ($output) use (&$count) {
                    $count = (int) $output;
                })
                ->timeout(300)
                ->run();
            $this->setSiteInstalled($result->wasSuccessful() && $count > 10, $uri);
            if ($alias === false) {
                $this->siteInstalledTested = true;
            }
            else {
                $this->siteInstalledTested[$alias] = true;
            }
        }

        return $remote['aliases'] ? count(array_filter($this->siteInstalled)) === count($this->siteInstalled) : $this->siteInstalled;
    }

    public function digipolisValidateCode()
    {
        $local = $this->getLocalSettings();
        $phpmdExtensions = [
            'php',
            'module',
            'install',
            'profile',
            'theme',
        ];
        $phpcsExtensions = [
            'php',
            'module',
            'install',
            'profile',
            'theme',
            'js',
            'yml',
        ];
        // Directories and files to check.
        $directories = [
          $local['project_root'] . '/web/modules/custom',
          $local['project_root'] . '/web/profiles/custom',
          $local['project_root'] . '/web/themes/custom',
        ];

        // Check if directories exist.
        $checks = [];
        foreach ($directories as $dir) {
          if (!file_exists($dir)) {
            continue;
          }

          $checks[] = $dir;
        }
        if (!$checks) {
          $this->say('! No custom directories to run checks on.');
          return;
        }
        $phpcs = $this
            ->taskPhpCs(
                implode(' ', $checks),
                $local['project_root'] . '/vendor/drupal/coder/coder_sniffer/Drupal',
                $phpcsExtensions
            )
            ->ignore([
                'libraries',
                'node_modules',
                'Gruntfile.js',
                '*.md',
                '*.min.js',
                '*.css'
            ])
            ->reportType('checkstyle')
            ->reportFile('validation/phpcs.checkstyle.xml')
            ->failOnViolations(false);
        $phpmd = $this->taskPhpMd(
            implode(',', $checks),
            'xml',
            $phpmdExtensions
        )
        ->reportFile('validation/phpmd.xml')
        ->failOnViolations(false);
        $collection = $this->collectionBuilder();
        $collection->addTask($phpmd);
        $collection->addTask($phpcs);
        return $collection;
    }

    protected function preRestoreBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $currentWebRoot = $remote['currentdir'];
        $collection = $this->collectionBuilder();
        $parent = parent::preRestoreBackupTask($worker, $auth, $remote, $opts);
        if ($parent) {
            $collection->addTask($parent);
        }

        if ($opts['data']) {
            $aliases = $remote['aliases'] ?: [0 => false];
            foreach($aliases as $uri => $alias) {
              $collection
                  ->taskSsh($worker, $auth)
                      ->remoteDirectory($currentWebRoot, true)
                      ->timeout(60)
                      ->exec('../vendor/bin/drush ' . ($alias ? '--uri=' . escapeshellarg($uri) : '') . ' sql-drop -y');
            }

        }
        return $collection;
    }

    protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $aliases = $remote['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        foreach ($aliases as $uri => $alias) {
            $install = 'vendor/bin/robo digipolis:install-drupal8 '
                  . escapeshellarg($extra['profile'])
                  . ' --site-name=' . escapeshellarg($extra['site-name'])
                  . ($force ? ' --force' : '' )
                  . ($extra['config-import'] ? ' --config-import' : '')
                  . ($extra['existing-config'] ? ' --existing-config' : '')
                  . ($alias ? ' --uri=' . escapeshellarg($uri) : '');

            if (!$force) {
                if (!$alias && $this->siteInstalledTested) {
                    $install = '[[ $(vendor/bin/drush sql-query "SHOW TABLES" | wc --lines) -gt 10 ]] || ' . $install;
                }
                elseif ($alias && is_array($this->siteInstalledTested) && isset($this->siteInstalledTested[$alias]) && $this->siteInstalledTested[$alias]) {
                    $install = '[[ $(vendor/bin/drush --uri=' . escapeshellarg($uri) . ' sql-query "SHOW TABLES" | wc --lines) -gt 10 ]] || ' . $install;
                }
            }

            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Install can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->verbose($extra['ssh-verbose'])
                ->exec($install);
        }
        return $collection;
    }

    protected function updateTask($worker, AbstractAuth $auth, $remote, $extra = [])
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $update = 'vendor/bin/robo digipolis:update-drupal8';
        $update .= $extra['config-import'] ? ' --config-import' : '';
        $aliases = $remote['aliases'] ?: [0 => false];

        foreach ($aliases as $uri => $alias) {
            $aliasUpdate = $update . ($alias ? ' --uri=' . escapeshellarg($uri) : '');
            $collection = $this->collectionBuilder();
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    // Updates can take a long time. Let's set it to 15 minutes.
                    ->timeout(900)
                    ->verbose($extra['ssh-verbose'])
                    ->exec($aliasUpdate);
        }
        return $collection;
    }

    protected function clearCacheTask($worker, $auth, $remote)
    {
        $currentWebRoot = $remote['currentdir'];
        $aliases = $remote['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        foreach ($aliases as $uri => $alias) {
            $drushUri = $alias ? escapeshellarg($uri) : '';
            $drushUriParam = $alias ? '--uri=' . $drushUri : '';
            $drushCommand = '../vendor/bin/drush ' . $drushUriParam;
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                ->exec($drushCommand . ' cr')
                ->exec($drushCommand . ' cc drush');

            $purge = $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                // Check if the drush_purge module is enabled and if an 'everything'
                // purger is configured.
                ->exec($this->checkModuleCommand('purge_drush', $remote, $drushUri) . ' && cd -P ' . $currentWebRoot . ' && ' . $drushCommand . ' ptyp | grep everything')
                ->run()
                ->wasSuccessful();

            if ($purge) {
                $collection->exec($drushCommand . ' pinv everything -y');
            }
        }
        return $collection;
    }

    protected function buildTask($archivename = null)
    {
        $this->readProperties();
        $archive = is_null($archivename) ? $this->time . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskThemesCompileDrupal8()
            ->taskThemesCleanDrupal8()
            ->taskPackageDrupal8($archive);
        return $collection;
    }

    /**
     * Build a Drupal 8 site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory to deploy to.
     * @option site-name The Drupal site name in case we need to install it.
     * @option profile The machine name of the profile we need to use when
     *   installing.
     * @options force-install Force a new isntallation of the Drupal8 site. This
     *   will drop all tables in the current database.
     * @option config-import Import configuration after updating the site.
     * @option existing-config Import configuration when installing the site.
     * @option worker The IP of the worker server. Defaults to the first server
     *   given in the arguments.
     *
     * @usage --app=myapp 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisDeployDrupal8(
        array $arguments,
        $opts = [
            'app' => 'default',
            'site-name' => 'Drupal',
            'profile' => 'standard',
            'force-install' => false,
            'config-import' => false,
            'existing-config' => false,
            'worker' => null,
            'ssh-verbose' => false,
        ]
    ) {
        return $this->deployTask($arguments, $opts);
    }

    /**
     * Build a Drupal 8 site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @usage test.tar.gz
     */
    public function digipolisBuildDrupal8($archivename = null)
    {
        return $this->buildTask($archivename);
    }

    /**
     * Install or update a drupal 8 remote site.
     *
     * @param string $server
     *   The server to install the site on.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $privateKeyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory in which the drupal site can be found.
     * @option site-name The Drupal site name in case we need to install it.
     * @option profile The machine name of the profile we need to use when
     *   installing.
     * @options force-install Force a new isntallation of the Drupal8 site. This
     *   will drop all tables in the current database.
     * @option config-import Import configuration after updating the site.
     * @option existing-config Install the site from existing configuration.
     *
     * @usage --app=myapp --profile=myprofile --site-name='My D8 Site' 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisInitDrupal8Remote(
        $server,
        $user,
        $privateKeyFile,
        $opts = [
            'app' => 'default',
            'site-name' => 'Drupal',
            'profile' => 'standard',
            'force-install' => false,
            'config-import' => false,
            'existing-config' => false,
        ]
    ) {
        $remote = $this->getRemoteSettings($server, $user, $privateKeyFile, $opts['app']);
        $auth = new KeyFile($user, $privateKeyFile);
        return $this->initRemoteTask($privateKeyFile, $auth, $remote, $opts, $opts['force-install']);
    }

    /**
     * Executes D8 database updates of the D8 site in the current folder.
     *
     * Executes D8 database updates of the D8 site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     */
    public function digipolisUpdateDrupal8($opts = ['config-import' => false, 'uri' => null])
    {
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $drushUri = $opts['uri'] ? escapeshellarg($opts['uri']) : '';
        $drushUriParam = $opts['uri'] ? '--uri=' . $drushUri : '';

        $collection
            ->taskExecStack()
            ->exec('cd -P $(ls -vdr ' . $this->getConfig()->get('digipolis.root.project') .
                '/../* | head -n2 | tail -n1) && vendor/bin/drush sset system.maintenance_mode 1');

        $collection
            ->taskDrushStack('vendor/bin/drush')
            ->uri($drushUri)
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr')
            ->drush('cc drush')
            ->updateDb();

        if ($opts['config-import']) {
            $uuid = $this->getSiteUuid($opts['uri']);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cr')
                ->drush('cc drush')
                ->drush('cim');

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(vendor/bin/drush ' . $drushUriParam . ' -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
                ->exec($this->varnishCheckCommand($drushUri));

            $collection->taskDrushStack('vendor/bin/drush')
                ->uri($drushUri)
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('cr')
            ->drush('cc drush');

        $locale = $this->taskExecStack()
            ->dir($this->getConfig()->get('digipolis.root.project'))
            ->exec($this->checkModuleCommand('locale', null, $drushUris))
            ->run()
            ->wasSuccessful();

        $collection->taskDrushStack('vendor/bin/drush')
          ->uri($drushUri)
          ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));

        if ($locale) {
            $collection
                ->drush('locale-check')
                ->drush('locale-update');
        }

        $collection
            ->drush('cr')
            ->drush('cc drush')
            ->drush('sset system.maintenance_mode 0');

        return $collection;
    }

    /**
     * Install the D8 site in the current folder.
     *
     * @param string $profile
     *   The name of the install profile to use.
     * @param array $opts
     *   The options for this command.
     *
     * @option site-name The site name to set during install.
     * @option force Force the installation. This will drop all tables in the
     *   current database.
     * @option config-import Import configuration after installing the site.
     * @option existing-config Install the site from existing configuration.
     */
    public function digipolisInstallDrupal8(
        $profile = 'standard',
        $opts = [
            'site-name' => 'Drupal',
            'force' => false,
            'config-import' => false,
            'existing-config' => false,
            'account-name' => 'admin',
            'account-mail' => 'admin@example.com',
            'account-pass' => null,
            'uri' => null,
        ]
    ) {
        $this->readProperties();
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $uri = $opts['uri'] ?: '';
        $drushUri = $uri ? escapeshellarg($uri) : '';
        $drushUriParam = $uri ? '--uri=' . $drushUri : '';
        $subfolder = $uri ? $this->parseSiteAliases()[$uri] : 'default';
        $site_path = $app_root . '/sites/' . $subfolder;

        if (is_file($site_path . '/settings.php')) {
            chmod($site_path . '/settings.php', 0664);
            include $site_path . '/settings.php';
        }
        elseif (is_file($site_path . '/settings.local.php')) {
            chmod($site_path, 0775);
            include $site_path . '/settings.local.php';
        }
        else {
            return new CommandError('No settings file found.');
        }

        $config = $databases['default']['default'];

        // Random string fallback for the account password.
        if (empty($opts['account-pass'])) {
            $factory = new Factory();
            $opts['account-pass'] = $factory
                ->getGenerator(new Strength(Strength::MEDIUM))
                ->generateString(16);
        }

        $collection = $this->collectionBuilder();
        $collection->rollback(
            $this->taskDrushStack('vendor/bin/drush')
                ->uri($drushUri)
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
                ->drush('sql-drop')
        );
        $dbUrl = false;
        if ($config['driver'] === 'sqlite') {
            $dbUrl = $config['driver'] . '://' . $config['database'];
        }
        if (!$dbUrl) {
            $dbUrl = $config['driver'] . '://'
                . $config['username'] . ':' . $config['password']
                . '@' . $config['host']
                . (isset($config['port']) && !empty($config['port'])
                    ? ':' . $config['port']
                    : ''
                )
                . '/' . $config['database'];
        }
        $drushInstall = $collection->taskDrushStack('vendor/bin/drush')
            ->uri($drushUri)
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->dbUrl($dbUrl)
            ->siteName($opts['site-name'])
            ->accountName($opts['account-name'])
            ->accountMail($opts['account-mail'])
            ->accountPass('"' . $opts['account-pass'] . '"')
            ->existingConfig($opts['existing-config']);

        if (isset($config['username']) && !empty($config['username'])) {
            $drushInstall->dbSu($config['username']);
        }
        if (isset($config['password']) && !empty($config['password'])) {
            $drushInstall->dbSuPw($config['password']);
        }

        if (!empty($config['prefix'])) {
            $drushInstall->dbPrefix($config['prefix']);
        }

        if ($opts['force']) {
            // There is no force option for drush.
            // $collection->option('force');
        }
        $collection
            ->siteInstall($profile)
            ->drush('cc drush')
            ->drush('sset system.maintenance_mode 1')
            ->drush('cr');

        $collection->taskFilesystemStack()
            ->chmod($site_path . '/settings.php', 0444)
            ->chmod($site_path, 0555);

        $locale = $this->taskExecStack()
            ->dir($this->getConfig()->get('digipolis.root.project'))
            ->exec($this->checkModuleCommand('locale', null, $drushUri))
            ->run()
            ->wasSuccessful();

        if ($locale) {
            $collection->taskDrushStack('vendor/bin/drush')
                ->uri($drushUri)
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
                ->drush('locale-check')
                ->drush('locale-update');
        }

        if ($opts['config-import']) {
            $collection->taskDrushStack('vendor/bin/drush')
                ->uri($drushUri)
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
            $uuid = $this->getSiteUuid($uri);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cim');

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(vendor/bin/drush ' . $drushUriParam . ' -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
                ->exec($this->varnishCheckCommand($drushUri));
        }

        $collection->taskDrushStack('vendor/bin/drush')
          ->uri($drushUri)
          ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
          ->drush('sset system.maintenance_mode 0');

        return $collection;
    }

    protected function getSiteUuid($uri = false)
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            $this->say('Could not get site UUID. No webroot found.');
            return false;
        }

        $finder = new \Symfony\Component\Finder\Finder();
        $subdir = ($uri ? '/' . $this->parseSiteAliases()[$uri] : '');
        $this->say('Searching for settings.php in ' . $webDir . '/sites' . $subdir . ' and subdirectories.');
        $finder->in($webDir . '/sites' . $subdir)->files()->name('settings.php');
        $config_directories = [];
        foreach ($finder as $settingsFile) {
            $app_root = $webDir;
            $site_path = 'sites' . $subdir;
            $this->say('Loading settings from ' . $settingsFile->getRealpath() . '.');
            include $settingsFile->getRealpath();
            break;
        }
        if (!isset($config_directories['sync'])) {
            $this->say('Could not get site UUID. No sync directory set.');
            return false;
        }
        $sync = $webDir . '/' . $config_directories['sync'] . '/system.site.yml';
        $this->say('Parsing site UUID from ' . $sync . '.');
        $siteSettings = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($sync));
        return $siteSettings['uuid'];
    }

    /**
     * Get the command to check if the page_cache module and varnish are not
     * enabled simultaneously. Command differs for Drush 9 vs Drush 8.
     *
     * @return string
     */
    protected function varnishCheckCommand($drushUri = '')
    {
        $this->readProperties();

        $drushVersion = $this->taskDrushStack()
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        if ($drushUri) {
            $drushVersion->uri($drushUri);
        }
        $drushVersion->getVersion();
        if (version_compare($drushVersion, '9.0', '<')) {
            return 'bash -c "[[ '
                . '\'$ENABLED_MODULES\' =~ \((varnish|purge)\) '
                . '&& \'$ENABLED_MODULES\' =~ \(page_cache\)'
                . ' ]]" && exit 1 || :';
        }
        return 'bash -c "[[ '
            . '\'$ENABLED_MODULES\' =~ (varnish|purge) '
            . '&& \'$ENABLED_MODULES\' =~ page_cache'
            . ' ]]" && exit 1 || :';
    }

    /**
     * Get the command to check if the locale module is enabled. Command differs
     * for Drush 9 vs Drush 8.
     *
     * @return string
     */
    protected function checkModuleCommand($module, $remote = null, $drushUri = false)
    {
        $this->readProperties();

        $drushVersion = $this->taskDrushStack();
        $drushUriParam = '';
        if ($drushUri) {
          $drushVersion->uri($drushUri);
          $drushUriParam = '--uri=' . $drushUri;
        }
        $drushVersion = $drushVersion->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->getVersion();
        $webroot = $remote ? $remote['currentdir'] : $this->getConfig()->get('digipolis.root.web');
        $projectroot = $remote ? $remote['currentdir'] . '/..' : $this->getConfig()->get('digipolis.root.project');
        if (version_compare($drushVersion, '9.0', '<')) {
            return  'cd -P ' . $projectroot . ' && '
                . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' cr && '
                . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' cc drush && '
                . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' '
                . 'pml --fields=name --status=enabled --type=module --format=list '
                . '| grep "(' . $module . ')"';
        }

        return 'cd -P ' . $projectroot . ' && '
            . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' cr && '
            . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' cc drush && '
            . 'vendor/bin/drush ' . $drushUriParam . ' -r ' . $webroot . ' '
            . 'pml --fields=name --status=enabled --type=module --format=list '
            . '| grep "^' . $module . '$"';
    }

    /**
     * Sync the database and files between two Drupal 8 sites.
     *
     * @param string $sourceUser
     *   SSH user to connect to the source server.
     * @param string $sourceHost
     *   IP address of the source server.
     * @param string $sourceKeyFile
     *   Private key file to use to connect to the source server.
     * @param string $destinationUser
     *   SSH user to connect to the destination server.
     * @param string $destinationHost
     *   IP address of the destination server.
     * @param string $destinationKeyFile
     *   Private key file to use to connect to the destination server.
     * @param string $sourceApp
     *   The name of the source app we're syncing. Used to determine the
     *   directory to sync.
     * @param string $destinationApp
     *   The name of the destination app we're syncing. Used to determine the
     *   directory to sync to.
     */
    public function digipolisSyncDrupal8(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default',
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        return $this->syncTask(
            $sourceUser,
            $sourceHost,
            $sourceKeyFile,
            $destinationUser,
            $destinationHost,
            $destinationKeyFile,
            $sourceApp,
            $destinationApp,
            $opts
        );
    }

    /**
     * Create a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're creating the backup for.
     */
    public function digipolisBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = ['app' => 'default', 'files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);
        $auth = new KeyFile($user, $keyFile);
        return $this->backupTask($host, $auth, $remote, $opts);
    }

    /**
     * Restore a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're restoring the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisRestoreBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        $collection = $this->collectionBuilder();
        $collection->addTask($this->restoreBackupTask($host, $auth, $remote, $opts));
        $collection->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));

        $uuid = $this->getSiteUuid();
        if ($uuid) {
            $collection->drush('cset system.site uuid ' . $uuid);
        }
        $collection
            ->drush('cr')
            ->drush('cc drush')
            ->drush('cim')
            ->drush('cr')
            ->drush('cc drush');

        $collection->taskExecStack()
            ->exec('ENABLED_MODULES=$(vendor/bin/drush -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
            ->exec($this->varnishCheckCommand());

        return $collection;
    }

    /**
     * Download a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're downloading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisDownloadBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        return $this->downloadBackupTask($host, $auth, $remote, $opts);
    }

    /**
     * Upload a backup of files (sites/default/files) and database to a server.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're uploading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisUploadBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        return $this->uploadBackupTask($host, $auth, $remote, $opts);
    }

    /**
     * {@inheritdoc}
     */
    protected function backupTask($worker, AbstractAuth $auth, $remote, $opts = array())
    {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $this->getCurrentProjectRoot($worker, $auth, $remote);

        $collection = $this->collectionBuilder();
        $collection->taskSsh($worker, $auth)
            ->exec('mkdir -p ' . $backupDir);

        // Overwrite database backups to handle aliases.
        if ($opts['files'] === $opts['data']) {
            $parentOpts = ['files' => true, 'data' => false] + $opts;
            $collection->addTask(parent::backupTask($worker, $auth, $remote, $parentOpts));
        }
        if ($opts['data'] || $opts['files'] === $opts['data']) {
            $aliases = $remote['aliases'] ?: [ 0 => false];
            foreach ($aliases as $uri => $alias) {
                $dbBackupFile = $this->backupFileName(($alias ? '.' . $alias : '') . '.sql');
                $dbBackup = 'vendor/bin/robo digipolis:database-backup ' . ($alias ? escapeshellarg($alias) : '')
                    . ' --destination=' . $backupDir . '/' . $dbBackupFile;
                $collection->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout($this->getTimeoutSetting('backup_database'))
                    ->exec($dbBackup);
            }
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function restoreBackupTask($worker, AbstractAuth $auth, $remote, $opts = array())
    {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $this->getCurrentProjectRoot($worker, $auth, $remote);

        $collection = $this->collectionBuilder();

        // Overwrite database backups to handle aliases.
        if ($opts['files'] === $opts['data']) {
            $parentOpts = ['files' => true, 'data' => false] + $opts;
            $collection->addTask(parent::restoreBackupTask($worker, $auth, $remote, $parentOpts));
        }
        if ($opts['data'] || $opts['files'] === $opts['data']) {
            $aliases = $remote['aliases'] ?: [0 => false];
            foreach ($aliases as $uri => $alias) {
                $dbBackupFile =  $this->backupFileName(($alias ? '.' . $alias : '') . '.sql.gz', $remote['time']);
                $dbRestore = 'vendor/bin/robo digipolis:database-restore ' . ($alias ? escapeshellarg($alias) : '')
                    . '--source=' . $backupDir . '/' . $dbBackupFile;
                $collection
                    ->taskSsh($worker, $auth)
                        ->remoteDirectory($currentProjectRoot, true)
                        ->timeout($this->getTimeoutSetting('restore_db_backup'))
                        ->exec($dbRestore);
            }
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function digipolisSyncLocal(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $local = $this->getLocalSettings($opts['app']);
        $collection = parent::digipolisSyncLocal($host, $user, $keyFile, $opts);
        if ($opts['files']) {
            $collection->taskExecStack()
                ->exec('rm -rf ' . $local['filesdir'] . '/files')
                ->exec('mv ' . $local['filesdir'] . '/public ' . $local['filesdir'] . '/files')
                ->exec('mv ' . $local['filesdir'] . '/private ' . $local['filesdir'] . '/files/private');
        }
        return $collection;
    }

    protected function defaultDbConfig()
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            return false;
        }
        $this->readProperties();
        $settings = $this->getConfig()->get('remote');
        if (!isset($settings['aliases'])) {
            $settings['aliases'] = $this->parseSiteAliases();
        }
        $aliases = $settings['aliases'] ?: [0 =>false];

        foreach ($aliases as $uri => $alias) {
            $finder = new \Symfony\Component\Finder\Finder();
            $subdir = 'sites/' . ($alias ?: 'default');
            $finder->in($webDir . '/' . $subdir)->files()->name('settings.php');
            foreach ($finder as $settingsFile) {
                $app_root = $webDir;
                $site_path = $subdir;
                include $settingsFile->getRealpath();
                break;
            }
            if (!isset($databases['default']['default'])) {
                continue;
            }
            $config = $databases['default']['default'];
            $dbConfig[$alias ?: 'default'] = [
                'type' => $config['driver'],
                'host' => $config['host'],
                'port' => isset($config['port']) ? $config['port'] : '3306',
                'user' => $config['username'],
                'pass' => $config['password'],
                'database' => $config['database'],
                'structureTables' => [
                    'batch',
                    'cache',
                    'cache_*',
                    '*_cache',
                    '*_cache_*',
                    'flood',
                    'search_dataset',
                    'search_index',
                    'search_total',
                    'semaphore',
                    'sessions',
                    'watchdog',
                ],
            ];
        }
        return $dbConfig ?: false;
    }

    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $settings = parent::getRemoteSettings($host, $user, $keyFile, $app, $timestamp);
        // Allow having aliases defined in properties.yml. If non are set, try
        // parsing them from sites.php
        if (!isset($settings['aliases'])) {
            $settings['aliases'] = $this->parseSiteAliases();
        }
        return $settings;
    }

    protected function parseSiteAliases() {
        $sitesFile = $this->getConfig()->get('digipolis.root.web', false) . '/sites/sites.php';
        if (!file_exists($sitesFile)) {
           return false;
        }
        include $sitesFile;
        return isset($sites) && is_array($sites) ? $sites : false;
    }
}
