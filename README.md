# Robo Digipolis Drupal8

[![Latest Stable Version](https://poser.pugx.org/digipolisgent/robo-digipolis-drupal8/v/stable)](https://packagist.org/packages/digipolisgent/robo-digipolis-drupal8)
[![Latest Unstable Version](https://poser.pugx.org/digipolisgent/robo-digipolis-drupal8/v/unstable)](https://packagist.org/packages/digipolisgent/robo-digipolis-drupal8)
[![Total Downloads](https://poser.pugx.org/digipolisgent/robo-digipolis-drupal8/downloads)](https://packagist.org/packages/digipolisgent/robo-digipolis-drupal8)
[![License](https://poser.pugx.org/digipolisgent/robo-digipolis-drupal8/license)](https://packagist.org/packages/digipolisgent/robo-digipolis-drupal8)

[![Build Status](https://travis-ci.org/digipolisgent/robo-digipolis-drupal8.svg?branch=develop)](https://travis-ci.org/digipolisgent/robo-digipolis-drupal8)
[![Maintainability](https://api.codeclimate.com/v1/badges/f3b213f3d449af134290/maintainability)](https://codeclimate.com/github/digipolisgent/robo-digipolis-drupal8/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/f3b213f3d449af134290/test_coverage)](https://codeclimate.com/github/digipolisgent/robo-digipolis-drupal8/test_coverage)
[![PHP 7 ready](https://php7ready.timesplinter.ch/digipolisgent/robo-digipolis-drupal8/develop/badge.svg)](https://travis-ci.org/digipolisgent/robo-digipolis-drupal8)

Used by digipolis, serving as an example.

This package contains a RoboFileBase class that can be used in your own
RoboFile. All commands can be overwritten by overwriting the parent method.

## Example

```php
<?php

use DigipolisGent\Robo\Drupal8\RoboFileBase;

class RoboFile extends RoboFileBase
{
    use \Robo\Task\Base\loadTasks;

    /**
     * @inheritdoc
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
        $collection = parent::digipolisDeployDrupal8($arguments, $opts);
        $collection->taskExec('/usr/bin/custom-post-release-script.sh');
        return $collection;
    }
}

```

## Available commands

Following the example above, these commands will be available:

```bash
digipolis:backup-drupal8           Create a backup of files
(sites/default/files) and database.
digipolis:build-drupal8            Build a Drupal 8 site and package it.
digipolis:clean-dir                Partially clean directories.
digipolis:clear-op-cache           Command digipolis:database-backup.
digipolis:database-backup          Command digipolis:database-backup.
digipolis:database-restore         Command digipolis:database-restore.
digipolis:deploy-drupal8           Build a Drupal 8 site and push it to the
servers.
digipolis:download-backup-drupal8  Download a backup of files
(sites/default/files) and database.
digipolis:init-drupal8-remote      Install or update a drupal 8 remote site.
digipolis:install-drupal8          Install the D8 site in the current folder.
digipolis:package-project          Package a directory into an archive.
digipolis:push-package             Command digipolis:push-package.
digipolis:restore-backup-drupal8   Restore a backup of files
(sites/default/files) and database.
digipolis:sync-drupal8             Sync the database and files between two
Drupal 8 sites.
digipolis:update-drupal8           Executes D8 database updates of the D8 site
in the current folder.
digipolis:upload-backup-drupal8    Upload a backup of files
(sites/default/files) and database to a server.
```
