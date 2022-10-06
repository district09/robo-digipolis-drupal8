<?php

namespace DigipolisGent\Robo\Drupal8\Util\TaskFactory;

use Consolidation\AnnotatedCommand\Output\OutputAwareInterface;
use Consolidation\Config\ConfigInterface;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
use DigipolisGent\Robo\Helpers\DependencyInjection\PropertiesHelperAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\RemoteHelperAwareInterface;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\AppTaskFactoryAware;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\PropertiesHelperAware;
use DigipolisGent\Robo\Helpers\DependencyInjection\Traits\RemoteHelperAware;
use DigipolisGent\Robo\Helpers\Util\PropertiesHelper;
use DigipolisGent\Robo\Helpers\Util\RemoteHelper;
use DigipolisGent\Robo\Helpers\Util\TaskFactory\AbstractApp;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use League\Container\DefinitionContainerInterface;
use Robo\Collection\CollectionBuilder;
use Symfony\Component\Console\Input\InputAwareInterface;

class Drupal8 extends AbstractApp implements PropertiesHelperAwareInterface, RemoteHelperAwareInterface, InputAwareInterface, OutputAwareInterface
{

    use RemoteHelperAware;
    use \DigipolisGent\Robo\Task\Deploy\Tasks;
    use AppTaskFactoryAware;
    use Drupal8UtilsTrait;
    use PropertiesHelperAware;
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \Robo\Common\IO;

    protected $siteInstalled = null;

    protected $siteInstalledTested;

    public function __construct(ConfigInterface $config, PropertiesHelper $propertiesHelper, RemoteHelper $remoteHelper) {
        parent::__construct($config);
        $this->setPropertiesHelper($propertiesHelper);
        $this->setRemoteHelper($remoteHelper);
    }

    public static function create(DefinitionContainerInterface $container) {
      $object = new static(
        $container->get('config'),
        $container->get(PropertiesHelper::class),
        $container->get(RemoteHelper::class)
      );
      $object->setBuilder(CollectionBuilder::create($container, $object));

      return $object;
    }

    /**
     * Install the site in the current folder.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param bool $force
     *   Whether or not to force the install even when the site is present.
     *
     * @return \Robo\Contract\TaskInterface
     *   The install task.
     */
    public function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $aliases = $remote['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();
        foreach ($aliases as $uri => $alias) {
            $install = CommandBuilder::create('vendor/bin/robo')
                ->addArgument('digipolis:install-drupal8')
                ->addArgument($extra['profile'])
                ->addOption('site-name', $extra['site-name']);
            if ($force) {
                $install->addOption('force');
            }
            if ($extra['config-import']) {
                $install->addOption('config-import');
            }
            if ($extra['existing-config']) {
                $install->addOption('existing-config');
            }
            if ($alias) {
                $install->addOption('uri', $uri);
            }

            if (!$force) {
                if (!$alias && $this->siteInstalledTested) {
                    $install = '[[ $(' . $this->usersTableCheckCommand('vendor/bin/drush') . ') ]] || ' . $install;
                }
                elseif ($alias && is_array($this->siteInstalledTested) && isset($this->siteInstalledTested[$alias]) && $this->siteInstalledTested[$alias]) {
                    $install = '[[ $(' . $this->usersTableCheckCommand('vendor/bin/drush', $uri) . ') ]] || ' . $install;
                }
            }

            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Install can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->verbose($extra['ssh-verbose'])
                ->exec((string) $install);
        }
        return $collection;
    }

    /**
     * Executes database updates of the site in the current folder.
     *
     * Executes database updates of the site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The update task.
     */
    public function updateTask($worker, AbstractAuth $auth, $remote, $extra = [])
    {
        $extra += ['config-import' => false];
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $update = CommandBuilder::create('vendor/bin/robo')->addArgument('digipolis:update-drupal8');
        if ($extra['config-import']) {
            $update->addOption('config-import');
        }
        $aliases = $remote['aliases'] ?: [0 => false];
        $collection = $this->collectionBuilder();

        foreach ($aliases as $uri => $alias) {
            $aliasUpdate = clone $update;
            if ($alias) {
                $aliasUpdate->addOption('uri', $uri);
            }
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    // Updates can take a long time. Let's set it to 15 minutes.
                    ->timeout(900)
                    ->verbose($extra['ssh-verbose'])
                    ->exec((string) $aliasUpdate);
        }
        return $collection;
    }

    /**
     * Check if a site is already installed
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool
     *   Whether or not the site is installed.
     */
    public function isSiteInstalled($worker, AbstractAuth $auth, $remote)
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

    public function setSiteInstalled($installed, $uri = false)
    {
        if (!$uri) {
            $this->siteInstalled = $installed;
            $this->siteInstalledTested = false;
            return;
        }
        if (is_null($this->siteInstalled)) {
            $this->siteInstalled = [];
            $this->siteInstalledTested = [];
        }
        $this->siteInstalled[$uri] = $installed;
        $this->siteInstalledTested[$uri] = false;
    }

    /**
     * Clear cache of the site.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The clear cache task or false if no clear cache task exists.
     */
    public function clearCacheTask($worker, $auth, $remote)
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
