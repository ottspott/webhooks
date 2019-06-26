<?php

$date = date('d-m-Y G:i:s');

$json = file_get_contents('php://input');
$obj = json_decode($json);
$url_parts = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$success = true; // let's be optimistic
$response = array(
  "ok" => true
); // let's be optimistic
$gorgias_credentials = array(
  "apiToken" => "",
  "domain" => "",
  "senderEmail" => "",
  "senderName" => "",
  "requesterEmail" => "",
  "requesterName" => "",
  "toName" => "",
);

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

$gorgias_credentials['apiToken'] = $result['apiToken'];

if (!isset($result['domain'])){
  $logs .= "Cannot find domain, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Gorgias domain is missing or invalid";
}

// accept both domain.gorgias.io and domain
$gorgias_credentials['domain'] = explode(".", $result['domain'])[0];

if (!isset($result['requesterName'])){
  $logs .= "senderName not found, setting it to default (Ottspott Logger).\n";
  $gorgias_credentials['senderName'] = "Ottspott Logger";
} else {
  $gorgias_credentials['senderName'] = $result['requesterName'];
}

if (!isset($result['requesterEmail'])){
  $logs .= "Cannot find senderEmail, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Gorgias senderEmail is missing or invalid";
}

$gorgias_credentials['senderEmail'] = $result['requesterEmail'];

$gorgias_credentials["requesterEmail"] = $gorgias_credentials['senderEmail'];
$gorgias_credentials["requesterName"] = $gorgias_credentials['senderName'];
$gorgias_credentials["toName"] = $gorgias_credentials['senderName'];

if($obj->event == 'new_outgoing_call' || $obj->event == 'outgoing_call_ended') {
  $user_details = searchContactByPhone($gorgias_credentials, $obj->destination_number);
  if(isset($user_details)) {
    $gorgias_credentials["requesterEmail"] = $user_details[2];
    $gorgias_credentials["requesterName"] = $user_details[1];
    $gorgias_credentials['toName'] = $user_details[1];
  }
} else {
  $user_details = searchContactByPhone($gorgias_credentials, $obj->caller_id_number);
  if(isset($user_details)){
    $gorgias_credentials["requesterEmail"] = $user_details[2];
    $gorgias_credentials["requesterName"] = $user_details[1];
  }
}

if ($success == false){
  sendResponseAndExit($response, $logs);
}

$logs = "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$subject = "";
$call_details_raw = "";
$call_details_raw_html = "";

switch ($obj->event){
  case "outgoing_call_ended":
  if($gorgias_credentials["toName"] === "") {
    $subject = "Outgoing call to +" . $obj->destination_number;
  } else {
    $subject = "Outgoing call to " . $gorgias_credentials["toName"] . ' (+' . $obj->destination_number . ')';
  }

  $call_details_raw = "Caller : " . $obj->caller_id_name . " - duration : " . $obj->duration . " seconds";
  $call_details_raw_html = "Caller : <b>" . $obj->caller_id_name . "</b> - duration : " . $obj->duration . " seconds";
  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $call_details_raw_html .= "<br />Recording file : <audio src='" . $obj->recording_url . "' controls></audio>";
  }
  break;
  case "incoming_call_ended_and_missed":
  $subject = "Missed call ";

  if ($gorgias_credentials["requesterName"] === "") {
    $subject .= " from +" . $obj->caller_id_number;
  } else {
    $subject .= " from " . $gorgias_credentials["requesterName"] . " (+" . $obj->caller_id_number . ")";
  }

  $call_details_raw = "A call has been missed";
  $call_details_raw_html = "A call has been missed";
  break;
  case "incoming_call_ended_and_answered":
  $subject = "Incoming call terminated";

  if ($gorgias_credentials["requesterName"] === "") {
    $subject .= " from +" . $obj->caller_id_number;
  } else {
    $subject .= " from " . $gorgias_credentials["requesterName"] . " (+" . $obj->caller_id_number . ")";
  }


  $call_details_raw = $obj->detailed_status . " - duration : " . $obj->duration . " seconds";
  $call_details_raw_html = $obj->detailed_status . " - duration : " . $obj->duration . " seconds";

  if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
    $call_details_raw_html .= "<br />Recording file : <audio src='" . $obj->recording_url . "' controls></audio>";
  }
  break;
  case "incoming_call_ended_and_voicemail_left":
  $subject = "Received Voicemail";

  if ($gorgias_credentials["requesterName"] === "") {
    $subject .= " from +" . $obj->caller_id_number;
  } else {
    $subject .= " from " . $gorgias_credentials["requesterName"] . " (+" . $obj->caller_id_number . ")";
  }

  $call_details_raw = "Voicemail URL " . $obj->voicemail_url . ", duration : " . $obj->voicemail_duration . "s";
  $call_details_raw_html = "<br />Voicemail file : <audio src='" . $obj->voicemail_url . "' controls></audio>";

  if (!is_null($obj->voicemail_transcription)){
    $call_details_raw .= ", transcribed text : '" . $obj->voicemail_transcription . "'";
    $call_details_raw_html .= "<div>transcribed text : <code>" . $obj->voicemail_transcription . "</code></div>";
  }
  break;
}

