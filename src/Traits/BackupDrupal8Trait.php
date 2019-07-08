<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;

trait BackupDrupal8Trait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getBuildDrupal8TraitDependencies()
    {
        return [AbstractDeployCommandTrait::class, Drupal8UtilsTrait::class];
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
    public function digipolisBackupDrupal8(
        $host,
        $user,
        $keyFile,
        $opts = ['app' => 'default', 'files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);
        $auth = new KeyFile($user, $keyFile);
        return $this->backupTask($host, $auth, $remote, $opts);
    }
}
