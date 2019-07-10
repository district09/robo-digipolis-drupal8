<?php

namespace DigipolisGent\Robo\Drupal8\Traits;


use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\Traits\AbstractSyncRemoteCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;

trait RestoreBackupDrupal8Trait
{

    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getRestoreBackupDrupal8TraitDependencies()
    {
        return [AbstractSyncRemoteCommandTrait::class, Drupal8UtilsTrait::class];
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
            ->exec((string) $this->varnishCheckCommand());

        return $collection;
    }
}
