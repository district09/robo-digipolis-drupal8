<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use DigipolisGent\Robo\Drupal8\EventHandler\BackupRemoteHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\BuildTaskHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\ClearCacheHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\FileBackupConfigHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\InstallDrupal8Handler;
use DigipolisGent\Robo\Drupal8\EventHandler\InstallHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\IsSiteInstalledHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\ParseSiteAliasesHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\RemoteSettingsHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\RestoreBackupFilesLocalHandler;
use DigipolisGent\Robo\Drupal8\EventHandler\UpdateDrupal8Handler;
use DigipolisGent\Robo\Drupal8\EventHandler\UpdateHandler;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;
use Symfony\Component\Finder\Finder;

class DigipolisDrupal8DefaultHooksCommands extends Tasks implements ConfigAwareInterface, CustomEventAwareInterface
{

    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;

    /**
     * @hook on-event digipolis-db-config
     */
    public function defaultDbConfig()
    {
        $this->readProperties();
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        if (!$webDir) {
            return false;
        }
        $settings = $this->getConfig()->get('remote');
        if (!isset($settings['aliases'])) {
            $settings['aliases'] = $this->handleEvent('digipolis-drupal8:parse-site-aliases', ['remoteSettings' => null]);
        }
        $aliases = $settings['aliases'] ?: [0 => false];

        foreach ($aliases as $uri => $alias) {
            $finder = new Finder();
            $subdir = 'sites/' . ($alias ?: 'default');
            $finder->in($webDir . '/' . $subdir)->files()->name('settings.php');
            foreach ($finder as $settingsFile) {
                $app_root = $webDir;
                $site_path = $subdir;
                include $settingsFile->getRealpath();
                break;
            }
            if (!isset($databases['default']['default'])) {
                continue;
            }
            $config = $databases['default']['default'];
            $dbConfig[$alias ?: 'default'] = [
                'type' => $config['driver'],
                'host' => $config['host'],
                'port' => isset($config['port']) ? $config['port'] : '3306',
                'user' => $config['username'],
                'pass' => $config['password'],
                'database' => $config['database'],
                'structureTables' => [
                    'batch',
                    'cache',
                    'cache_*',
                    '*_cache',
                    '*_cache_*',
                    'flood',
                    'search_dataset',
                    'search_index',
                    'search_total',
                    'semaphore',
                    'sessions',
                    'watchdog',
                ],
                'extra' => '--skip-add-locks --no-tablespaces',
            ];
        }
        return $dbConfig ?: false;
    }

    /**
     * Default implementation for the digipolis:restore-backup-files-local task.
     *
     * @hook on-event digipolis:restore-backup-files-local
     */
    public function getRestoreBackupFilesLocalHandler()
    {
        return new RestoreBackupFilesLocalHandler();
    }

    /**
     * Default implementation for the digipolis-drupal8:parse-site-aliases task.
     *
     * @hook on-event digipolis-drupal8:parse-site-aliases
     */
    public function getParseSiteAliasesHandler()
    {
        return new ParseSiteAliasesHandler();
    }

    /**
     * @hook on-event digipolis:file-backup-config
     */
    public function getBackupConfig()
    {
        return new FileBackupConfigHandler();
    }

    /**
     * @hook on-event digipolis:get-remote-settings
     */
    public function getRemoteSettingsHandler()
    {
        return new RemoteSettingsHandler();
    }

    /**
     * @hook on-event digipolis:install
     */
    public function getInstallHandler()
    {
        return new InstallHandler();
    }

    /**
     * @hook on-event digipolis:update
     */
    public function getUpdateHandler()
    {
        return new UpdateHandler();
    }

    /**
     * @hook on-event digipolis:install-drupal8
     */
    public function getInstallDrupal8Handler()
    {
        return new InstallDrupal8Handler();
    }

    /**
     * @hook on-event digipolis:update-drupal8
     */
    public function getUpdateDrupal8Handler()
    {
        return new UpdateDrupal8Handler();
    }

    /**
     * @hook on-event digipolis:is-site-installed
     */
    public function getIsSiteInstalledHandler()
    {
        return new IsSiteInstalledHandler();
    }

    /**
     * @hook on-event digipolis:backup-remote
     */
    public function getBackupRemoteHandler()
    {
        return new BackupRemoteHandler();
    }

    /**
     * @hook on-event digipolis:clear-cache
     */
    public function getClearCacheHandler()
    {
        return new ClearCacheHandler();
    }

    /**
     * @hook on-event digipolis:build-task
     */
    public function getBuildTaskHandler()
    {
        return new BuildTaskHandler();
    }
}
