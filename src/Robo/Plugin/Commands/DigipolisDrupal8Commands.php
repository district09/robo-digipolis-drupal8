<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\CommandError;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Drupal8\Util\RemoteHelper;
use DigipolisGent\Robo\Drupal8\Util\TaskFactory\Backup;
use DigipolisGent\Robo\Drupal8\Util\TaskFactory\Build;
use DigipolisGent\Robo\Drupal8\Util\TaskFactory\Drupal8;
use DigipolisGent\Robo\Helpers\DependencyInjection\AppTaskFactoryAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\DeployTaskFactoryAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\PropertiesHelperAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\SyncTaskFactoryAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\AppTaskFactoryAware;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\DeployTaskFactoryAware;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\PropertiesHelperAware;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\SyncTaskFactoryAware;
use DigipolisGent\Robo\Helpers\Robo\Plugin\Commands\DigipolisHelpersCommands;
use DigipolisGent\Robo\Helpers\Util\PropertiesHelper;
use DigipolisGent\Robo\Helpers\Util\RemoteHelper as HelpersRemoteHelper;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\AbstractApp;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\Backup as HelpersBackup;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\Build as HelpersBuild;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\Deploy;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\Sync;
use League\Container\ContainerAwareInterface;
use League\Container\DefinitionContainerInterface;
use RandomLib\Factory;
use SecurityLib\Strength;
use Symfony\Component\Finder\Finder;

