<?php

namespace DigipolisGent\Robo\Drupal8\Traits;

use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;

trait BuildDrupal8Trait
{

    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getBuildDrupal8TraitDependencies()
    {
        return [AbstractDeployCommandTrait::class, Drupal8UtilsTrait::class];
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
        return $this->buildTask($archivename);
    }
}
