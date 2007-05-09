<?php

/*
+---------------------------------------------------------------------------+
| Openads v2.3                                                              |
| ============                                                              |
|                                                                           |
| Copyright (c) 2003-2007 Openads Limited                                   |
| For contact details, see: http://www.openads.org/                         |
|                                                                           |
| Copyright (c) 2000-2003 the phpAdsNew developers                          |
| For contact details, see: http://www.phpadsnew.com/                       |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

require_once MAX_PATH . '/lib/OA/Dal.php';
require_once(MAX_PATH . '/lib/max/other/lib-db.inc.php');
require_once(MAX_PATH . '/www/admin/lib-banner.inc.php');
require_once MAX_PATH . '/lib/max/Plugin.php';
require_once MAX_PATH . '/lib/max/Delivery/remotehost.php';

// Initialize the client info to enable client targeting options
MAX_remotehostProxyLookup();
MAX_remotehostReverseLookup();
MAX_remotehostSetClientInfo();
MAX_remotehostSetGeoInfo();

/**
 * @todo I believe the following is unnecessary with the "MAX_remotehostSetGeoInfo()" above
 * However the isAllowed() methods for the Geo-Plugins will have to be updated
 */

// Register the geotargeting information if necessary
if (!isset($GLOBALS['_MAX']['GEO_DATA'])) {
    $oGeoPlugin = MAX_Plugin::factoryPluginByModuleConfig('geotargeting');
    // Get geotargeting info
    if ($oGeoPlugin) {
        $conf['geotargeting']['type'] = $oGeoPlugin->name;
    	// Set the geotargeting IP to the fixed test address
        // (IP Address used to determine which (if any) MaxMind databases are installed)
    	$GLOBALS['_MAX']['GEO_IP'] = '24.24.24.24';
    	// Get the geotargeting config
    	$geoTargetingType = $conf['geotargeting']['type'];
        $pluginConfig = $oGeoPlugin->getConfig();
        if (!empty($pluginConfig)) {
            // Overwrite
            foreach($pluginConfig as $configKey => $configValue) {
                $GLOBALS['_MAX']['CONF']['geotargeting'][$configKey] = $configValue;
            }
        }
    	// Look up the Geotargeting data
        $GLOBALS['_MAX']['GEO_DATA'] = $oGeoPlugin->getInfo();
    }
}

function MAX_AclAdjust($acl, $action)
{
    $count = count($acl);
    if (!empty($action['new']) && !empty($_REQUEST['type'])) {
        // Initialise this plugin to see if there is a default comparison
        list($package, $name) = explode(':', $_REQUEST['type']);
        $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
        $defaultComparison = $deliveryLimitationPlugin->defaultComparison;

        $acl[$count] = array(
            'comparison' => $defaultComparison,
            'data' => '',
            'executionorder' => $count,
            'logical' => isset($acl[$count - 1]) ? $acl[$count - 1]['logical']:'',
            'type' => $_REQUEST['type']
        );
    }
    if (!empty($action['del'])) {
        $idx = key($action['del']);
        unset($acl[$idx]);
        for ($i = $idx+1; $i<$count; $i++) {
            $acl[$i]['executionorder']--;
        }
    }
    if (!empty($action['down'])) {
        $idx = key($action['down']);
        $acl[$idx]['executionorder']++;
        $acl[$idx+1]['executionorder']--;
    }

    if (!empty($action['up'])) {
        $idx = key($action['up']);
        $acl[$idx]['executionorder']--;
        $acl[$idx-1]['executionorder']++;
    }

    if (!empty($action['clear']) && $action['clear'] == 'true') {
        $acl = array();
    }

    if (!empty($acl)) {
        // ReIndex the acl array
        $copy = array();
        foreach ($acl as $idx => $value) {
            $copy[$value['executionorder']] = $value;
        }
        ksort($copy);
        $acl = $copy;
    }
    return $acl;
}

