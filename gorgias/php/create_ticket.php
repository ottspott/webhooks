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
  "requesterEmail" => "",
  "requesterName" => ""
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
  $logs .= "requesterName not found, setting it to default (Ottspott Logger).\n";
  $gorgias_credentials['requesterName'] = "Ottspott Logger";
} else {
  $gorgias_credentials['requesterName'] = $result['requesterName'];
}

if (!isset($result['requesterEmail'])){
  $logs .= "Cannot find requesterEmail, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "Gorgias requesterEmail is missing or invalid";
}

$gorgias_credentials['requesterEmail'] = $result['requesterEmail'];

if ($success == false){
  sendResponseAndExit($response, $logs);
}

$logs = "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$subject = "";
$call_details = "";

switch ($obj->event){
  case "outgoing_call_ended":
  $subject = "Outgoing call to " . $obj->destination_number;
  $call_details = "Caller : " . $obj->caller_id_name . " - duration : " . $obj->duration . " seconds";
  break;
  case "incoming_call_ended_and_missed":
  $subject = "Missed call from " . $obj->caller_id_number;
  $call_details = "A call has been missed";
  break;
  case "incoming_call_ended_and_answered":
  $subject = "Incoming call terminated from " . $obj->caller_id_number;
  $call_details = "Answerer : " . $obj->answered_by . " - duration : " . $obj->duration . " seconds";
  break;
  case "incoming_call_ended_and_voicemail_left":
  $subject = "Received Voicemail";

  if (!property_exists($obj, 'caller_id_name')) {
    $subject .= " (from : " . $obj->caller_id_number . ")";
  } else {
    $subject .= " (from : " . $obj->caller_id_name . ")";
  }

  $call_details = "Voicemail URL <a href='" . $obj->voicemail_url . "'>here</a>, duration : " . $obj->voicemail_duration . "s";
  break;
}

// Build Gorgias ticket data
$data = array(
  "subject" => $subject,
  "sender" => array(
    "name"=> $gorgias_credentials['requesterName'],
    "email"=> $gorgias_credentials['requesterEmail']
    ),
  "requester" => array(
    "name"=> $gorgias_credentials['requesterName'],
    "email"=> $gorgias_credentials['requesterEmail']
    ),
  "receiver" => array(
    "name"=> $gorgias_credentials['requesterName'],
    "email"=> $gorgias_credentials['requesterEmail']
    ),
  "channel" => "phone",
  "via" => "phone",
  "messages" =>   array(
    array(
      "public" => true,
      "channel" => "phone",
      "via" => "phone",
      "receiver" => array(
        "name"=> $gorgias_credentials['requesterName'],
        "email"=> $gorgias_credentials['requesterEmail']
        ),
      "sender" => array(
        "name"=> $gorgias_credentials['requesterName'],
        "email"=> $gorgias_credentials['requesterEmail']
        ),
      "source" => array(
        "type" => "ottspott-call",
        "from" => array(
          "name" => "Unknown yet",
          "address" => $obj->caller_id_number
          ),
        "to" => array(
          array(
            "name" => $gorgias_credentials['requesterName'],
            "address" => $obj->destination_number
            ),
          ),
        ),
      "body_text" => $call_details
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
  'Authorization: Basic ' . $gorgias_credentials['apiToken'],
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

if ($result["http_code"] != "200"){
  $response['ok'] = false;
  $response['message'] = 'Failed to create ticket at Gorgias, status code : ' . $result["http_code"];
} else {
  $response['ok'] = true;
  $response['message'] = 'Successfully created ticket at Gorgias.';
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/webhooks_gorgias.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

?>

