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
  $json = json_decode($msg);
  echo date('d-m-y h:i:s') . " Device located at (" . $json->d->latitude . ", " . $json->d->longitude . ")" . PHP_EOL;
}
?>