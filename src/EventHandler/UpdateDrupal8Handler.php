<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use Consolidation\Config\ConfigAwareTrait;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class UpdateDrupal8Handler extends AbstractTaskEventHandler implements ConfigAwareInterface
{

    use ConfigAwareTrait;
    use DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \Robo\Task\Base\Tasks;
    use \Robo\Task\Filesystem\Tasks;
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $options = $event->getArgument('options');
        $aliases = $event->getArgument('aliases');
        $options += ['config-import' => false, 'uri' => null];
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
        if ($options['uri']) {
            $enableMaintenanceMode->addOption('uri', $options['uri']);
        }
        $collection
            ->taskExecStack()
            ->exec(
                (string) $enableMaintenanceMode
            );

        $collection
            ->taskDrushStack('vendor/bin/drush');
        if ($options['uri']) {
            $collection->uri($options['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('cr')
            ->drush('cc drush')
            ->updateDb();

        if ($options['config-import']) {
            $uuid = $this->getSiteUuid($options['uri']);
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
            if ($options['uri']) {
                $listModules->addOption('uri', $options['uri']);
            }

            $collection->taskExecStack()
                ->exec('ENABLED_MODULES=$(' . $listModules . ')')
                ->exec((string) $this->varnishCheckCommand($options['uri']));

            $collection->taskDrushStack('vendor/bin/drush');
            if ($options['uri']) {
                $collection->uri($options['uri']);
            }
            $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        }

        $collection
            ->drush('cr')
            ->drush('cc drush');

        // Translation updates if locale module is enabled.
        $drushBase = CommandBuilder::create('vendor/bin/drush');
        if ($options['uri']) {
            $drushBase->addOption('uri', $options['uri']);
        }
        $drushBase->addFlag('r', $this->getConfig()->get('digipolis.root.web'));

        $localeUpdate = $this->checkModuleCommand('locale', null, $options['uri'])
            ->onSuccess((clone $drushBase)->addArgument('locale-check'))
            ->onSuccess((clone $drushBase)->addArgument('locale-update'));

        // Allow this command to fail if the locale module is not present.
        $localeUpdate = CommandBuilder::create($localeUpdate)
            ->onFailure('echo')
            ->addArgument("Locale module not found, translations will not be imported.");

        $collection->taskExec((string) $localeUpdate);

        $collection->taskDrushStack('vendor/bin/drush');

        if ($options['uri']) {
            $collection->uri($options['uri']);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));

        $collection
            ->drush('cr')
            ->drush('cc drush')
            ->drush('sset system.maintenance_mode 0');

        return $collection;
    }
}