function MAX_AclSave($acls, $aEntities, $page = false)
{
    $conf = $GLOBALS['_MAX']['CONF'];
    $oDbh = &OA_DB::singleton();
    if ($page === false) {
        $page = basename($_SERVER['PHP_SELF']);
    }

    if ('banner-acl.php' == $page) {
        $table = 'banners';
        $aclsTable = 'acls';
        $fieldId = 'bannerid';
    }
    else if ('channel-acl.php' == $page) {
        $table = 'channel';
        $aclsTable = 'acls_channel';
        $fieldId = 'channelid';
    }
    else {
        return false;
    }

    $sLimitation = MAX_AclGetCompiled($acls, $page);
    // TODO: it should be done inside plugins instead, there is no need to slash the data
    $sLimitation = (!get_magic_quotes_runtime()) ? stripslashes($sLimitation) : $sLimitation;

    $aclsObjectId = $aEntities[$fieldId];
    $doObject = OA_Dal::staticGetDO($table, $aclsObjectId);

    if ($sLimitation == $doObject->compiledlimitation) {
        return true;
    }

    // There was a change to the ACL so update the necessary tables

    $doAclsObject = OA_Dal::factoryDO($aclsTable);
    $doAclsObject->$fieldId = $aclsObjectId;
    $doAclsObject->delete();

    if (!empty($acls)) {
        foreach ($acls as $acl) {
            list($package, $name) = explode(':', $acl['type']);
            $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
            $deliveryLimitationPlugin->init($acl);
            $doAclsObject = OA_Dal::factoryDO($aclsTable);
            $doAclsObject->$fieldId = $aclsObjectId;
            $doAclsObject->logical = $acl['logical'];
            $doAclsObject->type = $acl['type'];
            $doAclsObject->data = $deliveryLimitationPlugin->getData();
            $doAclsObject->comparison = $acl['comparison'];
            $doAclsObject->executionorder = $acl['executionorder'];
            $doAclsObject->insert();
        }
    }

    $doObject = OA_Dal::factoryDO($table);
    $doObject->$fieldId = $aclsObjectId;
    $doObject->acl_plugins = MAX_AclGetPlugins($acls, $page);
    $doObject->acls_updated = $now = OA::getNow();
    $doObject->compiledlimitation = $sLimitation;
    $doObject->update();

    // When a channel limitation changes - All banners with this channel must be re-learnt
    if ($page == 'channel-acl.php') {
        $affected_ads = array();
        $success = false;

        $query = "
            SELECT
                DISTINCT(bannerid)
            FROM
                {$conf['table']['prefix']}{$conf['table']['acls']}
            WHERE
                type = 'Site:Channel'
              AND (data = '{$aclsObjectId}' OR data LIKE '%,{$aclsObjectId}' OR data LIKE '%,{$aclsObjectId},%' OR data LIKE '{$aclsObjectId},%')
        ";
        $res = $oDbh->query($query);
        if (PEAR::isError($res)) {
            return $res;
        }
        while ($row = $res->fetchRow()) {
            $affected_ads[] = $row['bannerid'];
        }
        if (!empty($affected_ads)) {
            $query = "
                UPDATE
                    {$conf['table']['prefix']}{$conf['table']['banners']}
                SET
                    acls_updated = '{$now}'
                WHERE
                    bannerid IN (" . $oDbh->escape(implode(',', $affected_ads)) . ")
            ";
            $res = $oDb->exec($query);
            if (PEAR::isError($res)) {
                return $res;
            }
        }
    }
    return true;
}

function MAX_AclGetCompiled($acls) {
    if (empty($acls)) {
        return "true";
    } else {
        foreach ($acls as $order => $acl) {
            list($package, $name) = explode(':', $acl['type']);

            $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
            $deliveryLimitationPlugin->init($acl);

            $compiled = $deliveryLimitationPlugin->compile();
            if (!empty($compiledAcls)) {
                $compiledAcls[] = $acl['logical'];
            }
            $compiledAcls[] = $compiled;
        }
        return implode(' ', $compiledAcls);
    }
}

function MAX_AclGetPlugins($acls) {
    if (empty($acls)) {
        return '';
    }
    $acl_plugins = array();
    foreach ($acls as $order => $acl) {
        list($package, $name) = explode(':', $acl['type']);
        $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
        $deliveryLimitationPlugin->init($acl);

        if (!in_array($acl['type'], $acl_plugins)) {
            $acl_plugins[] = $acl['type'];
        }
    }
    return implode(',', $acl_plugins);
}

function MAX_AclValidate($page, $aParams) {
    $conf =& $GLOBALS['_MAX']['CONF'];
    $oDbh = &OA_DB::singleton();
    if (PEAR::isError($oDbh)) {
        // FIXME: What am I supposed to do here???
    }
    switch($page) {
        case 'banner-acl.php':
            $query_existing = "SELECT compiledlimitation, acl_plugins FROM {$conf['table']['prefix']}{$conf['table']['banners']} WHERE bannerid = ". $oDbh->quote($aParams['bannerid'], 'integer');
            $query_acls = "SELECT bannerid as id, logical, type, comparison, data, executionorder FROM {$conf['table']['prefix']}{$conf['table']['acls']} WHERE bannerid = ". $oDbh->quote($aParams['bannerid'], 'integer') ." ORDER BY executionorder";
        break;
        case 'channel-acl.php':
            $query_existing = "SELECT compiledlimitation, acl_plugins FROM {$conf['table']['prefix']}{$conf['table']['channel']} WHERE channelid = ". $oDbh->quote($aParams['channelid'], 'integer');
            $query_acls = "SELECT channelid as id, logical, type, comparison, data, executionorder FROM {$conf['table']['prefix']}{$conf['table']['acls_channel']} WHERE channelid = ". $oDbh->quote($aParams['channelid'], 'integer') ." ORDER BY executionorder";
        break;
    }
    list($compiledLimitation, $acl_plugins) = $oDbh->queryRow($query_existing, null, MDB2_FETCHMODE_ORDERED);
    $compiledLimitation = stripslashes($compiledLimitation);

    $acls = array();
    $res = $oDbh->query($query_acls);
    while ($row = $res->fetchRow()) {
        list($package, $name) = explode(':', $row['type']);
        $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
        $deliveryLimitationPlugin->init($row);
        if ($deliveryLimitationPlugin->isAllowed($page)) {
            $acls[$row['executionorder']] = $row;
        }
    }

    $newCompiledLimitation = stripslashes(MAX_AclGetCompiled($acls));
    $newAclPlugins = MAX_AclGetPlugins($acls);

    if (($newCompiledLimitation == $compiledLimitation) && ($newAclPlugins == $acl_plugins)) {
        return true;
    } elseif ((empty($newAclPlugins) && empty($newAclPlugins)) && ($newCompiledLimitation == 'true' && empty($compiledLimitation))) {
        return true;
    } else {
        return false;
    }
}

