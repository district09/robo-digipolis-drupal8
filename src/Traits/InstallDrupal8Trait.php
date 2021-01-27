<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use Consolidation\AnnotatedCommand\CommandError;
use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use RandomLib\Factory;
use SecurityLib\Strength;

trait InstallDrupal8Trait
{

    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getInstallDrupal8TraitDependencies()
    {
        return [AbstractDeployCommandTrait::class, Drupal8UtilsTrait::class];
    }



    /**
     * Install the D8 site in the current folder.
     *
     * @param string $profile
     *   The name of the install profile to use.
     * @param array $opts
     *   The options for this command.
     *
     * @option site-name The site name to set during install.
     * @option force Force the installation. This will drop all tables in the
     *   current database.
     * @option config-import Import configuration after installing the site.
     * @option existing-config Install the site from existing configuration.
     */
    public function digipolisInstallDrupal8(
        $profile = 'standard',
        $opts = [
            'site-name' => 'Drupal',
            'force' => false,
            'config-import' => false,
            'existing-config' => false,
            'account-name' => 'admin',
            'account-mail' => 'admin@example.com',
            'account-pass' => null,
            'uri' => null,
        ]
    ) {
        $this->readProperties();
        $app_root = $this->getConfig()->get('digipolis.root.web', false);
        $uri = $opts['uri'] ?: '';
        $subfolder = $uri ? $this->parseSiteAliases()[$uri] : 'default';
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
        if (empty($opts['account-pass'])) {
            $factory = new Factory();
            $opts['account-pass'] = $factory
                ->getGenerator(new Strength(Strength::MEDIUM))
                ->generateString(16);
        }

        $collection = $this->collectionBuilder();
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
            ->siteName($opts['site-name'])
            ->accountName($opts['account-name'])
            ->accountMail($opts['account-mail'])
            ->accountPass('"' . $opts['account-pass'] . '"')
            ->existingConfig($opts['existing-config']);

        if (isset($config['username']) && !empty($config['username'])) {
            $drushInstall->dbSu($config['username']);
        }
        if (isset($config['password']) && !empty($config['password'])) {
            $drushInstall->dbSuPw($config['password']);
        }

        if (!empty($config['prefix'])) {
            $drushInstall->dbPrefix($config['prefix']);
        }

        if ($opts['force']) {
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

        if ($opts['config-import']) {
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
            if ($opts['uri']) {
                $listModules->addOption('uri', $opts['uri']);
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

    protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
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
}
