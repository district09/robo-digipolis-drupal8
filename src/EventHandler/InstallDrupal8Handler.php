<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\Config\ConfigAwareTrait;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
use RandomLib\Factory;
use Robo\Contract\ConfigAwareInterface;
use SecurityLib\Strength;
use Symfony\Component\EventDispatcher\GenericEvent;

class InstallDrupal8Handler extends AbstractTaskEventHandler implements ConfigAwareInterface
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
        $profile = $event->getArgument('profile');
        $options = $event->getArgument('options');
        $aliases = $event->getArgument('aliases');
        $this->readProperties();
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $uri = $options['uri'] ?: '';
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

        $config = $databases['default']['default'];

        // Random string fallback for the account password.
        if (empty($options['account-pass'])) {
            $factory = new Factory();
            $options['account-pass'] = $factory
                ->getGenerator(new Strength(Strength::MEDIUM))
                ->generateString(16);
        }

        $collection = $this->collectionBuilder();
        // Installations can start with existing databases. Don't drop them if
        // they did.
        if (!$this->taskExec('[[ $(' . $this->appTaskFactory->usersTableCheckCommand('vendor/bin/drush', $uri) . ') ]]')->run()->wasSuccessful()) {
            $drop = $this->taskDrushStack('vendor/bin/drush');
            if ($uri) {
                $drop->uri($uri);
            }
            $drop->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
                ->drush('sql-drop');

            $collection->rollback($drop);
        }
        $dbUrl = false;
        if ($config['driver'] === 'sqlite') {
            $dbUrl = $config['driver'] . '://' . $config['database'];
        }
        if (!$dbUrl) {
            $dbUrl = $config['driver'] . '://'
                . $config['username'] . ':' . $config['password']
                . '@' . $config['host']
                . (isset($config['port']) && !empty($config['port'])
                    ? ':' . $config['port']
                    : ''
                )
                . '/' . $config['database'];
        }
        $drushInstall = $collection->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $drushInstall->uri($uri);
        }
        $drushInstall->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->dbUrl($dbUrl)
            ->siteName($options['site-name'])
            ->accountName($options['account-name'])
            ->accountMail($options['account-mail'])
            ->accountPass('"' . $options['account-pass'] . '"')
            ->existingConfig($options['existing-config']);

        if (isset($config['username']) && !empty($config['username'])) {
            $drushInstall->dbSu($config['username']);
        }
        if (isset($config['password']) && !empty($config['password'])) {
            $drushInstall->dbSuPw($config['password']);
        }

        if (!empty($config['prefix'])) {
            $drushInstall->dbPrefix($config['prefix']);
        }

        if ($options['force']) {
            // There is no force option for drush.
            // $collection->option('force');
        }
        $collection
            ->siteInstall($profile)
            ->drush('cc drush')
            ->drush('sset system.maintenance_mode 1')
            ->drush('cr');

        $collection->taskFilesystemStack()
            ->chmod($site_path . '/settings.php', 0444)
            ->chmod($site_path, 0555);

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

        if ($options['config-import']) {
            $collection->taskDrushStack('vendor/bin/drush');
            if ($uri) {
                $collection->uri($uri);
            }
            $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
            $uuid = $this->getSiteUuid($uri);
            if ($uuid) {
                $collection->drush('cset system.site uuid ' . $uuid);
            }
            $collection
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
                ->exec((string) $this->varnishCheckCommand($uri));
        }

        $collection->taskDrushStack('vendor/bin/drush');
        if ($uri) {
            $collection->uri($uri);
        }
        $collection->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->drush('sset system.maintenance_mode 0');

        return $collection;
    }
}
