<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class IsSiteInstalledHandler extends AbstractTaskEventHandler
{

    use \DigipolisGent\Robo\Task\Deploy\Tasks;
    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;

    protected $siteInstalled = null;

    public function getPriority(): int
    {
        return parent::getPriority() - 100;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        if (!is_null($this->siteInstalled) && !$remoteSettings['aliases']) {
            return $this->siteInstalled;
        }
        if ($remoteSettings['aliases'] && is_array($this->siteInstalled) && count($this->siteInstalled) >= 1) {
            // A site is installed if every single alias is installed.
            return count(array_filter($this->siteInstalled)) === count($this->siteInstalled);
        }
        $aliases = $remoteSettings['aliases'] ?: [0 => false];
        foreach ($aliases as $uri => $alias) {
            $currentWebRoot = $remoteSettings['currentdir'];
            $result = $this->taskSsh($remoteConfig->getHost(), new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile()))
                ->remoteDirectory($currentWebRoot, true)
                ->exec((string) $this->usersTableCheckCommand('../vendor/bin/drush', $uri))
                ->exec('[[ -f ' . escapeshellarg($currentWebRoot . '/sites/' . ($alias ?: 'default') . '/settings.php') . ' ]] || exit 1')
                ->stopOnFail()
                ->timeout(300)
                ->run();
            $this->setSiteInstalled($result->wasSuccessful(), $uri);
        }

        return $remoteSettings['aliases'] ? count(array_filter($this->siteInstalled)) === count($this->siteInstalled) : $this->siteInstalled;
    }

    public function setSiteInstalled($installed, $uri = false)
    {
        if (!$uri) {
            $this->siteInstalled = $installed;
            return;
        }
        if (is_null($this->siteInstalled)) {
            $this->siteInstalled = [];
        }
        $this->siteInstalled[$uri] = $installed;
    }
}
