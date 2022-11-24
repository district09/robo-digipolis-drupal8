<?php

namespace DigipolisGent\Robo\Drupal8\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Tasks;

class DigipolisDrupal8DeployCommand extends Tasks implements CustomEventAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\DigipolisHelpersDeployCommandUtilities;
    use \Boedah\Robo\Task\Drush\loadTasks;

    /**
     * Build a Drupal 8 site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory to deploy to.
     * @option site-name The Drupal site name in case we need to install it.
     * @option profile The machine name of the profile we need to use when
     *   installing.
     * @option force-install Force a new isntallation of the Drupal8 site. This
     *   will drop all tables in the current database.
     * @option config-import Import configuration after updating the site.
     * @option existing-config Import configuration when installing the site.
     * @option worker The IP of the worker server. Defaults to the first server
     *   given in the arguments.
     *
     * @command digipolis:deploy-drupal8
     *
     * @usage --app=myapp 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
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
        return $this->deploy($arguments, $opts);
    }
}
