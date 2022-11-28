<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractBackupHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use Symfony\Component\EventDispatcher\GenericEvent;

class RestoreBackupFilesLocalHandler extends AbstractBackupHandler
{

    public function getPriority(): int {
      return parent::getPriority() + 100;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        $localSettings = $event->getArgument('localSettings');
        $filesBackupFile =  $this->backupFileName('.tar.gz', $remoteSettings['time']);

        return $this->taskExecStack()
            ->exec(
                (string) CommandBuilder::create('rm')
                    ->addFlag('rf')
                    ->addArgument($localSettings['filesdir'] . '/files')
            )
            ->exec(
                (string) CommandBuilder::create('mv')
                    ->addArgument($localSettings['filesdir'] . '/public')
                    ->addArgument($localSettings['filesdir'] . '/files')
            )
            ->exec(
                (string) CommandBuilder::create('mv')
                    ->addArgument($localSettings['filesdir'] . '/private')
                    ->addArgument($localSettings['filesdir'] . '/files/private')
            );
    }
}
