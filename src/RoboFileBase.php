<?php

namespace DigipolisGent\Robo\Drupal8;

use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\Finder\Finder;

class RoboFileBase extends \Robo\Tasks implements DigipolisPropertiesAwareInterface, ConfigAwareInterface
{
    use \DigipolisGent\Robo\Task\DrupalConsole\loadTasks;
    use \DigipolisGent\Robo\Task\Package\Drupal8\loadTasks;
    use \DigipolisGent\Robo\Task\Package\Commands\PackageProject;
    use \DigipolisGent\Robo\Task\General\loadTasks;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Robo\Common\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\Deploy\Traits\SshTrait;
    use \DigipolisGent\Robo\Task\Deploy\Traits\ScpTrait;
    use \Robo\Task\Base\loadTasks;

    /**
     * Stores the request time.
     *
     * @var int
     */
    protected $time;

    /**
     * Create a RoboFileBase instance.
     */
    public function __construct()
    {
        $this->time = time();
    }

    /**
     * Build a Drupal 8 site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory to deploy to.
     * @option site-name The Drupal site name in case we need to install it.
     * @option profile The machine name of the profile we need to use when
     *   installing.
     * @options force-install Force a new isntallation of the Drupal8 site. This
     *   will drop all tables in the current database.
     * @option config-import Import configuration after updating the site.
     * @option worker The IP of the worker server. Defaults to the first server
     *   given in the arguments.
     *
     * @usage --app=myapp 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisDeployDrupal8(
        array $arguments,
        $opts = [
            'app' => 'default',
            'site-name' => 'Drupal',
            'profile' => 'standard',
            'force-install' => false,
            'config-import' => false,
            'worker' => null,
        ]
    ) {
        $archive = $this->time . '.tar.gz';
        $build = $this->digipolisBuildDrupal8($archive);
        $privateKeyFile = array_pop($arguments);
        $user = array_pop($arguments);
        $servers = $arguments;
        $worker = is_null($opts['worker']) ? reset($servers) : $opts['worker'];
        $remote = $this->getRemoteSettings($servers, $user, $privateKeyFile, $opts['app']);
        $releaseDir = $remote['releasesdir'] . '/' . $this->time;
        $auth = new KeyFile($user, $privateKeyFile);
        $currentProjectRoot = $remote['currentdir'] . '/..';

        $collection = $this->collectionBuilder();
        $collection->addTask($build);
        // Create a backup, and a rollback if a Drupal 8 site is present.
        $status = $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('vendor/bin/drupal site:status')
            ->run()
            ->wasSuccessful();
        if ($status) {
            $collection->addTask($this->digipolisBackupDrupal8($worker, $user, $privateKeyFile, $opts));
            $collection->rollback(
                $this->digipolisRestoreBackupDrupal8(
                    $worker,
                    $user,
                    $privateKeyFile,
                    $opts + ['timestamp' => $this->time]
                )
            );
            // Switch the current symlink to the previous release.
            $collection->rollback(
                $this->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->exec(
                        'vendor/bin/robo digipolis:switch-previous '
                        . $remote['releasesdir']
                        . ' ' . $remote['currentdir']
                    )
            );
        }
        foreach ($servers as $server) {
            $collection
                ->taskPushPackage($server, $auth)
                    ->destinationFolder($releaseDir)
                    ->package($archive)
                ->taskSsh($server, $auth)
                    ->exec('rm -rf ' . $remote['webdir'] . '/sites/default/files');
            foreach ($remote['symlinks'] as $link) {
                $collection->exec('ln -s -T -f ' . str_replace(':', ' ', $link));
            }
        }
        $collection->addTask($this->digipolisInitDrupal8Remote($worker, $user, $privateKeyFile, $opts));
        $clearOpcache = 'vendor/bin/drupal digipolis:clear-op-cache ' . $remote['opcache']['env'];
        if (isset($remote['opcache'])) {
            if ( isset($remote['opcache']['host'])) {
                $clearOpcache .= ' --host=' . $remote['opcache']['host'];
            }
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec($clearOpcache);
        }
        $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['releasesdir'])
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['backupsdir']);
        return $collection;
    }

    /**
     * Switch the current release symlink to the previous release.
     *
     * @param string $releasesDir
     *   Path to the folder containing all releases.
     * @param string $currentSymlink
     *   Path to the current release symlink.
     */
    public function digipolisSwitchPrevious($releasesDir, $currentSymlink)
    {
        $finder = new Finder();
        // Get all releases.
        $releases = iterator_to_array(
            $finder
                ->directories()
                ->in($releasesDir)
                ->sortByName()
                ->depth(0)
                ->getIterator()
        );
        // Last element is the current release.
        array_pop($releases);
        // Normalize the paths.
        $currentDir = realpath($currentSymlink);
        $releasesDir = realpath($releasesDir);
        // Get the right folder within the release dir to symlink.
        $relativeWebDir = substr($currentDir, 0, strlen($releasesDir));
        $previous = end($releases)->getRealPath() . $relativeWebDir;

        return $this->taskExec('ln -s -T -f ' . $previous . ' ' . $currentSymlink)
            ->run();
    }

