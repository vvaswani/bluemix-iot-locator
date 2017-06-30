<?php
// include class
require('phpMQTT.php');

// load configuration values
require('config.php');
$config['server'] = $config['org_id'] . '.messaging.internetofthings.ibmcloud.com';
$config['client_id'] = 'a:' . $config['org_id'] . ':' . $config['app_id'];

// report MySQL errors as exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// get JSON packet posted to webhook
$json = file_get_contents('php://input');

if (!$json) {
  die('No POST data available');
}

// decode JSON packet
// extract device ID, message contents
$data = json_decode($json);
$deviceIdArr = explode(':', $data->deviceId);
$deviceId = $deviceIdArr[2];
$message = json_decode($data->message);
$longitude = round($message->d->longitude, 4);
$latitude = round($message->d->latitude, 4);

// open database connection
$mysqli = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if ($mysqli->connect_errno) {
  error_log("Failed to connect to MySQL: " . $mysqli->connect_error);
}

try {
  // check if this device already has a location record
  $sql = "SELECT latitude, longitude, wait_time FROM device_location WHERE device_id = '$deviceId' LIMIT 0,1";
  $result = $mysqli->query($sql);
  $row = $result->fetch_object();
  // check if the last location recorded in the database matches the current location
  // if yes, update the wait time
  // if no, update the location record
  if ($result->num_rows == 1 && $row->latitude == $latitude && $row->longitude == $longitude) {
    $wait_time = $row->wait_time + 1;
    $sql = "UPDATE device_location SET wait_time = '$wait_time', updated = NOW() WHERE device_id = '$deviceId'";
    $mysqli->query($sql);
  } else {
    $sql = "DELETE FROM device_location WHERE device_id = '$deviceId'";
    $mysqli->query($sql);
    $wait_time = 0;
    $sql = "INSERT INTO device_location (device_id, latitude, longitude, wait_time, updated) VALUES ('$deviceId', '$latitude', '$longitude', '$wait_time', NOW())";
    $mysqli->query($sql);
  }
  
  // if the device has been in the same location
  // for the trigger number of minutes
  // find offers within the configured proximity radius
  // which have not already been delivered to the device today
  if ($wait_time == $config['wait_time_trigger_min']) {
    $proximity = $config['proximity_trigger_km'];
    $sql = "SELECT o.id, o.message, 
        (6371 * acos(cos(radians($latitude)) * cos(radians(latitude)) * cos(radians(longitude) - radians($longitude)) + sin(radians($latitude)) * sin(radians(latitude)))) AS distance
          FROM offer o
          LEFT JOIN device_offer dos ON o.id = dos.offer_id
        WHERE (dos.offer_delivery_date != DATE(NOW())
          OR dos.offer_delivery_date IS NULL)
        HAVING distance < $proximity
        ORDER BY distance
        LIMIT 0,10";
    $result = $mysqli->query($sql);

    if ($result->num_rows > 0) {
      // if offers found
      // initialize MQTT client
      $mqtt = new phpMQTT($config['server'], $config['port'], $config['client_id']); 
      $mqtt->debug = false;
      
      // connect to broker
      if(!$mqtt->connect(true, null, $config['iotf_api_key'], $config['iotf_api_secret'])){
        error_log('Failed to Could not connect to IoT cloud');
        exit();
      } 

      // iterate over offer list and publish to device
      // update database with offer delivery status
      while ($row = $result->fetch_object()) {
        $offerId = $row->id;
        $message = $row->message;
        $mqtt->publish('iot-2/type/Android/id/' . $deviceId . '/cmd/alert/fmt/json', '{"d":{"text":"' . $message . '"}}', 1);      
        $sql = "INSERT INTO device_offer (device_id, offer_id, offer_delivery_date) VALUES ('$deviceId', '$offerId', DATE(NOW()))";
        $mysqli->query($sql);
      }  

      // disconnect MQTT client
      $mqtt->close();      
    }    
  }
  
} catch (Exception $e) {
  error_log($e->getMessage());
}

// close database connection
$mysqli->close();