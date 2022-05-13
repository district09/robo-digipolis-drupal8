<?php

namespace DigipolisGent\Robo\Drupal8\Util;

use DigipolisGent\Robo\Helpers\Util\RemoteHelper as RemoteHelperBase;

class RemoteHelper extends RemoteHelperBase
{

    public function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $settings = parent::getRemoteSettings($host, $user, $keyFile, $app, $timestamp);
        $settings['aliases'] = $this->parseSiteAliases($settings);

        return $settings;
    }

    public function parseSiteAliases($remote = null) {
        // Allow having aliases defined in properties.yml. If non are set, try
        // parsing them from sites.php
        $this->propertiesHelper->readProperties();
        $remote = $remote ?? $this->getConfig()->get('remote');
        $aliases = isset($remote['aliases']) ? $remote['aliases'] : [];
        $sitesFile = $this->getConfig()->get('digipolis.root.web', false) . '/sites/sites.php';
        if (!file_exists($sitesFile)) {
            return $aliases;
        }
        include $sitesFile;
        $aliases = isset($sites) && is_array($sites) ? ($aliases + $sites) : $aliases;
        /**
         * Multiple aliases can map to the same folder. We don't want to execute
         * every action for the same folder twice. For consistency, we use the
         * url of the first occurrence of the folder name. Since array_unique()
         * sorts the array before filtering it, we can't use that. Using the
         * array_flip() function, a value has several occurrences, the latest
         * key will be used as its value, and all others will be lost. So to get
         * the first occurence, we reverse the array, flip it twice, and reverse
         * it again, so we have a unique array in the order we expect it to be.
         */
        return array_reverse(
            array_flip(
                array_flip(
                    array_reverse($aliases, true)
                )
            ),
            true
        );
    }
}
