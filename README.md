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
            'existing-config' => false,
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

## Multisites / site aliases

Drupal 8 multisites are supported. There are two ways to implement them:

1. Use Drupal's `sites.php`

  This script can parse the site aliases from sites.php, where the keys of the
  `$sites` array are the urls and the values the folders (under the `sites/`
  folder in the web root.

2. Use `properties.yml`:

  You can define you site aliases in `properties.yml` under the `remote` key in
  the same manner: keys are the urls, values the folders. For example:

```
remote:
  aliases:
    example.com: default
    alias1.example.com: alias1
    alias2.example.com: alias2
```

You can read more about the `properties.yml` file in the [Readme of the helpers
package](https://github.com/digipolisgent/robo-digipolis-helpers).

### Multisite settings files

If you want to symlink the settings files of each of your multisite
installations (which is recommended, since the alternative would be to have them
in your repository), you'll have to add those symlinks to the `properties.yml`.
Same goes for the files directories.

Using the example above, you'll have to add this to your properties.yml:

```
remote:
  symlinks:
    # Settings file symlinks.
    - '${remote.configdir}/alias1/settings.php:${remote.webdir}/sites/alias1/settings.php'
    - '${remote.configdir}/alias2/settings.php:${remote.webdir}/sites/alias2/settings.php'
    # Files directories symlinks.
    - '${remote.filesdir}/alias1/public:${remote.webdir}/sites/alias1/files'
    - '${remote.filesdir}/alias2/public:${remote.webdir}/sites/alias2/files'
```

### Rollbacks on multisites

Since backups are made at the beginning of the multisite deploy, every site of
the multisite is rolled back whenever there is an error in the deploy process,
even when the error happens during the deploy of the first alias. So in the
example above, at the beginning of the deploy, a database backup is made for
`default`, `alias1` and `alias2`. If the process fails during the deploy of
`default`, the rollback process will restore the database backups of `default`,
`alias1` and `alias2`. Some goes for when it fails during the deploy of
`alias2`. Deploys are done in the order the aliases are defined.

### Adding a new site to an existing setup

When a new site is added to an existing installation, make sure all settings
files and folders are in place (just like you would with a normal first time
installation). The site that was newly added will go through the installation
process, the sites that already existed will be left alone. This means you can't
update one subsite, while adding another at the same time. You'll have to do
that in two separate deploys.
