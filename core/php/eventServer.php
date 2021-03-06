<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";
require_once dirname(__FILE__) . "/../class/apiHelper.class.php";

ob_end_clean();


function sse( $data=null){
  if( !is_null( $data ) ) {
	   echo "data:" . json_encode( $data );
     echo "\r\n\r\n";
		 if( @ob_get_level() > 0 ) for( $i=0; $i < @ob_get_level(); $i++ ) @ob_flush();
     @flush();
  }
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ignore_user_abort(true);


$apiKey = init('apiKey');
$eqLogic = eqLogic::byLogicalId($apiKey, 'JeedomConnect');

if (!is_object($eqLogic)) {
  log::add('JeedomConnect', 'debug', "Can't find eqLogic");
  throw new Exception(__("Can't find eqLogic", __FILE__), -32699);
}
$id = rand(0,1000);
log::add('JeedomConnect', 'debug', "eventServer init client #".$id);


$config = $eqLogic->getConfig();
$lastReadTimestamp = time();
$step = 0;

sse( json_encode(array('infos' => array(
  'cmdInfo' => apiHelper::getCmdInfoData($config),
  'scInfo' => apiHelper::getScenarioData($config),
  'objInfo' => apiHelper::getObjectData($config)
  ))) );

while (true) {
  if (connection_aborted() || connection_status() != CONNECTION_NORMAL) {
      log::add('JeedomConnect', 'debug', "eventServer connexion closed for client #".$id);
      die();
  }

  $newConfig = apiHelper::lookForNewConfig(eqLogic::byLogicalId($apiKey, 'JeedomConnect'), $config);
  if ($newConfig != false) {
    $config = $newConfig;
    $result = array(
      'datetime' => time(),
      'result' => array()
    );
    array_push($result['result'], array(
      'name' => 'config::setConfig',
      'option' => $config
    ));
    log::add('JeedomConnect', 'debug', "eventServer send new config : " . json_encode($result));
    sse(json_encode($result));
    sleep(1);
  }

  $events = event::changes($lastReadTimestamp);
  $data = getData($events);

  if (count($data['result']) > 0) {
    //log::add('JeedomConnect', 'debug', "eventServer send ".json_encode($data));
    sse( json_encode($data) );
    $step = 0;
    $lastReadTimestamp = time();
  }
  else {
    $step +=1;
    if ($step == 5) {
      //log::add('JeedomConnect', 'debug', "eventServer heartbeat to #".$id);
      sse( json_encode(array('event' => 'heartbeat')) );
      $step = 0;
    }
  }
  sleep(1);
}


function getData($events) {
  global $eqLogic, $config;
  $infoIds = apiHelper::getInfoCmdList($config);
  $scIds = apiHelper::getScenarioList($config);
  $objIds = apiHelper::getObjectList($config);
  $result = array(
    'datetime' => $events['datetime'],
    'result' => array()
  );

  foreach ($events['result'] as $event) {
    if ($event['name'] == 'jeeObject::summary::update') {
      //if (in_array($event['option']['object_id'], $objIds)) {
        array_push($result['result'], $event);
      //}
    }
    if ($event['name'] == 'scenario::update') {
      if (in_array($event['option']['scenario_id'], $scIds)) {
        array_push($result['result'], $event);
      }
    }
    if ($event['name'] == 'cmd::update') {
      if (in_array($event['option']['cmd_id'], $infoIds) ) {
        array_push($result['result'], $event);
      }
    }
  }
  return $result;
}