class DigipolisDrupal8Commands extends DigipolisHelpersCommands implements
    PropertiesHelperAwareInterface,
    DeployTaskFactoryAwareInterface,
    SyncTaskFactoryAwareInterface,
    AppTaskFactoryAwareInterface
{
    use PropertiesHelperAware;
    use DeployTaskFactoryAware;
    use SyncTaskFactoryAware;
    use AppTaskFactoryAware;
    use \Boedah\Robo\Task\Drush\loadTasks;

    public function setContainer(DefinitionContainerInterface $container): ContainerAwareInterface {
        parent::setContainer($container);

        $container->extend(HelpersBackup::class)->setConcrete([Backup::class, 'create']);
        $container->extend(HelpersRemoteHelper::class)->setConcrete([RemoteHelper::class, 'create']);
        $container->extend(HelpersBuild::class)->setConcrete([Build::class, 'create']);

        // Inject all our dependencies.
        $this->setRemoteHelper($container->get(HelpersRemoteHelper::class));
        $this->setBackupTaskFactory($container->get(HelpersBackup::class));
        $this->setPropertiesHelper($container->get(PropertiesHelper::class));
        $this->setDeployTaskFactory($container->get(Deploy::class));
        $this->setSyncTaskFactory($container->get(Sync::class));
        $this->setAppTaskFactory($container->get(AbstractApp::class));

        return $this;
    }

    public function getAppTaskFactoryClass() {
        return Drupal8::class;
    }

    /**
     * @hook replace-command digipolis:sync-local
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
        $local = $this->remoteHelper->getLocalSettings($opts['app']);
        $collection = parent::digipolisSyncLocal($host, $user, $keyFile, $opts);
        if ($opts['files']) {
            $collection->taskExecStack()
                ->exec((string) CommandBuilder::create('rm')->addFlag('rf')->addArgument($local['filesdir'] . '/files'))
                ->exec((string) CommandBuilder::create('mv')->addArgument($local['filesdir'] . '/public')->addArgument($local['filesdir'] . '/files'))
                ->exec((string) CommandBuilder::create('mv')->addArgument($local['filesdir'] . '/private')->addArgument($local['filesdir'] . '/files/private'));
        }
        return $collection;
    }

    /**
     * @hook on-event digipolis-backup-config
     */
    public function getBackupConfig()
    {
        return [
            'file_backup_subdirs' => ['public', 'private'],
            'exclude_from_backup' => ['php', 'js/*', 'css/*', 'styles/*'],
        ];
    }


    /**
     * @hook on-event digipolis-db-config
     */
    public function defaultDbConfig()
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            return false;
        }
        $this->propertiesHelper->readProperties();
        $settings = $this->getConfig()->get('remote');
        if (!isset($settings['aliases'])) {
            $settings['aliases'] = $this->remoteHelper->parseSiteAliases();
        }
        $aliases = $settings['aliases'] ?: [0 =>false];

        foreach ($aliases as $uri => $alias) {
            $finder = new Finder();
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
                'extra' => '--skip-add-locks --no-tablespaces',
            ];
        }
        return $dbConfig ?: false;
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
        return $this->deployTaskFactory->deployTask($arguments, $opts);
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
        $opts += ['config-import' => false, 'uri' => null];
        $this->propertiesHelper->readProperties();
        $collection = $this->collectionBuilder();
        $enableMaintenanceMode = CommandBuilder::create('cd')
            ->addFlag('P')
            ->addRawArgument(
                '$(' . CommandBuilder::create('ls')
                    ->addFlag('vdr')
                    ->addRawArgument(
                        $this->getConfig()->get('digipolis.root.project') . '/../*'
                    )
                    ->pipeOutputTo(
                        'head'
                    )
                    ->addFlag('n2')
                    ->pipeOutputTo('tail')
                    ->addFlag('n1')
                . ')'
            )
            ->onSuccess('vendor/bin/drush')
                ->addArgument('sset')
                ->addArgument('system.maintenance_mode')
                ->addArgument('1');
        if ($opts['uri']) {
            $enableMaintenanceMode->addOption('uri', $opts['uri']);
        }
        $collection
            ->taskExecStack()
            ->exec(
                (string) $enableMaintenanceMode
            );

        $collection
            ->taskDrushStack('vendor/bin/drush');
        if ($opts['uri']) {
            $collection->uri($opts['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr')
            ->drush('cc drush')
            ->updateDb();

        if ($opts['config-import']) {
            $uuid = $this->appTaskFactory->getSiteUuid($opts['uri']);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cr')
                ->drush('cc drush')
                ->drush('cim');

            $listModules = CommandBuilder::create('vendor/bin/drush')
                ->addFlag('r', $this->getConfig()->get('digipolis.root.web'))
                ->addArgument('pml')
                ->addOption('fields', 'name')
                ->addOption('status', 'enabled')
                ->addOption('type', 'module')
                ->addOption('format', 'list');
            if ($opts['uri']) {
                $listModules->addOption('uri', $opts['uri']);
            }

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(' . $listModules . ')')
                ->exec((string) $this->appTaskFactory->varnishCheckCommand($opts['uri']));

            $collection->taskDrushStack('vendor/bin/drush');
            if ($opts['uri']) {
                $collection->uri($opts['uri']);
            }
            $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('cr')
            ->drush('cc drush');

        // Translation updates if locale module is enabled.
        $drushBase = CommandBuilder::create('vendor/bin/drush');
        if ($opts['uri']) {
            $drushBase->addOption('uri', $opts['uri']);
        }
        $drushBase->addFlag('r', $this->getConfig()->get('digipolis.root.web'));

        $localeUpdate = $this->appTaskFactory->checkModuleCommand('locale', null, $opts['uri'])
            ->onSuccess((clone $drushBase)->addArgument('locale-check'))
            ->onSuccess((clone $drushBase)->addArgument('locale-update'));

        // Allow this command to fail if the locale module is not present.
        $localeUpdate = CommandBuilder::create($localeUpdate)
            ->onFailure('echo')
            ->addArgument("Locale module not found, translations will not be imported.");

        $collection->taskExec((string) $localeUpdate);

        $collection->taskDrushStack('vendor/bin/drush');

        if ($opts['uri']) {
            $collection->uri($opts['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));

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
        $this->propertiesHelper->readProperties();
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $uri = $opts['uri'] ?: '';
        $subfolder = $uri ? $this->remoteHelper->parseSiteAliases()[$uri] : 'default';
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
        // Installations can start with existing databases. Don't drop them if
        // they did.
        if (!$this->taskExec('[[ $(' . $this->usersTableCheckCommand('vendor/bin/drush', $uri) . ') ]]')->run()->wasSuccessful()) {
            $drop = $this->taskDrushStack('vendor/bin/drush');
            if ($uri) {
                $drop->uri($uri);
            }
            $drop->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
                ->drush('sql-drop');

            $collection->rollback($drop);
        }
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
        $drushInstall = $collection->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $drushInstall->uri($uri);
        }
        $drushInstall->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
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

        // Translation updates if locale module is enabled.
        $drushBase = CommandBuilder::create('vendor/bin/drush');
        if ($uri) {
            $drushBase->addOption('uri', $uri);
        }
        $drushBase->addFlag('r', $this->getConfig()->get('digipolis.root.web'));

        $localeUpdate = $this->appTaskFactory->checkModuleCommand('locale', null, $uri)
            ->onSuccess((clone $drushBase)->addArgument('locale-check'))
            ->onSuccess((clone $drushBase)->addArgument('locale-update'));

        // Allow this command to fail if the locale module is not present.
        $localeUpdate = CommandBuilder::create($localeUpdate)
            ->onFailure('echo')
            ->addArgument("Locale module not found, translations will not be imported.");

        $collection->taskExec((string) $localeUpdate);

        if ($opts['config-import']) {
            $collection->taskDrushStack('vendor/bin/drush');
            if ($uri) {
                $collection->uri($uri);
            }
            $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
            $uuid = $this->appTaskFactory->getSiteUuid($uri);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cim');

            $listModules = CommandBuilder::create('vendor/bin/drush')
                ->addFlag('r', $this->getConfig()->get('digipolis.root.web'))
                ->addArgument('pml')
                ->addOption('fields', 'name')
                ->addOption('status', 'enabled')
                ->addOption('type', 'module')
                ->addOption('format', 'list');
            if ($opts['uri']) {
                $listModules->addOption('uri', $opts['uri']);
            }

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(' . $listModules . ')')
                ->exec((string) $this->appTaskFactory->varnishCheckCommand($uri));
        }

        $collection->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $collection->uri($uri);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('sset system.maintenance_mode 0');

        return $collection;
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
        return $this->syncTaskFactory->syncTask(
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

}
