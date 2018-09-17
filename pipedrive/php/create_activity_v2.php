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

foreach ($params as $param){
  list($k, $v) = explode('=', $param);
  $result[$k] = $v;
  $logs .= $k . " : " . $v . "\n";
}

$pipedrive_credentials = new \stdClass();
$pipedrive_credentials->accessToken = $obj->pipedrive_access_token;
$pipedrive_credentials->domain = $obj->pipedrive_domain;

if ($success == false){
  sendResponseAndExit($response, $logs);
}

$logs .= "\n-----------NEW-LOG----------";
$logs .= "[". $date . " - " . __FILE__ . "]\nJSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/pipedrive_create_activity_v2.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$logs = "";

$activity = array(
  "type" => "call",
  "done" => 1,
  "due_date" => date("Y-m-d", strtotime($obj->closed_at)),
  "due_time" => date("H:i",  strtotime($obj->closed_at))
);
if($obj->event == 'new_outgoing_call' || $obj->event == 'outgoing_call_ended') {
  $person_details = searchContactByPhone($pipedrive_credentials->accessToken, $obj->destination_number);
  //fix for the 033X format
  if(is_null($person_details)) {
    $person_details = searchContactByPhone($pipedrive_credentials->accessToken, 
    '00' . $obj->destination_number);
  }
} else {
  $person_details = searchContactByPhone($pipedrive_credentials->accessToken, $obj->caller_id_number);
  //fix for the 033X format
  if(is_null($person_details)) {
    $person_details = searchContactByPhone($pipedrive_credentials->accessToken, 
    '00' . $obj->caller_id_number);
  }
}

if (!is_null($person_details)) {
  $activity["person_id"] = $person_details[0];
}

if(isset($obj->slack_user_email) && $obj->slack_user_email != "")   {
  $pipedrive_user_id = searchUserByEmail($pipedrive_credentials->accessToken, $obj->slack_user_email);
  if($pipedrive_user_id !== null) {
    $activity['user_id'] = $pipedrive_user_id;
  }
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
  $activity["subject"] = "Incoming call answered";

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
$activity["done"] = 0;
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

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (to : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'callee_name')) {
    $activity["subject"] .= " (to : " . $obj->destination_number . ")";
  } else {
    $activity["subject"] .= " (to : " . $obj->callee_name . ")";
  }

  $activity["note"] = "<p>Call has just started</p>";
  break;
  case "outgoing_call_ended":
  $activity["subject"] = "Outgoing call ended";

  if (is_string($person_details[1])) {
    $activity["subject"] .= " (to : " . $person_details[1] . ")";
  } elseif (!property_exists($obj, 'callee_name')) {
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
$activity["done"] = 0;
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
$url = "https://api-proxy.pipedrive.com/activities";

//$activity_string = json_encode($activity);
$fplogs = fopen('/tmp/salesforce_create_activity.txt', 'a+');
fwrite($fplogs, $activity_string);
fclose($fplogs);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POSTFIELDS, $activity);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Authorization: Bearer ' . $obj->pipedrive_access_token)
);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// create an array from the data that is sent back from the API
$result = json_decode($output, 1);

// check if an id came back
if (!empty($result['data']['id'])) {
  $activity_id = $result['data']['id'];
  $logs .= "\nCreated activity : " . json_encode($activity) . "\n";
  $logs .= "JSON : " . $output . "\n";
  $success = true;
  $response['ok'] = true;
  $response['message'] = "Successfully created activity";
} else {
  $logs .= "\nFailed to create activity.\nResponse: " . json_encode($result);
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Failed to create activity at Pipedrive";
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  //header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/pipedrive_create_activity_v2.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

/**
 * Tries to find a contact in Pipedrive from a phone number in Ottspott.
 *
 * Query Pipedrive to find a conctac from a phone number using Pipedrive's search
 * API. Return the first contact that matches on success, or an empty value
 * if nothing has been found. 
 *
 * @param array $accessToken The Pipedrive API token
 * @param string $phone The phone number to search the contact
 *
 * @return [pipedrive_person_id, title] or NULL
 */
function searchContactByPhone($accessToken, $phone){
  $url = "https://api-proxy.pipedrive.com/searchResults?term=" . $phone . "&item_type=person&start=0&limit=1";

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $accessToken
  ));

  $output = curl_exec($ch);
  curl_close($ch);
  $obj = json_decode($output, 1);
  $logs = "\nSEARCH BY PHONE :\n";
  $logs .= $output . "\n";

  if ($obj['success'] != true || $obj['data'] == NULL) {
    $logs .= 'Cannot find contact for phone ' . $phone . "\n";
  } else {
  $logs .= 'Found contact for phone ' . $phone . ' : ' . json_encode($obj['data'][0]['title']) . "\n";
  }

  $fplogs = fopen('/tmp/pipedrive_create_activity_v2.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);

  if ($obj['success'] != true || $obj['data'] == NULL) {
    return NULL;
  }

  return [$obj['data'][0]['id'], $obj['data'][0]['title']];
}

/**
 * Tries to find a user in Pipedrive from an email in Ottspott.
 *
 * Query Pipedrive to find a user from an email using Pipedrive's search
 * API. Return the first user that matches on success, or an empty value
 * if nothing has been found. 
 *
 * @param array $accessToken The Pipedrive API token
 * @param string $email The email number to search the user
 *
 * @return pipedrive_user_id or NULL
 */
function searchUserByEmail($accessToken, $email){
  $url = "https://api-proxy.pipedrive.com/users/find?term=" . $email . "&search_by_email=1";

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $accessToken
  ));

  $output = curl_exec($ch);
  curl_close($ch);
  $obj = json_decode($output, 1);
  $logs = "\nSEARCH BY MAIL :\n";
  $logs .= $output . "\n";

  if ($obj['success'] != true || $obj['data'] == NULL) {
    $logs .= 'Cannot find user for email ' . $email . "\n";
  } else {
  $logs .= 'Found user for email ' . $email . ' : ' . json_encode($obj['data'][0]['name']) . "\n";
  }

  $fplogs = fopen('/tmp/pipedrive_create_activity_v2.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);

  if ($obj['success'] != true || $obj['data'] == NULL) {
    return NULL;
  }

  return $obj['data'][0]['id'];
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

