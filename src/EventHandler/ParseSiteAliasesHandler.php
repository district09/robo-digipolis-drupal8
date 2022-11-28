<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class ParseSiteAliasesHandler extends AbstractTaskEventHandler implements ConfigAwareInterface
{

    use DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \DigipolisGent\Robo\Task\Deploy\Tasks;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Drupal8\Traits\AliasesHelper;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $remoteSettings = $event->getArgument('remoteSettings');
        // Allow having aliases defined in properties.yml. If non are set, try
        // parsing them from sites.php
        $this->readProperties();
        $remoteSettings = $remoteSettings ?? $this->getConfig()->get('remote');
        return $this->getAliases($remoteSettings);
    }
}
