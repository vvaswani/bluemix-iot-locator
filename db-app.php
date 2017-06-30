<?php
// include class
require('phpMQTT.php');

// load configuration values
require('config.php');

$config['server'] = $config['org_id'] . '.messaging.internetofthings.ibmcloud.com';
$config['client_id'] = 'a:' . $config['org_id'] . ':' . $config['app_id'];
$location = array();

// initialize client
$mqtt = new phpMQTT($config['server'], $config['port'], $config['client_id']); 
$mqtt->debug = false;

// connect to broker
if(!$mqtt->connect(true, null, $config['iotp_api_key'], $config['iotp_api_secret'])){
  echo 'ERROR: Could not connect to IoT cloud';
	exit();
} 

// connect to database
$mysqli = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if ($mysqli->connect_errno) {
  echo 'ERROR: Could not connect to MySQL (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error;
  exit();
}

// subscribe to topics
$topics['iot-2/type/Android/id/' . $config['device_id'] . '/evt/accel/fmt/json'] = 
  array('qos' => $config['qos'], 'function' => 'getLocation');
$mqtt->subscribe($topics, $config['qos']);

// process messages
while ($mqtt->proc(true)) { 
}

// disconnect
$mqtt->close();

function getLocation($topic, $msg) {
  $mysqli = $GLOBALS['mysqli'];
  $json = json_decode($msg);
  $latitude = $json->d->latitude;
  $longitude = $json->d->longitude;
  if (!$mysqli->query("INSERT INTO data(ts, latitude, longitude) VALUES (NOW(), '$latitude', '$longitude')")) {
    echo 'ERROR: Data insertion failed';
    exit();
  }
}

/*
// MySQL table schema

CREATE TABLE `data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ts` datetime DEFAULT NULL,
  `latitude` float DEFAULT NULL,
  `longitude` float DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 

*/
?>