<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class ClearCacheHandler extends AbstractTaskEventHandler implements ConfigAwareInterface
{

    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \DigipolisGent\Robo\Task\Deploy\Tasks;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Boedah\Robo\Task\Drush\loadTasks;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        $currentWebRoot = $remoteSettings['currentdir'];
        $aliases = $remoteSettings['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());
        foreach ($aliases as $uri => $alias) {
            $drushCommand = CommandBuilder::create('../vendor/bin/drush');
            if ($alias) {
                $drushCommand->addOption('uri', $uri);
            }
            $collection->taskSsh($remoteConfig->getHost(), $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                ->exec((string) (clone $drushCommand)->addArgument('cr'))
                ->exec((string) (clone $drushCommand)->addArgument('cc')->addArgument('drush'));

            $purge = $this->taskSsh($remoteConfig->getHost(), $auth)
                ->remoteDirectory($currentWebRoot, true)
                ->timeout(120)
                // Check if the drush_purge module is enabled and if an 'everything'
                // purger is configured.
                ->exec(
                    (string) $this->checkModuleCommand('purge_drush', $remoteSettings, $uri)
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
                $collection->exec(
                    (string) (clone $drushCommand)
                        ->addArgument('pinv')
                        ->addArgument('everything')
                        ->addFlag('y')
                        // This one is allowed to fail.
                        ->onFailure(CommandBuilder::create('exit')->addRawArgument(0))
                );
            }
        }
        return $collection;
    }
}
