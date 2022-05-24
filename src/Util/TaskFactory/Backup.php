<?php

namespace DigipolisGent\Robo\Drupal8\Util\TaskFactory;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\Backup as BackupBase;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;

class Backup extends BackupBase
{
    /**
     * Create a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The backup task.
     */
    public function backupTask($worker, AbstractAuth $auth, $remote, $opts = array())
    {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $this->remoteHelper->getCurrentProjectRoot($worker, $auth, $remote);

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
                    ->timeout($this->remoteHelper->getTimeoutSetting('backup_database'))
                    ->exec((string) $dbBackup);
            }
        }
        return $collection;
    }

    /**
     * Restore a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The restore backup task.
     */
    public function restoreBackupTask($worker, AbstractAuth $auth, $remote, $opts = array())
    {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $this->remoteHelper->getCurrentProjectRoot($worker, $auth, $remote);

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
                        ->timeout($this->remoteHelper->getTimeoutSetting('restore_db_backup'))
                        ->exec((string) $dbRestore);
            }
        }
        return $collection;
    }


    /**
     * Pre restore backup task.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The pre restore backup task, false if no pre restore backup tasks need
     *   to run.
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
}