    /**
     * Build a Drupal 8 site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @usage test.tar.gz
     */
    public function digipolisBuildDrupal8($archivename = null)
    {
        $archive = is_null($archivename) ? $this->time . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskThemesCompileDrupal8()
            ->taskThemesCleanDrupal8()
            ->taskPackageDrupal8($archive);
        return $collection;
    }

    /**
     * Install or update a drupal 8 remote site.
     *
     * @param string $server
     *   The server to install the site on.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $privateKeyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory in which the drupal site can be found.
     * @option site-name The Drupal site name in case we need to install it.
     * @option profile The machine name of the profile we need to use when
     *   installing.
     * @options force-install Force a new isntallation of the Drupal8 site. This
     *   will drop all tables in the current database.
     * @option config-import Import configuration after updating the site.
     *
     * @usage --app=myapp --profile=myprofile --site-name='My D8 Site' 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisInitDrupal8Remote(
        $server,
        $user,
        $privateKeyFile,
        $opts = [
            'app' => 'default',
            'site-name' => 'Drupal',
            'profile' => 'standard',
            'force-install' => false,
            'config-import' => false,
        ]
    ) {
        $remote = $this->getRemoteSettings($server, $user, $privateKeyFile, $opts['app']);
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $update = 'vendor/bin/robo digipolis:update-drupal8';
        $auth = new KeyFile($user, $privateKeyFile);
        $status = $this->taskSsh($server, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('vendor/bin/drupal site:status')
            ->run()
            ->wasSuccessful();
        $collection = $this->collectionBuilder();
        if ($opts['force-install'] || !$status) {
            $this->say(!$status ? 'Site status failed.' : 'Force install option given.');
            $this->say('Triggering install script.');
            $install = 'vendor/bin/robo digipolis:install-drupal8 '
              . escapeshellarg($opts['profile'])
              . ' --site-name=' . escapeshellarg($opts['site-name'])
              . ($opts['force-install'] ? ' --force' : '' );
            $collection->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Install can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->exec($install);
            return $collection;
        }
        $collection
            ->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Updates can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->exec($update);
        if ($opts['config-import']) {
            $collection->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec('vendor/bin/drupal config:import --directory=../config');
        }
        return $collection;
    }

    /**
     * Executes D8 database updates of the D8 site in the current folder.
     *
     * Executes D8 database updates of the D8 site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     */
    public function digipolisUpdateDrupal8()
    {
        $this->readProperties();
        return $this->taskDrupalConsoleStack('vendor/bin/drupal')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->maintenance()
            ->updateDb()
            ->maintenance(false)
            ->stopOnFail()
            ->run();
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
     */
    public function digipolisInstallDrupal8($profile = 'standard', $opts = ['site-name' => 'Drupal', 'force' => false])
    {
        $this->readProperties();
        $webDir = $this->getConfig()->get('digipolis.root.web', false);
        $app_root = null;
        $site_path = null;
        include_once $webDir . '/sites/default/settings.php';
        $config = $databases['default']['default'];
        $task = $this->taskDrupalConsoleStack('vendor/bin/drupal')
            ->drupalRootDirectory($this->getConfig()->get('digipolis.root.web'))
            ->dbType($config['driver'])
            ->dbHost($config['host'])
            ->dbName($config['database'])
            ->dbUser($config['username'])
            ->dbPass($config['password'])
            ->dbPort($config['port'])
            ->dbPrefix($config['prefix'])
            ->siteName($opts['site-name'])
            ->option('no-interaction');
        if ($opts['force']) {
            $task->option('force');
        }
        $task
            ->siteInstall($profile)
            ->stopOnFail();

        return $task->run();
    }

    /**
     * Sync the database and files between two Drupal 8 sites.
     *
     * @param string $sourceUser
     *   SSH user to connect to the source server.
     * @param string $sourceHost
     *   IP address of the source server.
     * @param string $sourceKeyFile
     *   Private key file to use to connect to the source server.
     * @param string $destinationUser
     *   SSH user to connect to the destination server.
     * @param string $destinationHost
     *   IP address of the destination server.
     * @param string $destinationKeyFile
     *   Private key file to use to connect to the destination server.
     * @param string $sourceApp
     *   The name of the source app we're syncing. Used to determine the
     *   directory to sync.
     * @param string $destinationApp
     *   The name of the destination app we're syncing. Used to determine the
     *   directory to sync to.
     */
    public function digipolisSyncDrupal8(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default'
    ) {
        $collection = $this->collectionBuilder();
        // Create a backup.
        $collection->addTask(
            $this->digipolisBackupDrupal8(
                $sourceHost,
                $sourceUser,
                $sourceKeyFile,
                ['app' => $sourceApp]
            )
        );
        // Download the backup.
        $collection->addTask(
            $this->digipolisDownloadBackupDrupal8(
                $sourceHost,
                $sourceUser,
                $sourceKeyFile,
                ['app' => $sourceApp, 'timestamp' => null]
            )
        );
        // Upload the backup.
        $collection->addTask(
            $this->digipolisUploadBackupDrupal8(
                $destinationHost,
                $destinationUser,
                $destinationKeyFile,
                ['app' => $destinationApp, 'timestamp' => null]
            )
        );
        // Restore the backup.
        $collection->addTask(
            $this->digipolisRestoreBackupDrupal8(
                $destinationHost,
                $destinationUser,
                $destinationKeyFile,
                ['app' => $destinationApp, 'timestamp' => null]
            )
        );
        return $collection;
    }

    /**
     * Create a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're creating the backup for.
     */
    public function digipolisBackupDrupal8($host, $user, $keyFile, $opts = ['app' => 'default'])
    {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);

        $backupDir = $remote['backupsdir'] . '/' . $this->time;
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $auth = new KeyFile($user, $keyFile);

        $dbBackupFile = $this->backupFileName('.sql');
        $dbBackup = 'vendor/bin/robo digipolis:database-backup '
            . '--drupal '
            . '--destination=' . $backupDir . '/' . $dbBackupFile;

        $filesBackupFile = $this->backupFileName('.tar.gz');
        $filesBackup = 'tar -pczhf ' . $backupDir . '/'  . $filesBackupFile
            . ' -C ' . $remote['filesdir'] . ' public private';

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec('mkdir -p ' . $backupDir)
                ->exec($dbBackup)
                ->exec($filesBackup);
        return $collection;
    }

