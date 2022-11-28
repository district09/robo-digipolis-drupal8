<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\Robo\Drupal8\Traits\AliasesHelper;
use DigipolisGent\Robo\Helpers\EventHandler\DefaultHandler\SettingsHandler;
use Symfony\Component\EventDispatcher\GenericEvent;

class RemoteSettingsHandler extends SettingsHandler
{
    use AliasesHelper;
    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $this->readProperties();
        $remoteSettings = $this->getConfig()->get('remote');
        return [
          'aliases' => $this->getAliases($remoteSettings),
        ];
    }
}
