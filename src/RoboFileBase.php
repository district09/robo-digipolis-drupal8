<?php

namespace DigipolisGent\Robo\Drupal8;

use DigipolisGent\Robo\Helpers\AbstractRoboFile;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use RandomLib\Factory;
use SecurityLib\Strength;

class RoboFileBase extends AbstractRoboFile
{
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \DigipolisGent\Robo\Task\DrupalConsole\loadTasks;
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

    public function setSiteInstalled($installed)
    {
        $this->siteInstalled = $installed;
    }

    protected function isSiteInstalled($worker, AbstractAuth $auth, $remote)
    {
        if (!is_null($this->siteInstalled)) {
            return $this->siteInstalled;
        }
        $currentWebRoot = $remote['currentdir'];
        $self = $this;
        $result = $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentWebRoot, true)
            ->exec('../vendor/bin/drush sql-query "SHOW TABLES"', function ($output) use ($self) {
                $self->setSiteInstalled(count(explode("\n", $output)) > 1);
            })
            ->timeout(300)
            ->run();
        $this->setSiteInstalled($result->wasSuccessful() && $this->siteInstalled);
        return $this->siteInstalled;
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
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($currentWebRoot, true)
                    ->timeout(60)
                    // Drupal console needs to bootstrap Drupal to load its
                    // commands. If something goes wrong during bootstrap we
                    // can't drop the db. So we use drush for now.
                    // ->exec('vendor/bin/drupal database:drop -y');
                    ->exec('../vendor/bin/drush sql-drop -y');

        }
        return $collection;
    }

    protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $install = 'vendor/bin/robo digipolis:install-drupal8 '
              . escapeshellarg($extra['profile'])
              . ' --site-name=' . escapeshellarg($extra['site-name'])
              . ($force ? ' --force' : '' )
              . ($extra['config-import'] ? ' --config-import' : '');

        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            // Install can take a long time. Let's set it to 15 minutes.
            ->timeout(900)
            ->verbose($extra['ssh-verbose'])
            ->exec($install);
    }

    protected function updateTask($worker, AbstractAuth $auth, $remote, $extra = [])
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $update = 'vendor/bin/robo digipolis:update-drupal8';
        $update .= $extra['config-import'] ? ' --config-import' : '';
        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Updates can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->verbose($extra['ssh-verbose'])
                ->exec($update);
        return $collection;
    }

    protected function clearCacheTask($worker, $auth, $remote)
    {
        $currentWebRoot = $remote['currentdir'];
        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentWebRoot, true)
            ->timeout(120)
            ->exec('../vendor/bin/drush cr all')
            ->exec('../vendor/bin/drush cc drush');
    }

    protected function buildTask($archivename = null)
    {
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
    public function digipolisUpdateDrupal8($opts = ['config-import' => false])
    {
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $collection
            ->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('sset system.maintenance_mode 1');

        // When uninstalling modules inside update hook,
        // we get "dependency on a non-existent service" exception.
        // Therefore switch to drush for excecuting update hooks.
        $collection->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->updateDb();

        if ($opts['config-import']) {
            $uuid = $this->getSiteUuid();
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cim');

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(vendor/bin/drush -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
                ->exec('bash -c "[[ \'$ENABLED_MODULES\' =~ \((varnish|purge)\) && \'$ENABLED_MODULES\' =~ \(page_cache\) ]]" && exit 1 || :');

            $collection->taskDrushStack('vendor/bin/drush')
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('cr');

        $locale = $this->taskExecStack()
            ->dir($this->getConfig()->get('digipolis.root.project'))
            ->exec(
                'vendor/bin/drush '
                . '-r ' . $this->getConfig()->get('digipolis.root.web') . ' '
                . 'pml --core --fields=name --status=enabled --type=module --format=list '
                . '| grep "(locale)"'
            )->run()->wasSuccessful();

        if ($locale) {
            $collection
                ->drush('locale-check')
                ->drush('locale-update');
        }

        $collection
            ->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr all')
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
     */
    public function digipolisInstallDrupal8(
        $profile = 'standard',
        $opts = [
            'site-name' => 'Drupal',
            'force' => false,
            'config-import' => false,
            'account-name' => 'admin',
            'account-mail' => 'admin@example.com',
            'account-pass' => null
        ]
    ) {
        $this->readProperties();
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        $app_root = $webDir;
        $site_path = 'sites/default';
        include $webDir . '/sites/default/settings.php';
        $config = $databases['default']['default'];

        // Random string fallback for the account password.
        if (empty($opts['account-pass'])) {
            $factory = new Factory();
            $opts['account-pass'] = $factory
                ->getGenerator(new Strength(Strength::MEDIUM))
                ->generateString(16);
        }

        $collection = $this->collectionBuilder();
        $collection->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->dbUrl(
                $config['driver'] . '://'
                . $config['username'] . ':' . $config['password']
                . '@' . $config['host']
                . (isset($config['port']) && !empty($config['port'])
                    ? ':' . $config['port']
                    : ''
                )
                . '/' . $config['database']
            )
            ->dbSu($config['username'])
            ->dbSuPw($config['password'])
            ->dbPrefix($config['prefix'])
            ->siteName($opts['site-name'])
            ->accountName($opts['account-name'])
            ->accountMail($opts['account-mail'])
            ->accountPass('"' . $opts['account-pass'] . '"');
        if ($opts['force']) {
            // There is no force option for drush.
            // $collection->option('force');
        }
        $collection
            ->siteInstall($profile)
            ->drush('sset system.maintenance_mode 1')
            ->drush('cr');

        $locale = $this->taskExecStack()
            ->dir($this->getConfig()->get('digipolis.root.project'))
            ->exec(
                'vendor/bin/drush '
                . '-r ' . $this->getConfig()->get('digipolis.root.web') . ' '
                . 'pml --core --fields=name --status=enabled --type=module --format=list '
                . '| grep "(locale)"'
            )->run()->wasSuccessful();

        if ($locale) {
            $collection
                ->drush('locale-check')
                ->drush('locale-update');
        }

        if ($opts['config-import']) {
            $uuid = $this->getSiteUuid();
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cim');

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(vendor/bin/drush -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
                ->exec('bash -c "[[ \'$ENABLED_MODULES\' =~ \((varnish|purge)\) && \'$ENABLED_MODULES\' =~ \(page_cache\) ]]" && exit 1 || :');

            $collection->taskDrushStack('vendor/bin/drush')
                ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('sset system.maintenance_mode 0');

        return $collection;
    }

    protected function getSiteUuid()
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            $this->say('Could not get site UUID. No webroot found.');
            return false;
        }

        $finder = new \Symfony\Component\Finder\Finder();
        $this->say('Searching for settings.php in ' . $webDir . '/sites and subdirectories.');
        $finder->in($webDir . '/sites')->files()->name('settings.php');
        $config_directories = [];
        foreach ($finder as $settingsFile) {
            $app_root = $webDir;
            $site_path = 'sites/default';
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
            ->drush('cim');

        $collection->taskExecStack()
            ->exec('ENABLED_MODULES=$(vendor/bin/drush -r ' . $this->getConfig()->get('digipolis.root.web') . ' pml --fields=name --status=enabled --type=module --format=list)')
            ->exec('bash -c "[[ \'$ENABLED_MODULES\' =~ \((varnish|purge)\) && \'$ENABLED_MODULES\' =~ \(page_cache\) ]]" && exit 1 || :');

        $collection->taskDrushStack('vendor/bin/drush')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr');

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
        $collection->taskExecStack()
            ->exec('rm -rf ' . $local['filesdir'] . '/files')
            ->exec('mv ' . $local['filesdir'] . '/public ' . $local['filesdir'] . '/files')
            ->exec('mv ' . $local['filesdir'] . '/private ' . $local['filesdir'] . '/files/private');
        return $collection;
    }

    protected function defaultDbConfig()
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            return false;
        }

        $finder = new \Symfony\Component\Finder\Finder();
        $finder->in($webDir . '/sites')->files()->name('settings.php');
        foreach ($finder as $settingsFile) {
            $app_root = $webDir;
            $site_path = 'sites/default';
            include $settingsFile->getRealpath();
            break;
        }
        if (!isset($databases['default']['default'])) {
            return false;
        }
        $config = $databases['default']['default'];
        return [
          'default' => [
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
            ]
        ];
    }
}
