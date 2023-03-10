<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use Robo\Collection\CollectionBuilder;
use Symfony\Component\EventDispatcher\GenericEvent;

class InstallDrupal8Handler extends Drupal8Handler
{
    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $profile = $event->getArgument('profile');
        $options = $event->getArgument('options');
        $aliases = $event->getArgument('aliases');
        $this->readProperties();
        $uri = $options['uri'] ?: null;

        // Random string fallback for the account password.
        $options['account-pass'] = $this->getAccountPassword($options['account-pass'] ?: null);

        $collection = $this->collectionBuilder();
        $this->addDropDatabaseRollbackTask($collection, $uri);
        $this->addDrushInstallTask($collection, $options, $aliases, $profile, $uri);
        $this->addConfigImportTask($collection, $options, $uri, $aliases);
        $this->addLocaleUpdateTask($collection, $uri);
        $this->addVarnishCheckTask($collection, $uri);
        $this->addDisableMaintenanceModeTask($collection, $uri);

        return $collection;
    }

    protected function addDropDatabaseRollbackTask(CollectionBuilder $collection, ?string $uri = null)
    {
        // Installations can start with existing databases. Don't drop them if
        // they did.
        if (!$this->taskExec('[[ $(' . $this->usersTableCheckCommand('vendor/bin/drush', $uri) . ') ]]')->run()->wasSuccessful()) {
            $drop = $this->taskDrushStack('vendor/bin/drush');
            if ($uri) {
                $drop->uri($uri);
            }
            $drop->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
                ->drush('sql-drop');

            $collection->rollback($drop);
        }
    }

    protected function addDrushInstallTask(
      CollectionBuilder $collection,
      array $options,
      array $aliases,
      string $profile,
      ?string $uri = null
    ) {
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $subfolder = $uri ? $aliases[$uri] : 'default';
        $site_path = $app_root . '/sites/' . $subfolder;
        $databaseConfig = $this->getDatabaseConfig($aliases, $uri);
        $dbUrl = $this->getDatabaseUrl($databaseConfig);

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

        if (isset($databaseConfig['username']) && !empty($databaseConfig['username'])) {
            $drushInstall->dbSu($databaseConfig['username']);
        }
        if (isset($databaseConfig['password']) && !empty($databaseConfig['password'])) {
            $drushInstall->dbSuPw($databaseConfig['password']);
        }

        if (!empty($databaseConfig['prefix'])) {
            $drushInstall->dbPrefix($databaseConfig['prefix']);
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
    }
}
