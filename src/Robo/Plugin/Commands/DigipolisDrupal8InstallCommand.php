<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use DigipolisGent\CommandBuilder\CommandBuilder;
use RandomLib\Factory;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;
use SecurityLib\Strength;

class DigipolisDrupal8InstallCommand extends Tasks implements CustomEventAwareInterface, ConfigAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Drupal8\Traits\AliasesHelper;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;

    /**
     * Install the D8 site in the current folder.
     *
     * @param string $profile
     *   The name of the install profile to use.
     * @param array $opts
     *   The options for this command.
     *
     * @option site-name The site name to set during install.
     * @option force Force the installation. This will drop all tables in the
     *   current database.
     * @option config-import Import configuration after installing the site.
     * @option existing-config Install the site from existing configuration.
     *
     * @command digipolis:install-drupal8
     */
    public function digipolisInstallDrupal8(
        $profile = 'standard',
        $opts = [
            'site-name' => 'Drupal',
            'force' => false,
            'config-import' => false,
            'existing-config' => false,
            'account-name' => 'admin',
            'account-mail' => 'admin@example.com',
            'account-pass' => null,
            'uri' => null,
        ]
    ) {
        $this->readProperties();
        return $this->handleTaskEvent(
            'digipolis:install-drupal8',
            [
                'profile' => $profile,
                'options' => $opts,
                'aliases' => $this->getAliases($this->getConfig()->get('remote'))
            ]
        );
    }
}
