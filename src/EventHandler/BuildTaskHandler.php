<?php

namespace DigipolisGent\Robo\Drupal8\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\TimeHelper;
use Symfony\Component\EventDispatcher\GenericEvent;

class BuildTaskHandler extends AbstractTaskEventHandler
{
    use \DigipolisGent\Robo\Task\Package\Drupal8\Tasks;
    use \DigipolisGent\Robo\Task\Package\Tasks;

    public function getPriority(): int {
      return parent::getPriority() - 100;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $event->stopPropagation();
        $archiveName = $event->hasArgument('archiveName') ? $event->getArgument('archiveName') : null;
        $archive = is_null($archiveName) ? TimeHelper::getInstance()->getTime() . '.tar.gz' : $archiveName;

        $collection = $this->collectionBuilder();
        $collection
            ->taskThemesCompileDrupal8()
            ->taskThemesCleanDrupal8()
            ->taskPackageDrupal8($archive);
        return $collection;
    }
}