    /**
     * Restore a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're restoring the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisRestoreBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);

        $currentProjectRoot = $remote['currentdir'] . '/..';
        $backupDir = $remote['backupsdir'] . '/' . $this->time;
        $auth = new KeyFile($user, $keyFile);

        $filesBackupFile =  $this->backupFileName('.tar.gz', $opts['timestamp']);
        $dbBackupFile =  $this->backupFileName('.sql.gz', $opts['timestamp']);

        $dbRestore = 'vendor/bin/robo digipolis:database-restore '
              . '--drupal '
              . '--source=' . $backupDir . '/' . $dbBackupFile;
        $collection = $this->collectionBuilder();

        // Restore the files backup.
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($remote['filesdir'], true)
                ->exec('rm -rf public/* private/* public/.??* private/.??')
                ->exec('tar -xkzf ' . $backupDir . '/' . $filesBackupFile);

        // Restore the db backup.
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(60)
                ->exec('vendor/bin/drupal database:drop -y')
                ->exec($dbRestore);
        return $collection;
    }

    /**
     * Download a backup of files (sites/default/files) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're downloading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisDownloadBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);

        $backupDir = $remote['backupsdir'] . '/' . (is_null($opts['timestamp']) ? $this->time : $opts['timestamp']);
        $dbBackupFile = $this->backupFileName('.sql.gz', $opts['timestamp']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $opts['timestamp']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskScp($host, $auth)
                ->get($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->get($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
    }

    /**
     * Upload a backup of files (sites/default/files) and database to a server.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're uploading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupDrupal8
     */
    public function digipolisUploadBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);

        $backupDir = $remote['backupsdir'] . '/' . (is_null($opts['timestamp']) ? $this->time : $opts['timestamp']);
        $dbBackupFile = $this->backupFileName('.sql.gz', $opts['timestamp']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $opts['timestamp']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($host, $auth)
                ->exec('mkdir -p ' . $backupDir)
            ->taskScp($host, $auth)
                ->put($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->put($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
    }

    /**
     * Helper functions to replace tokens in an array.
     *
     * @param string|array $input
     *   The array or string containing the tokens to replace.
     * @param array $replacements
     *   The token replacements.
     *
     * @return string|array
     *   The input with the tokens replaced with their values.
     */
    protected function tokenReplace($input, $replacements)
    {
        if (is_string($input)) {
            return strtr($input, $replacements);
        }
        foreach ($input as &$i) {
            $i = $this->tokenReplace($i, $replacements);
        }
        return $input;
    }

    /**
     * Generate a backup filename based on the given time.
     *
     * @param string $extension
     *   The extension to append to the filename. Must include leading dot.
     * @param int|null $timestamp
     *   The timestamp to generate the backup name from. Defaults to the request
     *   time.
     *
     * @return string
     *   The generated filename.
     */
    protected function backupFileName($extension, $timestamp = null)
    {
        if (is_null($timestamp)) {
            $timestamp = $this->time;
        }
        return $timestamp . '_' . date('Y_m_d_H_i_s', $timestamp) . $extension;
    }

    /**
     * Get the settings from the 'remote' config key, with the tokens replaced.
     *
     * @param string $host
     *   The IP address of the server to get the settings for.
     * @param string $user
     *   The SSH user used to connect to the server.
     * @param string $keyFile
     *   The path to the private key file used to connect to the server.
     * @param string $app
     *   The name of the app these settings apply to.
     * @param string|null $timestamp
     *   The timestamp to use. Defaults to the request time.
     *
     * @return array
     *   The settings for this server and app.
     */
    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $this->readProperties();

        // Set up destination config.
        $replacements = array(
            '[user]' => $user,
            '[private-key]' => $keyFile,
            '[app]' => $app,
            '[time]' => is_null($timestamp) ? $this->time : $timestamp,
        );
        if (is_string($host)) {
            $replacements['[server]'] = $host;
        }
        if (is_array($host)) {
            foreach ($host as $key => $server) {
                $replacements['[server-' . $key . ']'] = $server;
            }
        }
        return $this->tokenReplace($this->getConfig()->get('remote'), $replacements);
    }
}
