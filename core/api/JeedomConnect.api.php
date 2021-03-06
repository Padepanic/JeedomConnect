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

header('Content-Type: application/json');

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";
require_once dirname(__FILE__) . "/../class/apiHelper.class.php";

$jsonData = file_get_contents("php://input");
log::add('JeedomConnect', 'debug', 'HTTP API received '.$jsonData);
$jsonrpc = new jsonrpc($jsonData);

if ($jsonrpc->getJsonrpc() != '2.0') {
	throw new Exception(__('Requête invalide. Version JSON-RPC invalide : ', __FILE__) . $jsonrpc->getJsonrpc(), -32001);
}

$params = $jsonrpc->getParams();
$method = $jsonrpc->getMethod();

if ($method == 'GEOLOC') {
  $apiKey = $jsonrpc->getId();
} else {
  $apiKey = $params['apiKey'];
}

$eqLogic = eqLogic::byLogicalId($apiKey, 'JeedomConnect');

if (!is_object($eqLogic) && $method != 'GET_PLUGIN_CONFIG') {
  log::add('JeedomConnect', 'debug', "Can't find eqLogic");
  throw new Exception(__("Can't find eqLogic", __FILE__), -32699);
}


switch ($method) {
  case 'GET_PLUGIN_CONFIG':
		$user = user::byHash($params['userHash']);
		if ($user == null) {
			log::add('JeedomConnect', 'debug', "user not valid");
		  throw new Exception(__("User not valid", __FILE__), -32699);
		}
    $jsonrpc->makeSuccess(array(
      'type' => 'PLUGIN_CONFIG',
      'payload' => apiHelper::getPluginConfig()
    ));
    break;
  case 'CONNECT':
    $versionPath = dirname(__FILE__) . '/../../plugin_info/version.json';
    $versionJson = json_decode(file_get_contents($versionPath));
    if ($eqLogic->getConfiguration('deviceId') == '') {
      log::add('JeedomConnect', 'info', "Register new device {$params['deviceName']}");
      $eqLogic->registerDevice($params['deviceId'], $params['deviceName']);
    }
    $eqLogic->registerToken($params['token']);
    //check registered device
    if ($eqLogic->getConfiguration('deviceId') != $params['deviceId']) {
      log::add('JeedomConnect', 'warning', "Authentication failed (invalid device)");
      $jsonrpc->makeSuccess(array( 'type' => 'BAD_DEVICE' ));
      return;
    }

    //check version requierement
    if (version_compare($params['appVersion'], $versionJson->require, "<")) {
      log::add('JeedomConnect', 'warning', "Failed to connect : bad version requierement");
      $jsonrpc->makeSuccess(array( 'type' => 'APP_VERSION_ERROR' ));
      return;
    }
    if (version_compare($versionJson->version, $params['pluginRequire'], "<")) {
      log::add('JeedomConnect', 'warning', "Failed to connect : bad plugin requierement");
      $jsonrpc->makeSuccess(array( 'type' => 'PLUGIN_VERSION_ERROR' ));
      return;
    }

		//check userHash
		$user = \user::byHash($params['userHash']);
		if ($user == null) {
			log::add('JeedomConnect', 'debug', "user not valid");
			throw new Exception(__("User not valid", __FILE__), -32699);
		}
		$eqLogic->setConfiguration('userHash', $params['userHash']);
		$eqLogic->save();
		$config = $eqLogic->getConfig();

    $result = array(
      'type' => 'WELCOME',
      'payload' => array(
        'pluginVersion' => $versionJson->version,
        'configVersion' => $eqLogic->getConfiguration('configVersion'),
        'scenariosEnabled' => $eqLogic->getConfiguration('scenariosEnabled') == '1',
				'pluginConfig' => apiHelper::getPluginConfig(),
				'cmdInfo' => apiHelper::getCmdInfoData($config),
				'scInfo' => apiHelper::getScenarioData($config),
				'objInfo' => apiHelper::getObjectData($config)
      )
    );
    log::add('JeedomConnect', 'debug', 'send '.json_encode($result));
    $jsonrpc->makeSuccess($result);
    break;
	case 'REGISTER_DEVICE':
		$rdk = apiHelper::registerUser($eqLogic, $params['userHash'], $params['rdk']);
		if (!isset($rdk)) {
			log::add('JeedomConnect', 'debug', "user not valid");
			throw new Exception(__("User not valid", __FILE__), -32699);
		}
		$jsonrpc->makeSuccess(array(
      'type' => 'REGISTERED',
      'payload' => array(
				'rdk' => $rdk
      )
    ));
		break;
  case 'GET_CONFIG':
		$result = $eqLogic->getConfig();
		$result['payload']['summaryConfig'] = config::byKey('object:summary');
    $jsonrpc->makeSuccess($result);
    break;
  case 'GET_CMD_INFO':
    $result = array(
	    'type' => 'SET_CMD_INFO',
	    'payload' => apiHelper::getCmdInfoData($eqLogic->getConfig())
	  );
    log::add('JeedomConnect', 'debug', 'Send '.json_encode($result));
    $jsonrpc->makeSuccess($result);
    break;
  case 'GET_SC_INFO':
    $result = array(
	    'type' => 'SET_SC_INFO',
	    'payload' => apiHelper::getScenarioData($eqLogic->getConfig())
	  );
    log::add('JeedomConnect', 'info', 'Send '.json_encode($result));
    $jsonrpc->makeSuccess($result);
    break;
	case 'GET_OBJ_INFO':
    $result = array(
	    'type' => 'SET_OBJ_INFO',
	    'payload' => apiHelper::getObjectData($eqLogic->getConfig())
	  );
    log::add('JeedomConnect', 'info', 'Send objects '.json_encode($result));
    $jsonrpc->makeSuccess($result);
    break;
	case 'GET_INFO':
		$config = $eqLogic->getConfig();
		$result = array(
			'type' => 'SET_INFO',
			'payload' => array(
				'cmds' => apiHelper::getCmdInfoData($config),
				'scenarios' => apiHelper::getScenarioData($config),
				'objects' => apiHelper::getObjectData($config)
			)
		);
		log::add('JeedomConnect', 'info', 'Send info '.json_encode($result));
		$jsonrpc->makeSuccess($result);
		break;
  case 'GET_GEOFENCES':
    $result = apiHelper::getGeofencesData($eqLogic);
    log::add('JeedomConnect', 'info', 'GEOFENCES '.json_encode($result));
    if (count($result['payload']['geofences']) > 0) {
      log::add('JeedomConnect', 'info', 'Send '.json_encode($result));
      $jsonrpc->makeSuccess($result);
    }
    break;
  case 'ADD_GEOFENCE':
    $eqLogic->addGeofenceCmd($params['geofence']);
    $jsonrpc->makeSuccess();
    break;
  case 'REMOVE_GEOFENCE':
    $eqLogic->removeGeofenceCmd($params['geofence']);
    $jsonrpc->makeSuccess();
    break;
  case 'GEOLOC':
  if (array_key_exists('geofence', $params) ) {
    $geofenceCmd = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), 'geofence_' . $params['geofence']['identifier']);
    if (!is_object($geofenceCmd)) {
      log::add('JeedomConnect', 'error', "Can't find geofence command");
      return;
    }
    if ($params['geofence']['action'] == 'ENTER') {
      if ($geofenceCmd->execCmd() != 1) {
        log::add('JeedomConnect', 'debug', "Set 1 for geofence " . $params['geofence']['extras']['name']);
        $geofenceCmd->event(1);
      }
    } else if ($params['geofence']['action'] == 'EXIT') {
      if ($geofenceCmd->execCmd() != 0) {
        log::add('JeedomConnect', 'debug', "Set 0 for geofence " . $params['geofence']['extras']['name']);
        $geofenceCmd->event(0);
      }
    }
  } else {
    $eqLogic->setGeofencesByCoordinates($params['coords']['latitude'], $params['coords']['longitude']);
  }
  break;
  case 'ASK_REPLY':
    $answer = $params['answer'];
    $cmd = cmd::byId($params['cmdId']);
    if (!is_object($cmd)) {
      log::add('JeedomConnect', 'error', "Can't find command");
      return;
    }
    if ($cmd->askResponse($answer)) {
      log::add('JeedomConnect', 'debug', 'reply to ask OK');
    }
    break;
}



?>
