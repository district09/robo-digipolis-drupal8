<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\DefaultHandler\PreRestoreBackupRemoteHandler as PreRestoreBackupRemoteHandlerBase;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class PreRestoreBackupRemoteHandler extends PreRestoreBackupRemoteHandlerBase
{

    public function getPriority(): int
    {
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
        $options = $event->getArgument('options');
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());
        $timeouts = $event->getArgument('timeouts');
        $fileBackupConfig = $event->getArgument('fileBackupConfig');

        if (!$options['files'] && !$options['data']) {
            $options['files'] = true;
            $options['data'] = true;
        }
        $currentWebRoot = $remoteSettings['currentdir'];
        $collection = $this->collectionBuilder();

        if ($options['data']) {
            $aliases = $remoteSettings['aliases'] ?: [0 => false];
            foreach($aliases as $uri => $alias) {
                $drop = CommandBuilder::create('../vendor/bin/drush')
                    ->addArgument('sql-drop')
                    ->addFlag('y');
                if ($alias) {
                    $drop->addOption('uri', $uri);
                }
                $collection
                    ->taskSsh($remoteConfig->getHost(), $auth)
                        ->remoteDirectory($currentWebRoot, true)
                        ->timeout(60)
                        ->exec((string) $drop);
            }

        }
        return $collection;
    }
}
