<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use Symfony\Component\EventDispatcher\GenericEvent;

class FileBackupConfigHandler extends AbstractTaskEventHandler
{

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        return [
            'file_backup_subdirs' => ['public', 'private'],
            'exclude_from_backup' => ['php', 'js/*', 'css/*', 'styles/*'],
        ];
    }
}
