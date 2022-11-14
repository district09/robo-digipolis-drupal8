<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class InstallHandler extends AbstractTaskEventHandler
{

    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
    use \DigipolisGent\Robo\Task\Deploy\Tasks;

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
        $force = $event->getArgument('force');
        $options += ['config-import' => false];
        $currentProjectRoot = $remoteSettings['currentdir'] . '/..';
        $aliases = $remoteSettings['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        foreach ($aliases as $uri => $alias) {
            $install = CommandBuilder::create('vendor/bin/robo')
                ->addArgument('digipolis:install-drupal8')
                ->addArgument($options['profile'])
                ->addOption('site-name', $options['site-name']);
            if ($force) {
                $install->addOption('force');
            }
            if ($options['config-import']) {
                $install->addOption('config-import');
            }
            if ($options['existing-config']) {
                $install->addOption('existing-config');
            }
            if ($alias) {
                $install->addOption('uri', $uri);
            }

            if (!$force) {
                $install = '[[ $(' . $this->usersTableCheckCommand('vendor/bin/drush', $alias ? $uri : '') . ') ]] || ' . $install;
            }

            $collection->taskSsh($remoteConfig->getHost(), new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile()))
                ->remoteDirectory($currentProjectRoot, true)
                // Install can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->verbose($options['ssh-verbose'])
                ->exec((string) $install);
        }

        return $collection;
    }
}