function MAX_AclCopy($page, $from, $to) {
    $oDbh = &OA_DB::singleton();
    $conf =& $GLOBALS['_MAX']['CONF'];

    switch ($page) {
        case 'channel-acl.php' :
            echo "Not implemented";
            break;
        default:
            // Delete old limitations
            $query = "
                DELETE FROM
                      {$conf['table']['prefix']}{$conf['table']['acls']}
                WHERE
                    bannerid = ". $oDbh->quote($to, 'integer');
            $res = $oDbh->exec($query);
            if (PEAR::isError($res)) {
                return $res;
            }

            // Copy ACLs
            $query = "
                INSERT INTO {$conf['table']['prefix']}{$conf['table']['acls']}
                    SELECT
                        ". $oDbh->quote($to, 'integer') .", logical, type, comparison, data, executionorder
                    FROM
                        {$conf['table']['prefix']}{$conf['table']['acls']}
                    WHERE
                        bannerid= ". $oDbh->quote($from, 'integer') ."
                    ORDER BY executionorder
            ";
            $res = $oDbh->exec($query);
            if (PEAR::isError($res)) {
                return $res;
            }

            // Copy compiledlimitation
            $doBannersFrom = OA_Dal::staticGetDO('banners', $from);
            $doBannersTo = OA_DAL::staticGetDO('banners', $to);
            $doBannersTo->compiledlimitation = $doBannersFrom->compiledlimitation;
            $doBannersTo->acl_plugins = $doBannersFrom->acl_plugins;
            $doBannersTo->block = $doBannersFrom->block;
            $doBannersTo->capping = $doBannersFrom->capping;
            $doBannersTo->session_capping = $doBannersFrom->session_capping;
            return $doBannersTo->update();
    }
}

/**
 * This function iterates over all the ACLs in the system, and recompiles the compiledlimitation
 * string across all banners and channels
 *
 */
function MAX_AclReCompileAll()
{
    $conf =& $GLOBALS['_MAX']['CONF'];
    // Since we need to do almost identical things for both banner and channel ACLs...
    $actions = array(
        'banners'   => array(
            'table'     => 'acls',
            'id_field'  => 'bannerid',
            'page'      => 'banner-acl.php',
            'compiled_table' => $conf['table']['banners'],
        ),
        'channels'  => array(
            'table'     => 'acls_channel',
            'id_field'  => 'channelid',
            'page'      => 'channel-acl.php',
            'compiled_table' => $conf['table']['channel'],
        ),
    );
    foreach ($actions as $action) {
        $recompile = array();
        $acls = array();
        $query = "
            SELECT
                *
            FROM
                {$conf['table']['prefix']}{$action['table']}
            ORDER BY {$action['id_field']}, executionorder";
        $res = phpAds_dbQuery($query);
        if ($res !== false) {
            while ($row = phpAds_dbFetchArray($res)) {
                list($package, $name) = explode(':', $row['type']);
                $deliveryLimitationPlugin = MAX_Plugin::factory('deliveryLimitations', ucfirst($package), ucfirst($name));
                $deliveryLimitationPlugin->init($row);
                if ($deliveryLimitationPlugin->isAllowed($action['page'])) {
                    $acls[$row[$action['id_field']]][$row['executionorder']] = $row;
                }
            }
        }
        // OK so we've updated all the data values, now the hard part, we need to recompile limitations for all banners
        foreach ($acls as $id => $acl) {
            $aEntities = array($action['id_field'] => $id);
            MAX_AclSave($acl, $aEntities, $action['page']);
        }
        // All other banners should have the compiledlimitation set to "true"
        $ids = implode(',', array_keys($acls));
        $query = "UPDATE {$action['compiled_table']} SET compiledlimitation = 'true', acl_plugins = '' WHERE {$action['id_field']} NOT IN ({$ids})";
        phpAds_dbQuery($query);
    }
}

function MAX_aclAStripslashed($aArray)
{
    if (get_magic_quotes_runtime() == 1) {
        return $aArray;
    }

    foreach ($aArray AS $key => $item) {
        if (is_array($item)) {
            $aArray[$key] = MAX_aclAStripslashed($item);
        } else {
            $aArray[$key] = stripslashes($item);
        }
    }
    return $aArray;
}
?>
