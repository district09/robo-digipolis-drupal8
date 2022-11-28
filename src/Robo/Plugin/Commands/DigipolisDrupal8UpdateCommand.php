<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;

class DigipolisDrupal8UpdateCommand extends Tasks implements CustomEventAwareInterface, ConfigAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Drupal8\Traits\AliasesHelper;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;

    /**
     * Update the D8 site in the current folder.
     *
     * @param array $opts
     *   The options for this command.
     *
     * @option config-import Import configuration after installing the site.
     * @option uri The uri of the site we're updating, primarily used for multi-
     *   site installations.
     *
     * @command digipolis:update-drupal8
     */
    public function digipolisUpdateDrupal8(
        $opts = [
            'config-import' => false,
            'uri' => null,
        ]
    ) {
        $this->readProperties();
        return $this->handleTaskEvent(
            'digipolis:update-drupal8',
            [
                'options' => $opts,
                'aliases' => $this->getAliases($this->getConfig()->get('remote'))
            ]
        );
    }
}
