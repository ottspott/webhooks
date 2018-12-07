<?php

$date = date('d-m-Y G:i:s');

$success = true;
$response['ok'] = true;

$json = file_get_contents('php://input');
$obj = json_decode($json);

$logs .= "\n[". $date . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/zoho_create_activity.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$zoho_credentials = new \stdClass();
$zoho_credentials->accessToken = $obj->zoho_oauth_token;
$zoho_credentials->domain = $obj->zoho_domain;

$logs = "";
switch($obj->event){
  case "new_incoming_call":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/callreceived";
    if (property_exists($obj, 'userid')){
      $parameters = array(
        "userid" => $obj->userid,
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
      );
    }
    else{
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
      );
    }
  break;
  case "incoming_call_answered":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/callanswered";
    if (property_exists($obj, 'userid')){
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "userid" => $obj->userid,
      );
    }
    else {
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
      );
    }
  break;
  case "incoming_call_ended_and_missed":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/callmissed";
    $parameters = array(
      "callrefid" => $obj->call_uuid,
      "fromnumber" => $obj->caller_id_number,
      "tonumber" => $obj->destination_number,
      "callmissedtime" => prettyDate($obj->closed_at),
    );
  break;
  case "incoming_call_ended_and_answered":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/callhungup";
    if (property_exists($obj, 'userid')){
      $parameters = array(
        "userid" => $obj->userid,
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "callstarttime" => prettyDate($obj->answered_at),
        "direction" => "inbound",
        "duration" => $obj->duration,
      );
    }
    else {
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "callstarttime" => prettyDate($obj->answered_at),
        "direction" => "inbound",
        "duration" => $obj->duration,
      );
    }
  break;
  case "new_outgoing_call":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/calldialed";
    $custstatus = "inprogress";
    if (property_exists($obj, 'callstatus') && $obj->callstatus == "outcallanswered")
      $custstatus = "success";
    if (property_exists($obj, 'userid')){
      $userid = $obj->userid;
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "customerstatus" => $custstatus,
        "userid" => $userid,
      );
    }
    else{
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "customerstatus" => $custstatus,
      );
    }
  break;
  case "outgoing_call_ended":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/callhungup";
    if (property_exists($obj, 'userid')){
      $parameters = array(
        "userid" => $obj->userid,
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "callstarttime" => prettyDate($obj->answered_at),
        "direction" => "outbound",
        "duration" => $obj->duration,
      );
    }
    else{
      $parameters = array(
        "callrefid" => $obj->call_uuid,
        "fromnumber" => $obj->caller_id_number,
        "tonumber" => $obj->destination_number,
        "callstarttime" => prettyDate($obj->answered_at),
        "direction" => "outbound",
        "duration" => $obj->duration,
      );
    }
  break;
  case "incoming_call_ended_and_voicemail_left":
    $url = "https://www.zohoapis.com/crm/v2/phonebridge/voiceurl";
    $parameters = array(
      "callrefid" => $obj->call_uuid,
      "voiceurl" => $obj->voicemail_url,
    );
  break;
}
// Encode in JSON
$data_string = http_build_query($parameters, '', '&');

$datalogs = "";
$datalogs .= "\n[". $date . "] Encoded parameters are : ";
$datalogs .= $data_string;
$datalogs .= "\n";

$dlogs = fopen('/tmp/zoho_create_activity.txt', 'a+');
fwrite($dlogs, $datalogs);
fclose($dlogs);

//Now let's send a request to Zoho
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, count($parameters));
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Authorization: Zoho-oauthtoken ' . $obj->zoho_oauth_token,
));

$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// create an array from the data that is sent back from the API
$result = json_decode($output, 1);

if (!isset($result['status'])) {
  $success = true;
  $response['ok'] = true;
  $response['message'] = "Successfully created activity";
} else {
  $logs .= "Failed to create activity.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Failed to create activity!\nResponse from Zoho: " . $output . "\n";
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/zoho_create_activity.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

function prettyDate($date){
  $str = preg_replace('/T/', ' ', $date);
  $arr = explode(".", $str);
  $pretty_date = $arr[0];
  return $pretty_date;
}

?>
