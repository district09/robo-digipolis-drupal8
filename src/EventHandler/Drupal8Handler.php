<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use Consolidation\AnnotatedCommand\CommandError;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use RandomLib\Factory;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\ConfigAwareInterface;
use SecurityLib\Strength;

abstract class Drupal8Handler extends AbstractTaskEventHandler implements ConfigAwareInterface
{
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \Robo\Task\Base\Tasks;
    use \Robo\Task\Filesystem\Tasks;
    use \Boedah\Robo\Task\Drush\loadTasks;
    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;

    protected function getDatabaseConfig(array $aliases, ?string $uri = null): array
    {
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $subfolder = $uri ? $aliases[$uri] : 'default';
        $site_path = $app_root . '/sites/' . $subfolder;

        if (is_file($site_path . '/settings.php')) {
            chmod($site_path . '/settings.php', 0664);
            include $site_path . '/settings.php';
        }
        elseif (is_file($site_path . '/settings.local.php')) {
            chmod($site_path, 0775);
            include $site_path . '/settings.local.php';
        }
        else {
            return new CommandError('No settings file found.');
        }

        return $databases['default']['default'];
    }

    protected function getDatabaseUrl(array $databaseConfig)
    {
        if ($databaseConfig['driver'] === 'sqlite') {
            return $databaseConfig['driver'] . '://' . $databaseConfig['database'];
        }

        return $databaseConfig['driver'] . '://'
            . $databaseConfig['username'] . ':' . $databaseConfig['password']
            . '@' . $databaseConfig['host']
            . (isset($databaseConfig['port']) && !empty($databaseConfig['port'])
                ? ':' . $databaseConfig['port']
                : ''
            )
            . '/' . $databaseConfig['database'];
    }

    protected function getAccountPassword(string $default = null)
    {
        if ($default) {
            return $default;
        }

        $factory = new Factory();

        return $factory
            ->getGenerator(new Strength(Strength::MEDIUM))
            ->generateString(16);
    }

    protected function addConfigImportTask(CollectionBuilder $collection, array $options, ?string $uri = null, array $aliases = [])
    {
        if ($options['config-import']) {
            $drushBase = CommandBuilder::create('vendor/bin/drush');
            if ($uri) {
                $drushBase->addOption('uri', $uri);
            }
            $drushBase->addFlag('r', $this->getConfig()->get('digipolis.root.web'));
            $uuid = $this->getSiteUuid($uri, $aliases);
            if ($uuid) {
                $collection->taskExec(
                    (string)(clone $drushBase)
                        ->addArgument('cset')
                        ->addArgument('system.site')
                        ->addArgument('uuid')
                        ->addArgument($uuid)
                        ->addFlag('y')
                );
            }
            foreach ($this->getLanguageUuids($uri, $aliases) as $langcode => $uuid) {
                $collection->taskExec(
                    (string) CommandBuilder::create(
                        (clone $drushBase)
                            ->addArgument('cset')
                            ->addArgument('language.entity.' . $langcode)
                            ->addArgument('uuid')
                            ->addArgument($uuid)
                            ->addFlag('y')
                    )
                    ->onFailure('echo')->addArgument('Could not update uuid of language "' . $langcode . '"')
                );
            }
            $collection->taskExec((string)(clone $drushBase)->addArgument('cim')->addFlag('y'));
        }
    }

    protected function addLocaleUpdateTask(CollectionBuilder $collection, ?string $uri = null)
    {
        // Translation updates if locale module is enabled.
        $drushBase = CommandBuilder::create('vendor/bin/drush');
        if ($uri) {
            $drushBase->addOption('uri', $uri);
        }
        $drushBase->addFlag('r', $this->getConfig()->get('digipolis.root.web'));

        $localeUpdate = $this->checkModuleCommand('locale', null, $uri)
            ->onSuccess((clone $drushBase)->addArgument('locale-check'))
            ->onSuccess((clone $drushBase)->addArgument('locale-update'));

        // Allow this command to fail if the locale module is not present.
        $localeUpdate = CommandBuilder::create($localeUpdate)
            ->onFailure('echo')
            ->addArgument("Locale module not found, translations will not be imported.");

        $collection->taskExec((string) $localeUpdate);
    }

    protected function addVarnishCheckTask(CollectionBuilder $collection, ?string $uri = null)
    {
        $listModules = CommandBuilder::create('vendor/bin/drush')
            ->addFlag('r', $this->getConfig()->get('digipolis.root.web'))
            ->addArgument('pml')
            ->addOption('fields', 'name')
            ->addOption('status', 'enabled')
            ->addOption('type', 'module')
            ->addOption('format', 'list');
        if ($uri) {
            $listModules->addOption('uri', $uri);
        }
        $collection->taskExecStack()
            ->exec('ENABLED_MODULES=$(' . $listModules . ')')
            ->exec((string) $this->varnishCheckCommand($uri));
    }

    protected function addDisableMaintenanceModeTask(CollectionBuilder $collection, ?string $uri = null)
    {
        $collection->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $collection->uri($uri);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('sset system.maintenance_mode 0');
    }
}
