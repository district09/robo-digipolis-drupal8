<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use DigipolisGent\CommandBuilder\CommandBuilder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

trait Drupal8UtilsTrait
{
    public function getSiteUuid($uri = false)
    {
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            $this->say('Could not get site UUID. No webroot found.');
            return false;
        }
        $roboSettings = $this->getConfig()->get('remote');
        if (!isset($roboSettings['aliases'])) {
            $settings['aliases'] = $this->handleEvent('digipolis-drupal8:parse-site-aliases', ['remoteSettings' => null]);
        }
        $aliases = $settings['aliases'] ?: [0 => false];
        $finder = new Finder();
        $subdir = ($uri ? '/' . $aliases[$uri] : '');
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
    public function varnishCheckCommand($uri = '')
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
    public function usersTableCheckCommand($drush, $uri = '')
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
    public function checkModuleCommand($module, $remote = null, $uri = false)
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
}
