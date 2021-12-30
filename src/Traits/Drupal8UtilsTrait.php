<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use Boedah\Robo\Task\Drush\loadTasks as DrushLoadTasks;
use DigipolisGent\CommandBuilder\CommandBuilder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

trait Drupal8UtilsTrait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getDrupal8UtilsTraitDependencies()
    {
        return [DrushLoadTasks::class];
    }

    protected $siteInstalled = null;

    protected $siteInstalledTested;

    protected function getSiteUuid($uri = false)
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            $this->say('Could not get site UUID. No webroot found.');
            return false;
        }

        $finder = new Finder();
        $subdir = ($uri ? '/' . $this->parseSiteAliases()[$uri] : '');
        $this->say('Searching for settings.php in ' . $webDir . '/sites' . $subdir . ' and subdirectories.');
        $finder->in($webDir . '/sites' . $subdir)->files()->name('settings.php');
        $config_directories = [];
        $settings = [];
        foreach ($finder as $settingsFile) {
            $app_root = $webDir;
            $site_path = 'sites' . $subdir;
            $this->say('Loading settings from ' . $settingsFile->getRealpath() . '.');
            include $settingsFile->getRealpath();
            break;
        }
        if (!isset($settings['config_sync_directory']) && !isset($config_directories['sync'])) {
            $this->say('Could not get site UUID. No sync directory set.');
            return false;
        }
        $sync = $webDir . '/' . ($settings['config_sync_directory'] ?? $config_directories['sync']) . '/system.site.yml';
        $this->say('Parsing site UUID from ' . $sync . '.');
        $siteSettings = Yaml::parse(file_get_contents($sync));
        return $siteSettings['uuid'];
    }

    /**
     * Get the command to check if the page_cache module and varnish are not
     * enabled simultaneously. Command differs for Drush 9 vs Drush 8.
     *
     * @return string
     */
    protected function varnishCheckCommand($uri = '')
    {
        $this->readProperties();

        $drushVersion = $this->taskDrushStack()
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'));
        if ($uri) {
            $drushVersion->uri($uri);
        }
        $drushVersion = $drushVersion->getVersion();
        if (version_compare($drushVersion, '9.0', '<')) {
            return 'bash -c "[[ '
                . '\'$ENABLED_MODULES\' =~ \((varnish|purge)\) '
                . '&& \'$ENABLED_MODULES\' =~ \(page_cache\)'
                . ' ]]" && exit 1 || :';
        }
        return 'bash -c "[[ '
            . '\'$ENABLED_MODULES\' =~ (varnish|purge) '
            . '&& \'$ENABLED_MODULES\' =~ page_cache'
            . ' ]]" && exit 1 || :';
    }

    /**
     * Get the command to check if the users table exists.
     *
     * @param string $drush
     *   Path to the drush executable.
     * @param string $uri
     *   The uri (used for multisite installations, optional).
     *
     * @return string
     */
    protected function usersTableCheckCommand($drush, $uri = '')
    {
        $command = CommandBuilder::create($drush);
        if ($uri) {
            $command->addOption('uri', $uri);
        }
        return $command->addArgument('sql-query')->addArgument('SHOW TABLES')->pipeOutputTo('grep')->addArgument('users');
    }

    /**
     * Get the command to check if a module is enabled. Command differs for
     * Drush 9 vs Drush 8.
     *
     * @return string
     */
    protected function checkModuleCommand($module, $remote = null, $uri = false)
    {
        $this->readProperties();

        $drushVersion = $this->taskDrushStack();
        if ($uri) {
            $drushVersion->uri($uri);
        }
        $drushVersion = $drushVersion->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->getVersion();
        $webroot = $remote ? $remote['currentdir'] : $this->getConfig()->get('digipolis.root.web');
        $projectroot = $remote ? $remote['currentdir'] . '/..' : $this->getConfig()->get('digipolis.root.project');
        $drushBase = CommandBuilder::create('vendor/bin/drush')
            ->addFlag('r', $webroot);
        if ($uri) {
            $drushBase->addOption('uri', $uri);
        }
        return CommandBuilder::create('cd')
            ->addFlag('P')
            ->addArgument($projectroot)
            ->onSuccess((clone $drushBase)->addArgument('cr'))
            ->onSuccess((clone $drushBase)->addArgument('cc')->addArgument('drush'))
            ->onSuccess((clone $drushBase)->addArgument('pml')->addOption('fields', 'name')->addOption('status', 'enabled')->addOption('type', 'module')->addOption('format', 'list'))
            ->pipeOutputTo('grep')->addRawArgument(version_compare($drushVersion, '9.0', '<') ? '"(' . $module . ')"' : '"^' . $module . '$"');
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

    protected function parseSiteAliases($remote = null) {
        // Allow having aliases defined in properties.yml. If non are set, try
        // parsing them from sites.php
        $this->readProperties();
        $remote = $remote ?? $this->getConfig()->get('remote');
        $aliases = isset($remote['aliases']) ? $remote['aliases'] : [];
        $sitesFile = $this->getConfig()->get('digipolis.root.web', false) . '/sites/sites.php';
        if (!file_exists($sitesFile)) {
            return $aliases;
        }
        include $sitesFile;
        $aliases = isset($sites) && is_array($sites) ? ($aliases + $sites) : $aliases;
        /**
         * Multiple aliases can map to the same folder. We don't want to execute
         * every action for the same folder twice. For consistency, we use the
         * url of the first occurrence of the folder name. Since array_unique()
         * sorts the array before filtering it, we can't use that. Using the
         * array_flip() function, a value has several occurrences, the latest
         * key will be used as its value, and all others will be lost. So to get
         * the first occurence, we reverse the array, flip it twice, and reverse
         * it again, so we have a unique array in the order we expect it to be.
         */
        return array_reverse(
            array_flip(
                array_flip(
                    array_reverse($aliases, true)
                )
            ),
            true
        );
    }
}