// Build Gorgias ticket data
$data = array(
  "subject" => $subject,
  "sender" => array(
    "name"=> $gorgias_credentials['senderName'],
    "email"=> $gorgias_credentials['senderEmail']
    ),
  "requester" => array(
"name"=> $gorgias_credentials['requesterName'],
    "email"=> $gorgias_credentials['requesterEmail']
    ),
  "receiver" => array(
    "name"=> $gorgias_credentials['senderName'],
    "email"=> $gorgias_credentials['senderEmail']
    ),
  "channel" => "phone",
  "via" => "phone",
  "messages" =>   array(
    array(
      "public" => true,
      "channel" => "phone",
      "via" => "phone",
      "from_agent" => true,
      "receiver" => array(
        "name"=> $gorgias_credentials['senderName'],
        "email"=> $gorgias_credentials['senderEmail']
        ),
      "sender" => array(
        "name"=> $gorgias_credentials['senderName'],
        "email"=> $gorgias_credentials['senderEmail']
        ),
      "source" => array(
        "type" => "ottspott-call",
        "from" => array(
          "name" => "Unknown yet",
          "address" => "+" . $obj->caller_id_number
          ),
        "to" => array(
          array(
            "name" => $gorgias_credentials['toName'],
            "address" => "+" . $obj->destination_number
            ),
          ),
        ),
      "body_text" => $call_details_raw,
      "body_html" => $call_details_raw_html
      )
    ),
  );

// Encode in JSON
$data_string = json_encode($data);

// Send request to create ticket
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://" . $gorgias_credentials['domain'] . ".gorgias.io/api/tickets/");
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Content-Type: application/json',
  'Authorization: Basic ' . base64_encode($gorgias_credentials['senderEmail'] . ':' . $gorgias_credentials['apiToken']),
  'Cache-Control: no-cache',
  'Content-Length: ' . strlen($data_string)
  ));

$logs .= "\n";
$logs .= "[". $date . " - " . __FILE__ . "] TO SEND :\n";
$logs .= json_encode($data);
$logs .= "\n";

$output = curl_exec($ch);
$result = curl_getinfo($ch);
curl_close($ch);

$logs .= "\n";
$logs .= "[". $date . " - " . __FILE__ . "] HTTP CODE FROM GORGIAS : " . $result["http_code"];
$logs .= "\n";
$logs .= "\n";
$logs .= "\n";

if ($result["http_code"] != "200" && $result["http_code"] != "201"){
  $response['ok'] = false;
  $response['message'] = 'Failed to create ticket at Gorgias, status code : ' . $result["http_code"];
} else {
  $response['ok'] = true;
  $response['message'] = 'Successfully created ticket at Gorgias.';
}

sendResponseAndExit($response, $logs);

function searchContactByPhone($gorgias_credentials, $phone){

  $data = array(
    "type"  => "users_by_phone",
    "query" => "+" . $phone,
    "size"  => "1"
  );
  $data_string = json_encode($data);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://" . $gorgias_credentials['domain'] . ".gorgias.io/api/search/");
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($gorgias_credentials['senderEmail'] . ':' . $gorgias_credentials['apiToken']),
    'Cache-Control: no-cache',
    'Content-Length: ' . strlen($data_string)
    ));


  $output = curl_exec($ch);
$result = curl_getinfo($ch);
curl_close($ch);
  $obj = json_decode($output, 1);

  $logs = "[". $date . " - " . __FILE__ . "] search result from Gorgias :\n";
  $logs .= $output . "\n";
$logs .= "[". $date . " - " . __FILE__ . "] HTTP CODE FROM GORGIAS : " . $result["http_code"];
if ( $result["http_code"] != "201" && empty($obj['data']) && !isset($obj['data'][0])) {
    $logs .= 'Cannot find contact for phone ' . $phone . "\n";
  } else {
$logs .= 'Found user for phone ' . $phone . ' : ' . json_encode($obj['data'][0]['name']) . "\n";
  }

if ( $result["http_code"] != "201" && empty($obj['data']) && !isset($obj['data'][0])) {
    return NULL;
  }
$logs .= "\nRES " . $obj['data'][0]['id'] . " " . $obj['data'][0]['name'] . " " . $obj['data'][0]['email'];
  $fplogs = fopen('/tmp/webhooks_gorgias.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  return [$obj['data'][0]['id'], $obj['data'][0]['name'], $obj['data'][0]['email']];
}
function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/webhooks_gorgias.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

?>
