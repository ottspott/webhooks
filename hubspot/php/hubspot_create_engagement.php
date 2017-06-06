<?php

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
$logs .= "\n POST params \n" ;
foreach ($obj as $key => $value) {
  $logs .= $key . ": " . $value . "\n";
}

if (!isset($result['hapikey'])){
  $logs .= "Cannot find hapikey, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "hapikey is missing or invalid";
}

$hapikey = $result['hapikey'];

if($hapikey) {
  if($obj->event === "outgoing_call_ended" || $obj->event === "new_outgoing_call") {  
    $phone = $obj->destination_number;
  } else  {
    $phone = $obj->caller_id_number;
  }
  $logs .= 'PHONE: ' . $phone + ' hapikey ' + $hapikey;
  $contact = getHubspotContact($phone, $hapikey);

  if($contact === null) {
    $logs .= "Cannot find HubSpot contact, returning.\n";
    $success = false;
    $response['ok'] = false;
    $response['message'] = "HubSpot contact was not found";
  } else {
    $contact_name = " (" . $contact->properties->firstname->value . " " . $contact->properties->lastname->value . ")";  
  }
}


if ($success == false){
  sendResponseAndExit($response, $logs);
}

$logs .= "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/hubspot_create_engagement.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$logs = "";

$engagement = array(
  "engagement" => array(
    "active" => true,
    "ownerId"=> intval($contact->properties->hubspot_owner_id->value),
    "type" => "CALL",
    "timestamp" =>  time()*1000
  ),
  "associations" => array (
    "contactIds"=> [
      $contact->vid
    ],
    "companyIds"=> [],
    "dealIds"=> [],
    "ownerIds"=> [intval($contact->properties->hubspot_owner_id->value)]
  ),
  "attachments"=> [],
  "metadata" => []
);



switch($obj->event){

case "new_incoming_call":
  $engagement["metadata"]["body"] = "Incoming call started <br />";
  $engagement["metadata"]["body"] .= " From : +" . $obj->caller_id_number . $contact_name;
  $engagement["metadata"]["body"] .= "<br /> To: +" . $obj->destination_number;
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'RINGING';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = 0;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';

  break;

case "incoming_call_answered":
  $engagement["metadata"]["body"] = $obj->detailed_status . " <br />";
  $engagement["metadata"]["body"] .= "From : +" . $obj->caller_id_number . $contact_name;
  $engagement["metadata"]["body"] .= "<br /> To : +" . $obj->destination_number;
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'CONNECTING';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = 0;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';
  break;

case "incoming_call_ended_and_missed":
  $engagement["metadata"]["body"] = "Missed call <br />";
  $engagement["metadata"]["body"] .= "From : +" . $obj->caller_id_number . $contact_name;
  $engagement["metadata"]["body"] .= "<br /> To : +" . $obj->destination_number;
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'NO_ANSWER';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = 0;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';

  break;

case "incoming_call_ended_and_answered":
  $engagement["metadata"]["body"] =  $obj->detailed_status . " (duration : " . duration($obj->duration) . ") <br />";

  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'COMPLETED';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = $obj->duration*1000;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';
  $engagement["metadata"]["body"] .= "From : +" . $obj->caller_id_number . $contact_name;

  $engagement["metadata"]["body"] .= "<br /> To : +" . $obj->destination_number;
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $engagement["metadata"]["recordingUrl"] = $obj->recording_url;
  }

  break;

case "new_outgoing_call":
  $engagement["metadata"]["body"] = "Outgoing call has just started";
  $engagement["metadata"]["body"] .= "<br /> From : +" . $obj->caller_id_number . "<br />";
  $engagement["metadata"]["body"] .= " To : +" . $obj->destination_number . $contact_name;
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'RINGING';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = 0;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';

  break;

case "outgoing_call_ended":
  $engagement["metadata"]["body"] = "Outgoing call ended (duration : " . duration($obj->duration) . ") <br />";
  $engagement["metadata"]["body"] .= " From : +" . $obj->caller_id_number;
  if (property_exists($obj, 'caller_id_name')) {
    $engagement["metadata"]["body"] .= "  (" . $obj->caller_id_name . ")";
  } 
  $engagement["metadata"]["body"] .=  "<br /> To : +" . $obj->destination_number . $contact_name;
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'COMPLETED';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = $obj->duration * 1000;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = '';

  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $engagement["metadata"]["recordingUrl"] = $obj->recording_url;
  }

  break;

case "incoming_call_ended_and_voicemail_left":
  $engagement["metadata"]["body"] = "Received Voicemail (duration: " . duration($obj->voicemail_duration) . ")<br />";
  $engagement["metadata"]["toNumber"] = $obj->destination_number;
  $engagement["metadata"]["fromNumber"] = $obj->caller_id_number;
  $engagement["metadata"]["status"] = 'COMPLETED';
  $engagement["metadata"]["externalId"] = '';
  $engagement["metadata"]["durationMilliseconds"] = $obj->voicemail_duration * 1000;
  $engagement["metadata"]["externalAccountId"] = '';
  $engagement["metadata"]["recordingUrl"] = $obj->voicemail_url;
  $engagement["metadata"]["body"] .= "From : +" . $obj->caller_id_number . $contact_name . "<br />";
  $engagement["metadata"]["body"] .= "To : +" . $obj->destination_number . "<br />";
  $engagement["metadata"]["body"] .= "Transcription : " . $obj->voicemail_transcription;

  break;

}

// Now send request to HubSpot
$url = "https://api.hubapi.com/engagements/v1/engagements?hapikey=" . $hapikey;
$engagement_string = json_encode($engagement);
$fplogs = fopen('/tmp/hubspot_create_engagement.txt', 'a+');
fwrite($fplogs, $engagement_string);
fclose($fplogs);
//$engagements = json_encode($engagements);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POSTFIELDS, $engagement_string);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Content-Type: application/json',
  'Accept: application/json'
)
);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// create an array from the data that is sent back from the API
$result = json_decode($output, 1);
$logs .= "\n RESULT " . $output . "\n";
// check if an id came back
if (!empty($result['engagement']['id'])) {
  $engagement = $result['engagement'];
  $logs .= "Created engagement : " . $engagement_string . "\n";
  $logs .= "JSON : " . $output . "\n";
  $success = true;
  $response['ok'] = true;
  $response['message'] = "Successfully created engagement";
} else {
  $logs .= "Failed to create engagement.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Failed to create engagement at HubSpot";
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/hubspot_create_engagement.txt', 'a+');
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

/**
 * A function to search HubSpot contact by phone
 *
 * @phone       concact phone in international format 
 * @hapikey     hubspot api key
 *
 * @return      object 
 */
function getHubspotContact($phone, $hapikey)
{
  $url = "https://api.hubapi.com/contacts/v1/search/query?q=+" . $phone . "&hapikey=" . $hapikey;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

  $output = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);

  $result = json_decode($output);
  if(isset($result->total) && $result->total > 0) {
    return  $result->contacts[0];
  } else {
    return null;
  }

}
?>
