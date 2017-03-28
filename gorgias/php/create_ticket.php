<?php

$date = date('d-m-Y G:i:s');

$json = file_get_contents('php://input');
$obj = json_decode($json);

$logs = "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$gorgias_domain = "mycompany";
$gorgias_token = "yourapitokenatgorgias";
$requester_name = "Ottspott Logger";
$requester_email = "support@mycompany.com";
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
}

// Build Gorgias ticket data
$data = array(
	"subject" => $subject,
	"sender" => array(
		"name"=> $requester_name,
		"email"=> $requester_email
	),
	"requester" => array(
		"name"=> $requester_name,
		"email"=> $requester_email
	),
	"receiver" => array(
		"name"=> $requester_name,
		"email"=> $requester_email
	),
	"channel" => "phone",
	"via" => "phone",
	"messages" => 	array(
		array(
			"public" => true,
			"channel" => "phone",
			"via" => "phone",
			"receiver" => array(
				"name"=> $requester_name,
				"email"=> $requester_email
			),
			"sender" => array(
				"name"=> $requester_name,
				"email"=> $requester_email
			),
			"source" => array(
				"type" => "ottspott-call",
				"from" => array(
					"name" => "Unknown yet",
					"address" => $obj->caller_id_number				
				),
				"to" => array(
					array(
						"name" => "Foodcheri",
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
curl_setopt($ch, CURLOPT_URL, "https://" . $gorgias_domain . ".gorgias.io/api/tickets/");
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	'Content-Type: application/json',
	'Authorization: Basic ' . $gorgias_token,
	'Cache-Control: no-cache',
	'Content-Length: ' . strlen($data_string)
));

$logs .= "\n";
$logs .= "[". $date . " - " . __FILE__ . "] TO SEND :\n";
$logs .= json_encode($data);
$logs .= "\n";

$output = curl_exec($ch);
$result = curl_getinfo($ch);

$logs .= "\n";
$logs .= "[". $date . " - " . __FILE__ . "] HTTP CODE FROM GORGIAS : " . $result["http_code"];
$logs .= "\n";
$logs .= "\n";
$logs .= "\n";

curl_close($ch);
$fplogs = fopen('/tmp/webhooks_gorgias.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);


?>

