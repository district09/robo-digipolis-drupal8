<?php

namespace DigipolisGent\Robo\Drupal8;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\AbstractRoboFile;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use Symfony\Component\Finder\Finder;

class RoboFileBase extends AbstractRoboFile
{
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \DigipolisGent\Robo\Task\Package\Drupal8\loadTasks;
    use \DigipolisGent\Robo\Task\CodeValidation\loadTasks;
    use \DigipolisGent\Robo\Helpers\Traits\AbstractCommandTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use Traits\BuildDrupal8Trait;
    use Traits\DeployDrupal8Trait;
    use Traits\UpdateDrupal8Trait;
    use Traits\InstallDrupal8Trait;
    use Traits\Drupal8UtilsTrait;
    use Traits\SyncDrupal8Trait;

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

    /**
     * {@inheritdoc}
     */
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
            $result = $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->exec((string) $this->usersTableCheckCommand('../vendor/bin/drush', $uri))
                ->exec('[[ -f ' . escapeshellarg($currentWebRoot . '/sites/' . ($alias ?: 'default') . '/settings.php') . ' ]] || exit 1')
                ->stopOnFail()
                ->timeout(300)
                ->run();
            $this->setSiteInstalled($result->wasSuccessful(), $uri);
            if ($alias === false) {
                $this->siteInstalledTested = true;
            }
            else {
                $this->siteInstalledTested[$alias] = true;
            }
        }

        return $remote['aliases'] ? count(array_filter($this->siteInstalled)) === count($this->siteInstalled) : $this->siteInstalled;
    }

    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
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
                $drop = CommandBuilder::create('../vendor/bin/drush')
                    ->addArgument('sql-drop')
                    ->addFlag('y');
                if ($alias) {
                    $drop->addOption('uri', $uri);
                }
                $collection
                    ->taskSsh($worker, $auth)
                        ->remoteDirectory($currentWebRoot, true)
                        ->timeout(60)
                        ->exec((string) $drop);
            }

        }
        return $collection;
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
            ->exec((string) CommandBuilder::create('mkdir')->addFlag('p')->addArgument($backupDir));

        // Overwrite database backups to handle aliases.
        if ($opts['files'] === $opts['data']) {
            $parentOpts = ['files' => true, 'data' => false] + $opts;
            $collection->addTask(parent::backupTask($worker, $auth, $remote, $parentOpts));
        }
        if ($opts['data'] || $opts['files'] === $opts['data']) {
            $aliases = $remote['aliases'] ?: [ 0 => false];
            foreach ($aliases as $uri => $alias) {
                $dbBackupFile = $this->backupFileName(($alias ? '.' . $alias : '') . '.sql');
                $dbBackup = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:database-backup')->addOption('destination', $backupDir . '/' . $dbBackupFile);
                if ($alias) {
                    $dbBackup->addArgument($alias);
                }
                if ($alias) {
                    $currentWebRoot = $remote['currentdir'];
                    $dbBackup = '[[ ! -f ' . escapeshellarg($currentWebRoot . '/sites/' . $alias . '/settings.php') . ' ]] || ' . $dbBackup;
                }
                $collection->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout($this->getTimeoutSetting('backup_database'))
                    ->exec((string) $dbBackup);
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
                $preRestoreBackup = $this->preRestoreBackupTask($worker, $auth, $remote, ['data' => true, 'files' => false]);
                if ($preRestoreBackup) {
                    $collection->addTask($preRestoreBackup);
                }
                $dbBackupFile =  $this->backupFileName(($alias ? '.' . $alias : '') . '.sql.gz', $remote['time']);
                $dbRestore = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:database-restore')->addOption('source', $backupDir . '/' . $dbBackupFile);
                if ($alias) {
                    $dbRestore->addArgument($alias);
                }
                $collection
                    ->taskSsh($worker, $auth)
                        ->remoteDirectory($currentProjectRoot, true)
                        ->timeout($this->getTimeoutSetting('restore_db_backup'))
                        ->exec((string) $dbRestore);
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
                ->exec((string) CommandBuilder::create('rm')->addFlag('rf')->addArgument($local['filesdir'] . '/files'))
                ->exec((string) CommandBuilder::create('mv')->addArgument($local['filesdir'] . '/public')->addArgument($local['filesdir'] . '/files'))
                ->exec((string) CommandBuilder::create('mv')->addArgument($local['filesdir'] . '/private')->addArgument($local['filesdir'] . '/files/private'));
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
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
     * {@inheritdoc}
     */
    protected function clearCacheTask($worker, $auth, $remote)
    {
        $currentWebRoot = $remote['currentdir'];
        $aliases = $remote['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        foreach ($aliases as $uri => $alias) {
            $drushCommand = CommandBuilder::create('../vendor/bin/drush');
            if ($alias) {
                $drushCommand->addOption('uri', $uri);
            }
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                ->exec((string) (clone $drushCommand)->addArgument('cr'))
                ->exec((string) (clone $drushCommand)->addArgument('cc')->addArgument('drush'));

            $purge = $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                // Check if the drush_purge module is enabled and if an 'everything'
                // purger is configured.
                ->exec(
                    (string) $this->checkModuleCommand('purge_drush', $remote, $uri)
                        ->onSuccess('cd')
                            ->addFlag('P')
                            ->addArgument($currentWebRoot)
                        ->onSuccess(
                            (clone $drushCommand)
                                ->addArgument('ptyp')
                                ->addOption('format', 'list')
                        )
                        ->pipeOutputTo('grep')
                            ->addArgument('everything')
                )
                ->run()
                ->wasSuccessful();

            if ($purge) {
                $collection->exec((string) (clone $drushCommand)->addArgument('pinv')->addArgument('everything')->addFlag('y'));
            }
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $settings = parent::getRemoteSettings($host, $user, $keyFile, $app, $timestamp);
        $settings['aliases'] = $this->parseSiteAliases();

        return $settings;
    }

    /**
     * {@inheritdoc}
     */
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
            ];
        }
        return $dbConfig ?: false;
    }
}
