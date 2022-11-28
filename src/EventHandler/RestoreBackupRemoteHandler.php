<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\DefaultHandler\RestoreBackupRemoteHandler as RestoreBackupRemoteHandlerBase;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class RestoreBackupRemoteHandler extends RestoreBackupRemoteHandlerBase
{

    public function getPriority(): int
    {
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

        $backupDir = $remoteSettings['backupsdir'] . '/' . $remoteSettings['time'];
        $currentProjectRoot = $remoteConfig->getCurrentProjectRoot();

        $collection = $this->collectionBuilder();

        // Overwrite database backups to handle aliases.
        if ($options['files'] === $options['data']) {
            $parentOptions = ['files' => true, 'data' => false] + $options;
            $parentEvent = clone $event;
            $parentEvent->setArgument('options', $parentOptions);
            $collection->addTask(parent::handle($parentEvent));
        }
        if ($options['data'] || $options['files'] === $options['data']) {
            $aliases = $remoteSettings['aliases'] ?: [0 => false];
            foreach ($aliases as $uri => $alias) {
                $dbBackupFile =  $this->backupFileName(($alias ? '.' . $alias : '') . '.sql.gz', $remoteSettings['time']);
                $dbRestore = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:database-restore')->addOption('source', $backupDir . '/' . $dbBackupFile);
                if ($alias) {
                    $dbRestore->addArgument($alias);
                }
                $collection
                    ->taskSsh($remoteConfig->getHost(), new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile()))
                        ->remoteDirectory($currentProjectRoot, true)
                        ->timeout($timeouts['restore_db_backup'])
                        ->exec((string) $dbRestore);
            }
        }

        $event->stopPropagation();
        return $collection;
    }
}
