<?php

namespace DigipolisGent\Robo\Drupal8\Util\TaskFactory;

use DigipolisGent\Robo\Helpers\Util\TaskFactory\Build as BuildBase;
use DigipolisGent\Robo\Helpers\DependencyInjection\PropertiesHelperAwareInterface;
use Robo\Common\BuilderAwareTrait;
use Robo\Contract\BuilderAwareInterface;

class Build extends BuildBase
{
    use \DigipolisGent\Robo\Task\Package\Drupal8\Tasks;

    /**
     * Build a site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @return \Robo\Contract\TaskInterface
     *   The deploy task.
     */
    public function buildTask($archivename = null)
    {
        $this->propertiesHelper->readProperties();
        $archive = is_null($archivename) ? $this->remoteHelper->getTime() . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskThemesCompileDrupal8()
            ->taskThemesCleanDrupal8()
            ->taskPackageDrupal8($archive);
        return $collection;
    }
}
