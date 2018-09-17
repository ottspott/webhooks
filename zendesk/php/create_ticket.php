<?php

// load Composer
require 'vendor/autoload.php';

use Zendesk\API\HttpClient as ZendeskAPI;

$date = date('d-m-Y G:i:s');

$success = true;
$json = file_get_contents('php://input');
$obj = json_decode($json);
$url_parts = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

$params = explode('&', $url_parts);

$logs = "\n[". $date . " - " . __FILE__ . "] \nURL PARAMETERS RECEIVED FROM OTTSPOTT :\n";

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
$client->setAuth('oauth', ['username' => $result['zendesk_username'], 'token' => $result['accessToken']]);

$logs .= "\n[". $date . " - " . __FILE__ . "] \nJSON RECEIVED FROM OTTSPOTT :\n";
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

$call_from = $obj->caller_id_number;
$call_to = $obj->destination_number;
$raw_time = str_ireplace("T", " ", $obj->created_at);
$time_of_call = substr($raw_time, 0, 18);
$call_length = duration($obj->duration);

switch($obj->event){
  case "new_incoming_call":
    $subject = "Ottspott (incoming call)";
    $ticket_status = "open";
    $user = searchUserByPhone($client, $obj->destination_number);
    $internal_note = "<b><u>Call details:</u><br>Call from:</b> +" . $call_from . "<br><b>Call to:</b> +" . $call_to;
    $public = true;
    break;
  case "incoming_call_answered":
    $subject = "Ottspott (incoming call)";
    $ticket_status = "open";
    $user = searchUserByPhone($client, $obj->caller_id_number);
    $internal_note = "<b><u>Call details:</u><br>Call from:</b> +" . $call_from . "<br><b>Call to:</b> +" . $call_to;
    $public = true;
    break;
  case "incoming_call_ended_and_missed":
    $subject = "Ottspott (missed call)";
    $ticket_status = "new";
    $user = searchUserByPhone($client, $obj->caller_id_number);
    $public = true;
    $internal_note = "<b><u>Call details:</u><br>Call from:</b> +" . $call_from . "<br><b>Call to:</b> +" . $call_to . "<br>Time of call:</b> " . $time_of_call . "<br><b>This call was missed</b>";
    break;
  case "incoming_call_ended_and_answered":
    $subject = "Ottspott (incoming call)";
    $ticket_status = "open";
    $public = false;
    $user = searchUserByPhone($client, $obj->caller_id_number);
    $comment_body = $obj->detailed_status . " (duration : " . duration($obj->duration) . ")";
    $agent = searchUserByEmail($client, $obj->slack_user_email);
    if (empty($agent)){
      $agent = searchUserByEmail($client, $obj->zendesk_username);
    }
    $agent_name = $agent->name;
    if ($obj->recorded !== true){
      $internal_note = "<u><b>Call details:</u><br>Time of call:</b> " . $time_of_call . "<br><b>Answered by:</b> " . $agent->name . "<br><b>Length of phone call:</b> " . $call_length;
    }
    else {
      $internal_note = "<u><b>Call details:</u><br>Time of call:</b> " . $time_of_call . "<br><b>Answered by:</b> " . $agent->name . "<br><b>Length of phone call:</b> " . $call_length . "<br><b>This call was recorded!<br>File:</b> <a href=\"" . $obj->recording_url . "\">open</a>";
    }
    break;
  case "new_outgoing_call":
    $subject = "Ottspott (outgoing call)";
    $public = true;
    $ticket_status = "new";
    $user = searchUserByPhone($client, $obj->destination_number);
    $internal_note = "<u><b>Call details:</u><br>Call from:</b> +" . $call_from . "<br><b>Call to:</b> +" . $call_to;
    break;
  case "outgoing_call_ended":
    $subject = "Ottspott (outgoing call)";
    $ticket_status = "open";
    $public = false;
    $user = searchUserByPhone($client, $obj->destination_number);
    if ($obj->recorded !== true){
      $internal_note = "<u><b>Call details:</u><br>Time of call:</b> " . $time_of_call . "<br><b>Length of phone call:</b> " . $call_length;
    }
    else {
      $internal_note = "<u><b>Call details:</u><br>Time of call:</b> " . $time_of_call . "<br><b>Length of phone call:</b> " . $call_length . "<br><b>This call was recorded!<br>File:</b> <a href=\"" . $obj->recording_url . "\">open</a>";
    }
    break;
  case "incoming_call_ended_and_voicemail_left":
    $subject = "Ottspott (missed call, voicemail left)";
    $ticket_status = "new";
    $public = false;
    $user = searchUserByPhone($client, $obj->caller_id_number);
    if ($obj->voicemail_transcription !== "") {
      $internal_note = "<u><b>Call details:</u><br>This call was missed, voicemail has just been left<br>File:</b> <a href=\"" . $obj->voicemail_url . "\">open</a>";
    }
    else {
      $internal_note = "<u><b>Call details:</u><br>This call was missed, voicemail has just been left<br>File:</b> <a href=\"" . $obj->voicemail_url . "\">open</a><br><b>Text:</b> " . $obj->voicemail_transcription;
    }
    break;
}

