<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;

trait UpdateDrupal8Trait {

    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getUpdateDrupal8TraitDependencies()
    {
        return [AbstractDeployCommandTrait::class, Drupal8UtilsTrait::class];
    }

    /**
     * Executes D8 database updates of the D8 site in the current folder.
     *
     * Executes D8 database updates of the D8 site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     */
    public function digipolisUpdateDrupal8($opts = ['config-import' => false, 'uri' => null])
    {
        $opts += ['config-import' => false, 'uri' => null];
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $enableMaintenanceMode = CommandBuilder::create('cd')
            ->addFlag('P')
            ->addRawArgument(
                '$(' . CommandBuilder::create('ls')
                    ->addFlag('vdr')
                    ->addRawArgument(
                        $this->getConfig()->get('digipolis.root.project') . '/../*'
                    )
                    ->pipeOutputTo(
                        'head'
                    )
                    ->addFlag('n2')
                    ->pipeOutputTo('tail')
                    ->addFlag('n1')
                . ')'
            )
            ->onSuccess('vendor/bin/drush')
                ->addArgument('sset')
                ->addArgument('system.maintenance_mode')
                ->addArgument('1');
        if ($opts['uri']) {
            $enableMaintenanceMode->addOption('uri', $opts['uri']);
        }
        $collection
            ->taskExecStack()
            ->exec(
                (string) $enableMaintenanceMode
            );

        $collection
            ->taskDrushStack('vendor/bin/drush');
        if ($opts['uri']) {
            $collection->uri($opts['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr')
            ->drush('cc drush')
            ->updateDb();

        if ($opts['config-import']) {
            $uuid = $this->getSiteUuid($opts['uri']);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
                ->drush('cr')
                ->drush('cc drush')
                ->drush('cim');

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
                ->exec((string) $this->varnishCheckCommand($opts['uri']));

            $collection->taskDrushStack('vendor/bin/drush');
            if ($opts['uri']) {
                $collection->uri($opts['uri']);
            }
            $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('cr')
            ->drush('cc drush');

        $locale = $this->taskExecStack()
            ->dir($this->getConfig()->get('digipolis.root.project'))
            ->exec((string) $this->checkModuleCommand('locale', null, $opts['uri']))
            ->run()
            ->wasSuccessful();

        $collection->taskDrushStack('vendor/bin/drush');
        if ($opts['uri']) {
            $collection->uri($opts['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));

        if ($locale) {
            $collection
                ->drush('locale-check')
                ->drush('locale-update');
        }

        $collection
            ->drush('cr')
            ->drush('cc drush')
            ->drush('sset system.maintenance_mode 0');

        return $collection;
    }

    protected function updateTask($worker, AbstractAuth $auth, $remote, $extra = [])
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
}
