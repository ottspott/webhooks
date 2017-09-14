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

//header('Content-Type: application/json');
$pipedrive_credentials = new \stdClass();

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
if($obj->event == 'new_outgoing_call' || $obj->event == 'outgoing_call_ended') {
  $person_details = searchUserByPhone($pipedrive_credentials->apiToken, $obj->destination_number);
} else {
  $person_details = searchUserByPhone($pipedrive_credentials->apiToken, $obj->caller_id_number);
}

if (!is_null($person_details)) {
  $activity["person_id"] = $person_details[0];
}

switch($obj->event){
  case "new_incoming_call":
  $activity["subject"] = "Incoming call started";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (from : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Call has just started</p>";
  break;
  case "incoming_call_answered":
  $activity["subject"] = "<p>Incoming call answered</p>";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (from : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>" . $obj->detailed_status . "</p>";
  break;
  case "incoming_call_ended_and_missed":
  $activity["subject"] = "Missed call";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (from : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Call has been left unanswered</p>";
  break;
  case "incoming_call_ended_and_answered":
  $activity["subject"] = "Incoming call ended";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (from : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] =  "<p>" . $obj->detailed_status . " (duration : " . duration($obj->duration) . ")</p>";
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $activity["note"] .= "<p>Call has been recorded : <a href='" . $obj->recording_url . "'>Recording file</a>";
  }
  $activity["duration"] = gmdate('H:i', $obj->duration + 59);
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
  $activity["duration"] = gmdate('H:i', $obj->duration + 59);
  $activity["note"] = "<p>" . $obj->detailed_status . " (duration : " . duration($obj->duration) . ")</p>";
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $activity["note"] .= "<p>Call has been recorded : <a href='" . $obj->recording_url . "'>Recording file</a>";
  }
  break;
  case "incoming_call_ended_and_voicemail_left":
  $activity["subject"] = "Received Voicemail";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (from : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'caller_id_name')) {
    $activity["subject"] .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $activity["subject"] .= " (from : " . $obj->caller_id_name . ")";
  }

  $activity["note"] = "<p>Voicemail file : <a href='" .$obj->voicemail_url . "' target='_blank'>here</a></p>";

  if (!is_null($obj->voicemail_transcription)){
  $activity["note"] .= "<p>Transcribed text : <code>" .$obj->voicemail_transcription . "</code></p>";
  }
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
 * Tries to find a user in Pipedrive from a phone number in Ottspott.
 *
 * Query Pipedrive to find a user from a phone number using Pipedrive's search
 * API. Return the first user that matches on success, or an empty value
 * if nothing has been found. 
 *
 * @param array $apiToken The Pipedrive API token
 * @param string $phone The phone number to search the user
 *
 * @return [pipedrive_person_id, title] or NULL
 */
function searchUserByPhone($apiToken, $phone){
  $url = "https://api.pipedrive.com/v1/searchResults?term=" . $phone . "&item_type=person&start=0&limit=1&api_token=" . $apiToken;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Accept: application/json'
  ));

  $output = curl_exec($ch);
  curl_close($ch);
  $obj = json_decode($output, 1);

  $logs = "[". $date . " - " . __FILE__ . "] search result from Pipedrive :\n";
  $logs .= $output . "\n";

  if ($obj['success'] != true || $obj['data'] == NULL) {
    $logs .= 'Cannot find contact for phone ' . $phone . "\n";
  } else {
  $logs .= 'Found user for phone ' . $phone . ' : ' . json_encode($obj['data'][0]['title']) . "\n";
  }

  $fplogs = fopen('/tmp/pipedrive_create_activity.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);

  if ($obj['success'] != true || $obj['data'] == NULL) {
    return NULL;
  }

  return [$obj['data'][0]['id'], $obj['data'][0]['title']];
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
