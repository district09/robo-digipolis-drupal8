<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\DefaultHandler\BackupRemoteHandler as HelpersBackupRemoteHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class BackupRemoteHandler extends HelpersBackupRemoteHandler
{
    public function getPriority(): int {
        return parent::getPriority() - 100;
    }
    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        $options = $event->getArgument('options');
        $timeouts = $event->getArgument('timeouts');
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());
        $backupDir = $remoteSettings['backupsdir'] . '/' . $remoteSettings['time'];
        $currentProjectRoot = $remoteConfig->getCurrentProjectRoot();

        $collection = $this->collectionBuilder();
        $collection->taskSsh($remoteConfig->getHost(), $auth)
            ->exec((string) CommandBuilder::create('mkdir')->addFlag('p')->addArgument($backupDir));

        // Overwrite database backups to handle aliases.
        if ($options['files'] === $options['data']) {
            $parentEvent = clone $event;
            $parentOptions = ['files' => true, 'data' => false] + $options;
            $parentEvent->setArgument('options', $parentOptions);
            $collection->addTask(parent::handle($parentEvent));
        }
        if ($options['data'] || $options['files'] === $options['data']) {
            $aliases = $remoteSettings['aliases'] ?: [0 => false];
            foreach ($aliases as $uri => $alias) {
                $dbBackupFile = $this->backupFileName(($alias ? '.' . $alias : '') . '.sql');
                $dbBackup = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:database-backup')->addOption('destination', $backupDir . '/' . $dbBackupFile);
                if ($alias) {
                    $dbBackup->addArgument($alias);
                }
                if ($alias) {
                    $currentWebRoot = $remoteSettings['currentdir'];
                    $dbBackup = '[[ ! -f ' . escapeshellarg($currentWebRoot . '/sites/' . $alias . '/settings.php') . ' ]] || ' . $dbBackup;
                }
                $collection->taskSsh($remoteConfig->getHost(), $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout($timeouts['backup_database'])
                    ->exec((string) $dbBackup);
            }
        }
        $event->stopPropagation();

        return $collection;
    }
}
