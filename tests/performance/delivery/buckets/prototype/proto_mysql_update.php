<?php

/*
+---------------------------------------------------------------------------+
| OpenX v${RELEASE_MAJOR_MINOR}                                                                |
| =======${RELEASE_MAJOR_MINOR_DOUBLE_UNDERLINE}                                                                |
|                                                                           |
| Copyright (c) 2003-2008 OpenX Limited                                     |
| For contact details, see: http://www.openx.org/                           |
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

require MAX_PATH . '/lib/OA/Dal/Delivery/mysql.php';

// default buckets to log data into
$OA_DEFAULT_BUCKETS = 'data_bucket_impression,data_bucket_impression_country,data_bucket_frequency';
$OA_DEFAULT_RAND = 1000;

/**
 * The mysql bucket (experimental) data access layer for delivery engine.
 * This is a prototype designed to test updates and inserts in mysql in-memory buckets
 *
 * @package    OpenXDal
 * @subpackage Delivery
 * @author     Radek Maciaszek <radek.maciaszek@openx.org>
 */

if(!extension_loaded("runkit") || !RUNKIT_FEATURE_MANIPULATION) {
    echo "Error: runkit module is not loaded";
    exit();
}

if (!function_exists('OA_Dal_Delivery_logAction')) {
    echo "Function doesn't exist!";
    exit;
}

// replace logAction implementation with custom OA_Dal_Delivery_logAction_BucketUpdate
runkit_function_remove('oa_dal_delivery_logaction');
runkit_function_copy('oa_dal_delivery_logaction_bucketupdate', 'oa_dal_delivery_logaction');

/**
 * A function to insert ad requests, ad impressions, ad clicks
 * and tracker clicks into the raw tables. Does NOT work with
 * tracker impressions.
 *
 * @param string  $table        The raw table name to insert into.
 * @param string  $viewerId     The viewer ID.
 * @param integer $adId         The advertisement ID.
 * @param integer $creativeId   The creative ID (currently unused).
 * @param integer $zoneId       The zone ID.
 * @param array   $geotargeting An array holding the viewer's geotargeting info.
 * @param array   $zoneInfo     An array to store information about the URL
 *                              the viewer used to access the page containing the zone.
 * @param array   $userAgentInfo An array to store information about the
 *                               viewer's web browser and operating system.
 * @param integer $maxHttps     An integer to store if the call to OpenXwas
 *                              performed using HTTPS or not.
 */
function OA_Dal_Delivery_logAction_BucketUpdate($table, $viewerId, $adId, $creativeId, $zoneId,
                        $geotargeting, $zoneInfo, $userAgentInfo, $maxHttps)
{
    return OA_log_data_bucket_impression($table, $viewerId, $adId, $creativeId, $zoneId,
                        $geotargeting, $zoneInfo, $userAgentInfo, $maxHttps);
}

function OA_log_data_bucket_impression($table, $viewerId, $adId, $creativeId, $zoneId,
                        $geotargeting, $zoneInfo, $userAgentInfo, $maxHttps)
{
    if ($table != '') {
        // ignore
    }
    if (!empty($_GET['createBuckets'])) {
        require 'mysql_buckets.php';
        $buckets = new OA_Buckets();
        $buckets->createBuckets();
    }

//    $buckets = isset($_GET['buckets']) ? explode($_GET['buckets']) : $GLOBALS['OA_DEFAULT_BUCKETS'];
//    $rand = isset($_GET['rand']) ? $_GET['rand'] : $GLOBALS['OA_DEFAULT_RAND'];
    // todo - take buckets into account
    $rand = 1000;

    $aQuery = array(
        'interval_start' => gmdate('Y-m-d H:00:00'),
        'creative_id' => mt_rand(1, $rand), //$adId,
        'zone_id' => mt_rand(1, $rand), // $zoneId,
    );
    $result = OA_bucket_updateTable('data_bucket_impression', $aQuery);

    $aQuery = array(
        'interval_start' => gmdate('Y-m-d H:00:00'),
        'creative_id' => mt_rand(1, $rand), // $adId,
        'zone_id' => mt_rand(1, $rand), // $zoneId,
        'country' => 'pl',
    );
    $result = OA_bucket_updateTable('data_bucket_impression_country', $aQuery);

    $aQuery = array(
        'campaign_id' => mt_rand(1, $rand), // 1,
        'frequency' => mt_rand(1, $rand), // 1,
    );
    $result = OA_bucket_updateTable('data_bucket_frequency', $aQuery);

    return $result;
}

function OA_bucket_buildUpdateQuery($tableName, $aQuery, $counter)
{
    $query = "UPDATE {$tableName} SET $counter = $counter + 1 WHERE ";
    $and = '';
    foreach ($aQuery as $column => $value) {
        if (!is_integer($value)) {
            $value = "'" . $value . "'";
        }
        $query .= $and . $column . ' = ' . $value;
        $and = ' AND ';
    }
    return $query;
}

function OA_bucket_buildInsertQuery($tableName, $aQuery, $counter)
{
    $insert = "INSERT INTO {$tableName} (";
    $values = 'VALUES (';
    $comma = '';
    foreach ($aQuery as $column => $value) {
        if (!is_integer($value)) {
            $value = "'" . $value . "'";
        }
        $insert .= $comma . $column;
        $values .= $comma . $value;
        $comma = ', ';
    }
    if ($counter) {
        $query = $insert . $comma . $counter . ') ' . $values . ', 1)';
    } else {
        $query = $insert . ') ' . $values . ')';
    }
    return $query;
}

function OA_bucket_affectedRows($result, $database = 'database')
{
    $dbName = ($database == 'rawDatabase') ? 'RAW_DB_LINK' : 'ADMIN_DB_LINK';
    $result = mysql_affected_rows($GLOBALS['_MAX'][$dbName]);
    return $result;
}

function OA_bucket_updateTable($tableName, $aQuery, $counter = 'count')
{
    // triple update/insert/update - for performance reasons
    if ($counter) {
        $updateQuery = OA_bucket_buildUpdateQuery($tableName, $aQuery, $counter);
        $result = OA_Dal_Delivery_query(
            $updateQuery,
            'rawDatabase'
        );
    }
    else {
        $result = true;
    }
    if (!$counter || OA_bucket_affectedRows($result, 'rawDatabase') <= 0) {
        if (!$result) {
            OA_mysqlPrintError('rawDatabase');
        }
        $insertQuery = OA_bucket_buildInsertQuery($tableName, $aQuery, $counter);
        $result = OA_Dal_Delivery_query(
            $insertQuery,
            'rawDatabase'
        );
        if (!$result) {
            OA_mysqlPrintError('rawDatabase');
            $result = OA_Dal_Delivery_query(
                $updateQuery,
                'rawDatabase'
            );
        }
    }
    return (bool)$result;
}

function OA_mysqlPrintError($database = 'database')
{
    $dbName = ($database == 'rawDatabase') ? 'RAW_DB_LINK' : 'ADMIN_DB_LINK';
    echo mysql_error($GLOBALS['_MAX'][$dbName]);
}

?>
