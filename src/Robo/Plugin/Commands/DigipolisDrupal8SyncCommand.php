<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Tasks;

class DigipolisDrupal8SyncCommand extends Tasks implements CustomEventAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\DigipolisHelpersSyncCommandUtilities;

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
     *
     * @option files Whether or not to sync the files between environments. When
     *   both this and the "data" option is omitted, both are synced.
     * @option data Whether or not to sync the database between environments.
     *   When both this and the "files" option is omitted, both are synced.
     * @option rsync Whether or not to use rsync to sync the files between the
     *   environments. Defaults to true. When set to false, a tar is created of
     *   the files, downloaded to the machine this command executes on, and then
     *   uploaded and extracted on the other environment.
     *
     * @command digipolis:sync-drupal8
     */
    public function digipolisSyncDrupal8(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default',
        $opts = ['files' => false, 'data' => false, 'rsync' => true]
    ) {
        return $this->sync(
            $sourceUser,
            $sourceHost,
            $sourceKeyFile,
            $destinationUser,
            $destinationHost,
            $destinationKeyFile,
            $sourceApp,
            $destinationApp,
            $opts
        );
    }
}
