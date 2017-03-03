<?php

// load Composer
require 'vendor/autoload.php';

use Zendesk\API\HttpClient as ZendeskAPI;

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

if (!isset($result['accessToken'])){
	$logs .= "Cannot find accessToken, returning.\n";
	$success = false;
	$response['ok'] = false;
	$response['message'] = "accessToken is missing or invalid";
}

if (!isset($result['domain'])){
	$logs .= "Cannot find domain, returning.\n";
	$success = false;
	$response['ok'] = false;
	$response['message'] = "Zendesk domain is missing or invalid";
}

if ($success == false){
	sendResponseAndExit($response, $logs);
}

$client = new ZendeskAPI($result['domain']);
$client->setAuth(\Zendesk\API\Utilities\Auth::OAUTH, ['token' => $result['accessToken']]);

$logs .= "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/zendesk_create_ticket.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$logs = "";

if (!property_exists($obj, 'caller_id_name')) {
	$obj->caller_id_name = "";
}

if (!property_exists($obj, 'callee_name')) {
	$obj->callee_name = "";
}

switch($obj->event){
	case "new_incoming_call":
		$subject = "Incoming call started";
		$user = searchUserByPhone($client, $obj->caller_id_number);
		$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);
		$body = "Call has just started";
		break;
	case "incoming_call_answered":
		$subject = "Incoming call answered";
		$user = searchUserByPhone($client, $obj->caller_id_number);
		$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);
		$body = $obj->detailed_status;
		break;
	case "incoming_call_ended_and_missed":
		$subject = "Missed call";
		$user = searchUserByPhone($client, $obj->caller_id_number);
		$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);
		$body = "Call has been left unanswered";
		break;
	case "incoming_call_ended_and_answered":
		$subject = "Incoming call ended";
		$user = searchUserByPhone($client, $obj->caller_id_number);
		$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);
		$body = $obj->detailed_status . " (duration : " . duration($obj->duration) . ")";
		break;
	case "new_outgoing_call":
		$subject = "Outgoing call started";
		$user = searchUserByPhone($client, $obj->destination_number);
		$requester = buildZendeskRequester($user, $obj->callee_name, $obj->destination_number);
		$body = "Call has just started";
		break;
	case "outgoing_call_ended":
		$subject = "Outgoing call ended";
		$user = searchUserByPhone($client, $obj->destination_number);
		$requester = buildZendeskRequester($user, $obj->callee_name, $obj->destination_number);
		$body = $obj->detailed_status . " (duration : " . duration($obj->duration) . ")";
		break;
	case "incoming_call_ended_and_voicemail_left":
		$subject = "Received Voicemail";
		$user = searchUserByPhone($client, $obj->caller_id_number);
		$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);
		$body = "Voicemail has just been left";
		break;
}

try {
	// Create a new ticket wi
	$newTicket = $client->tickets()->create(array(
		'type' => 'call',
		'tags'  => array('Ottspott', 'call'),
		'subject'  => $subject,
		'comment'  => array(
			'body' => $body
		),
		'requester' => $requester,
		'priority' => 'normal',
	));

	$response['ok'] = true;
	$response['message'] = "Ticket created";
	$logs = "[". $date . " - " . __FILE__ . "] Ticket created : " . json_encode($newTicket) . "\n";
	sendResponseAndExit($response, $logs);

} catch (\Zendesk\API\Exceptions\ApiResponseException $e) {
	$response['ok'] = false;
	$response['message'] = $e->getMessage();
	$logs = "[". $date . " - " . __FILE__ . "] Error : " . $e->getMessage();
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
	header('Content-Type: application/json');
	echo json_encode($response);

	$fplogs = fopen('/tmp/zendesk_create_ticket.txt', 'a+');
	fwrite($fplogs, $logs);
	fclose($fplogs);
	exit;
}

/**
 * A function for making time periods readable
 *
 * @link        https://snippets.aktagon.com/snippets/122-how-to-format-number-of-seconds-as-duration-with-php
 * @param       int     number of seconds elapsed
 *
 * @return		string
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
 * Builds 'requester' field in a Zendesk creation ticket request.
 *
 * Depending on the user search result we have from Zendesk for a given
 * phone number, we're able to populate this field with relevant Zendesk
 * information for this user. If we don't find anything in Zendesk, then
 * we rely on the information gotten from Ottspott, if any.
 *
 * @param array $user The user object retrieved from Zendesk, can be empty.
 * @param string $name The name of the contact in the associated call.
 * @param string $number The phone number of the caller or callee in
 *   the associated call.
 *
 * @return array The requester array to send in the Zendesk ticket creation
 *   request.
 */
function buildZendeskRequester($user, $name, $number){
	if (!empty($user)){
		$requester = array(
			'locale_id' => '1',
			'name' => $user->name,
			'email' => $user->email,
		);
	} else {
		if (empty($name)){
			$requester = array(
				'locale_id' => '1',
				'name' => '+' . $number,
			);
		} else {
			$requester = array(
				'locale_id' => '1',
				'name' => $name . ' (+' . $number . ')',
			);
		}
	}

	return $requester;
}

/**
 * Tries to find a user in Zendesk from a phone number in Ottspott.
 *
 * Query Zendesk to find a user from a phone number using Zendesk's search
 * API. Return the first user that matches on success, or an empty value
 * if nothing has been found. 
 *
 * @param array $client The Zendesk client instance.
 * @param string $phone The phone number to search the user
 *
 * @return array
 */
function searchUserByPhone($client, $phone){
  $params = array('query' => 'type:user phone:' . $phone);
  $search = $client->users()->search($params);

  $fplogs = fopen('/tmp/zendesk_create_ticket.txt', 'a+');
  if (empty($search->users)) {
    $logs = 'Cannot find contact for phone ' . $phone . "\n";
  } else {
    $logs = 'Found user for phone ' . $phone . ' : ' . json_encode($search->users[0]) . "\n";
  }
  fwrite($fplogs, $logs);
  fclose($fplogs);

  if (empty($search->users)){
    return $search->users;
  }

  return $search->users[0];
}

?>

