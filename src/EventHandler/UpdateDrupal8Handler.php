<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use Robo\Collection\CollectionBuilder;
use Symfony\Component\EventDispatcher\GenericEvent;

class UpdateDrupal8Handler extends Drupal8Handler
{

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $options = $event->getArgument('options');
        $aliases = $event->getArgument('aliases');
        $options += ['config-import' => false, 'uri' => null];
        $this->readProperties();
        $uri = $options['uri'] ?: null;

        $collection = $this->collectionBuilder();
        $this->addEnableMaintenanceModeTask($collection, $uri);
        $this->addDatabaseUpdateTask($collection, $uri);
        $this->addConfigImportTask($collection, $options, $uri);
        $this->addLocaleUpdateTask($collection, $uri);
        $this->addVarnishCheckTask($collection, $uri);
        $this->addDisableMaintenanceModeTask($collection, $uri);

        return $collection;
    }

    protected function addEnableMaintenanceModeTask(CollectionBuilder $collection, ?string $uri = null)
    {
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
        if ($uri) {
            $enableMaintenanceMode->addOption('uri', $uri);
        }
        $collection
            ->taskExecStack()
            ->exec(
                (string) $enableMaintenanceMode
            );
    }

    protected function addDatabaseUpdateTask(CollectionBuilder $collection, ?string $uri = null)
    {
        $collection
            ->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $collection->uri($uri);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr')
            ->drush('cc drush')
            ->updateDb();
    }
}
