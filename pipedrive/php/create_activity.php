<?php

/**
 * An Ottspott Webhook that creates an activity in Pipedrive.
 *
 * Ottspott will pass as much information as possible, for instance if
 * your contacts have been imported in Ottspott from Pipedrive and a
 * contact matches for a the given call that triggers the webhook,
 * Ottspott will pass a 'pipedrive_person_id' that refers to the contact
 * object in Pipedrive.
 */

$date = date('d-m-Y G:i:s');

$success = true;
$response['ok'] = true;
$json = file_get_contents('php://input');
$obj = json_decode($json);
$url_parts = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

$params = explode('&', $url_parts);

$logs = "[". $date . " - " . __FILE__ . "] URL PARAMETERS RECEIVED FROM OTTSPOTT :\n";

foreach ($params as $param){
  list($k, $v) = explode('=', $param);
  $result[$k] = $v;
  $logs .= $k . " : " . $v . "\n";
}

if (!isset($result['apiToken'])){
  $logs .= "Cannot find apiToken, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "apiToken is missing or invalid";
}

$pipedrive_credentials->apiToken = $result['apiToken'];

if (!isset($result['domain'])){
  $logs .= "Cannot find domain, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Pipedrive domain is missing or invalid";
}

$pipedrive_credentials->domain = $result['domain'];

if ($success == false){
  sendResponseAndExit($response, $logs);
}

$logs .= "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/pipedrive_create_activity.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$logs = "";

$activity = array(
  "type" => "call",
  "done" => 1
);

if (property_exists($obj, 'contact_data') && $obj->contact_data->is_pipedrive_contact == true) {
  // Found Pipedrive contact, set person_id
  $activity["person_id"] = $obj->contact_data->pipedrive_person_id;
}

switch($obj->event){
  case "new_incoming_call":
  $activity["subject"] = "Incoming call started";

  if (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Call has just started</p>";
  break;
  case "incoming_call_answered":
  $activity["subject"] = "<p>Incoming call answered</p>";

  if (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>" . $obj->detailed_status . "</p>";
  break;
  case "incoming_call_ended_and_missed":
  $activity["subject"] = "Missed call";

  if (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Call has been left unanswered</p>";
  break;
  case "incoming_call_ended_and_answered":
  $activity["subject"] = "Incoming call ended";

  if (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] =  "<p>" . $obj->detailed_status . " (duration : " . duration($obj->duration) . ")</p>";
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
	  $activity["note"] .= "<p>Call has been recorded : <a href='" . $obj->recording_url . "'>Recording file</a>";
  }
  break;
  case "new_outgoing_call":
  $activity["subject"] = "Outgoing call started";

  if (!property_exists($obj, 'callee_name')) {
    $activity["subject"] .= " (to : " . $obj->destination_number . ")";
  } else {
    $activity["subject"] .= " (to : " . $obj->callee_name . ")";
  }

  $activity["note"] = "<p>Call has just started</p>";
  break;
  case "outgoing_call_ended":
  $activity["subject"] = "Outgoing call ended";

  if (!property_exists($obj, 'callee_name')) {
    $activity["subject"] .= " (to : " . $obj->destination_number . ")";
  } else {
    $activity["subject"] .= " (to : " . $obj->callee_name . ")";
  }

  $activity["note"] = "<p>" . $obj->detailed_status . " (duration : " . duration($obj->duration) . ")</p>";
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
	  $activity["note"] .= "<p>Call has been recorded : <a href='" . $obj->recording_url . "'>Recording file</a>";
  }
  break;
  case "incoming_call_ended_and_voicemail_left":
  $activity["subject"] = "Received Voicemail";

  if (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Voicemail has just been left</p>";
  break;
}

// Now send request to Pipedrive
$url = "https://api.pipedrive.com/v1/activities?api_token=" . $pipedrive_credentials->apiToken;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, true);

curl_setopt($ch, CURLOPT_POSTFIELDS, $activity);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// create an array from the data that is sent back from the API
$result = json_decode($output, 1);

// check if an id came back
if (!empty($result['data']['id'])) {
  $activity_id = $result['data']['id'];
  $logs .= "Created activity : " . json_encode($activity) . "\n";
  $logs .= "JSON : " . $output . "\n";
  $success = true;
  $response['ok'] = true;
  $response['message'] = "Successfully created activity";
} else {
  $logs .= "Failed to create activity.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Failed to create activity at Pipedrive";
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/pipedrive_create_activity.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

/**
 * A function for making time periods readable
 *
 * @link        https://snippets.aktagon.com/snippets/122-how-to-format-number-of-seconds-as-duration-with-php
 * @param       int number of seconds elapsed
 *
 * @return      string
 */
function duration($seconds_count)
{
  $delimiter  = ':';
  $seconds = $seconds_count % 60;
  $minutes = floor($seconds_count/60);
  $hours   = floor($seconds_count/3600);

  $seconds = str_pad($seconds, 2, "0", STR_PAD_LEFT);
  $minutes = str_pad($minutes, 2, "0", STR_PAD_LEFT).$delimiter;

  if($hours > 0)
  {
    $hours = str_pad($hours, 2, "0", STR_PAD_LEFT).$delimiter;
  }
  else
  {
    $hours = '';
  }

  return "$hours$minutes$seconds";
}

?>
