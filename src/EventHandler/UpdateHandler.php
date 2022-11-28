<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class UpdateHandler extends AbstractTaskEventHandler
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
        $options += ['config-import' => false];
        $currentProjectRoot = $remoteSettings['currentdir'] . '/..';
        $aliases = $remoteSettings['aliases'] ?: [0 => false];

        $update = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:update-drupal8');
        if ($options['config-import']) {
            $update->addOption('config-import');
        }
        $collection = $this->collectionBuilder();

        foreach ($aliases as $uri => $alias) {
            $aliasUpdate = clone $update;
            if ($alias) {
                $aliasUpdate->addOption('uri', $uri);
            }
            $collection
                ->taskSsh($remoteConfig->getHost(), new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile()))
                    ->remoteDirectory($currentProjectRoot, true)
                    // Updates can take a long time. Let's set it to 15 minutes.
                    ->timeout(900)
                    ->verbose($options['ssh-verbose'])
                    ->exec((string) $aliasUpdate);
        }
        return $collection;
    }
}