$requester = buildZendeskRequester($user, $obj->caller_id_name, $obj->caller_id_number);

if (property_exists($obj, 'ticket_id')) {
  try {
    $updateTicket = $client->tickets()->update($obj->ticket_id, [
      'comment'  => [
        'html_body' => $internal_note,
        'public' => $public
      ],
      'status' => $ticket_status,
      'requester' => $requester
    ]);
    $logs = "\n[". $date . " - " . __FILE__ . "] \nTICKET UPDATED :\n " . json_encode($updateTicket) . "\n";
    echo json_encode($updateTicket);
    sendResponseAndExit($response, $logs);

  } catch (\Zendesk\API\Exceptions\ApiResponseException $e) {
    echo json_encode($updateTicket);
    $response['ok'] = false;
    $response['message'] = $e->getMessage();
    $logs = "[". $date . " - " . __FILE__ . "] Error : " . $e->getMessage();
  }
  sendResponseAndExit($response, $logs);
}
else {
  try {
    $newTicket = $client->tickets()->create(array(
      'tags'  => array('Ottspott', 'call'),
      'subject'  => $subject,
      'comment'  => array(
        'html_body' => $internal_note,
        'public' => $public
      ),
      'status' => $ticket_status,
      'requester' => $requester,
      'priority' => 'normal',
    ));
    $logs = "\n[". $date . " - " . __FILE__ . "] \nTICKET CREATED\n : " . json_encode($newTicket) . "\n";
    echo json_encode($newTicket);
    sendResponseAndExit($response, $logs);
  } 
  catch (\Zendesk\API\Exceptions\ApiResponseException $e) {
    echo json_encode($newTicket);
    $response['ok'] = false;
    $response['message'] = $e->getMessage();
    $logs = "[". $date . " - " . __FILE__ . "] Error : " . $e->getMessage();
  }
sendResponseAndExit($response, $logs);
}

function sendResponseAndExit($response, $logs){
  
  $fplogs = fopen('/tmp/zendesk_create_ticket.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

/**
 * A function for making time periods readable
 *
 * @link        https://snippets.aktagon.com/snippets/122-how-to-format-number-of-seconds-as-duration-with-php
 * @param       int number of seconds elapsed
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
        'name' => $name,
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

function searchUserByEmail($client, $email){
  $params = array('query' => 'type:user email:' . $email);
  $search = $client->users()->search($params);

  $fplogs = fopen('/tmp/zendesk_create_ticket.txt', 'a+');
  if (empty($search->users)) {
    $logs = 'Cannot find agent for email ' . $email . "\n";
  } else {
    $logs = 'Found agent for email ' . $email . ' : ' . json_encode($search->users[0]) . "\n";
  }
  fwrite($fplogs, $logs);
  fclose($fplogs);

  if (empty($search->users)){
    return $search->users;
  }

  return $search->users[0];
}

?>