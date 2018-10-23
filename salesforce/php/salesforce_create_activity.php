<?php

$date = date('d-m-Y G:i:s');
$access_token = '';

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
  $logs .= $key . ": " . json_encode($value) . "\n";
}

if (!isset($result['domain'])){
  $logs .= "Cannot find domain, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "domain is missing or invalid";
}

$domain = $result['domain'];

if (!isset($result['key'])){
  $logs .= "Cannot find refresh_token, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "refresh_token is missing or invalid";
}

$refresh_token = $result['key'];

if (!isset($result['user_id'])){
  $logs .= "Cannot find user_id, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "user_id is missing or invalid";
}

$salesforce_id = $result['user_id'];

if(!isset($result["dev"])) {
  $client_id = '3MVG9HxRZv05HarRN0RODQsIUrV9a5QnSNDdPI61Zp1teESXrWB1NzBW4eovNe.zt.r0V4Q22uQ8iFeoDGgwZ';
  $client_secret = '1699317100791549356';
  $manager = new MongoDB\Driver\Manager("mongodb://ubr6tmfyvub4cvm:9FNrHEkx0u8H2fmU0C1q@bwc5nednesjxfr2-mongodb.services.clever-cloud.com:2083/bwc5nednesjxfr2");
  $table_name = "bwc5nednesjxfr2.users";
} elseif ($result["dev"] == "dev") {
  $client_id = '3MVG9HxRZv05HarRN0RODQsIUrSqNuWqm81WtbrtKytJaaLUqdEnaqRvtPIHM_V5P3Tm7KOpzVE57Irgo3fnf';
  $client_secret = '3062949387980370332';
  $manager = new MongoDB\Driver\Manager("mongodb://urom99oo9qwydbi:e6kk2h1XfbUHqY2vfcFBbgaksat89ofrvkg@139.59.149.98:27017/ottspott_devel");
  $table_name = "ottspott_devel.users";
}

if($domain && $refresh_token && $salesforce_id) {

  $filter = [];
  $options = ["projection" => []];

  $params = array(
    "grant_type" => 'refresh_token',
    "client_id" => $client_id,
    "client_secret" => $client_secret,
    "refresh_token" => $refresh_token,
    "format" =>  "json"
  );
  $fields_string = json_encode($params);

  $ch = curl_init("https://login.salesforce.com/services/oauth2/token");
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
  curl_setopt($ch, CURLOPT_POSTFIELDS,$params);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
  $json = curl_exec($ch);
  curl_close($ch);
  $res = json_decode($json, true);
  $logs .= 'Access token: ' . $res["access_token"];  
  

  if($res["access_token"]) {
    $access_token = $res["access_token"];
  }
}  


if($access_token) {
if($obj->event === "outgoing_call_ended" || $obj->event === "new_outgoing_call") {  
  $phone = $obj->destination_number;
} else  {
  $phone = $obj->caller_id_number;
}
$logs .= 'PHONE: ' . $phone . "\n";
$contact = getSalesforceContact($phone, $access_token, $domain, 'Contact');
  
if($contact === null) {
     $lead = getSalesforceContact($phone, $access_token, $domain, 'Lead'); 
     if($lead === null) {
       $logs .= "Cannot find Salesforce contact or lead, returning.\n";
       $success = false;
       $response['ok'] = false;
       $response['message'] = "Salesforce contact or lead was not found";
    } else {
     $contact_name = " (" . $lead->FirstName . " " . $lead->LastName . ")"; 
     $contact_id =  $lead->Id;
     $success = true;
     $response['ok'] = true;
     $response['message'] = "Salesforce lead was found " . $contact_name;
    }
   } else {
     $contact_name = " (" . $contact->FirstName . " " . $contact->LastName . ")"; 
     $contact_id =  $contact->Id;
     $success = true;
     $response['ok'] = true;
     $response['message'] = "Salesforce contact was found " . $contact_name;
   }
} 
 
if ($success == false){
 sendResponseAndExit($response, $logs);
}


$logs .= " \n [". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$activity = array(
    "WhoId" => $contact_id,
    "Subject"  => "Call",
    "CallType" => "Inbound",
    "Status" => "Not Started",
    "Priority" => "Normal",
    "OwnerId" => $salesforce_id,
    "Description" => "",
    "CallDurationInSeconds" => 0,
    "ActivityDate" => time()*1000
);

$fplogs = fopen('/tmp/salesforce_create_activity.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);

$logs = "";
$json = ""; 

switch($obj->event){

  case "incoming_call_ended_and_missed":
    $activity["Description"] = " Missed call";
    $activity["Description"] .= "\n From : +" . $obj->caller_id_number . $contact_name;
    $activity["Description"] .= "\n To : +" . $obj->destination_number;
    $activity["Status"] = "Completed";
    $activity["Subject"] = "Missed call";
  break;

  case "incoming_call_ended_and_answered":
    $activity["Description"] =  $obj->detailed_status . " (duration : " . duration($obj->duration) . ") ";
    $activity["Description"] .= "\n From : +" . $obj->caller_id_number . $contact_name;
    $activity["Description"] .= "\n To : +" . $obj->destination_number;
    $activity["Status"] = 'Completed';
    $activity["CallDurationInSeconds"] = $obj->duration;
    $activity["Subject"] = $obj->detailed_status . " (duration : " . duration($obj->duration) . ") ";	
    if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
      $activity["Description"] .= "\n Recording: " . $obj->recording_url ;
    }

    try {
      $filter = ["profile.user_id" => $obj->answered_by, "profile.team_id" => $obj->slack_team_id];
      $query = new MongoDB\Driver\Query($filter, $options);
      $res = $manager->executeQuery($table_name , $query);
      foreach ($res as $doc) {
          $user = $doc;
      break;
      }

      if (!empty($user)) {
          $json .= 'user email ' . $user->profile->email;  
          $owner_id = getSalesForceUser($access_token, $user->profile->email, $domain);
          if($owner_id) {
            $json .= 'Owner Id ' . $owner_id;
            $activity["OwnerId"] = $owner_id;
          }
      }
    } catch (\MongoDB\Driver\Exception $e) {
        echo $e->getMessage(), "\n";
        $logs .= " USER DB REQUEST error ". $e->getMessage();
        exit;
    }

  break;

  case "outgoing_call_ended":
    $activity["Description"] = "Outgoing call ended (duration : " . duration($obj->duration) . ") ";
    $activity["Description"] .= "\n From : +" . $obj->caller_id_number;
    if (property_exists($obj, 'caller_id_name')) {
      $activity["Description"] .= "  (" . $obj->caller_id_name . ")";
    } 
    $activity["Description"] .=  "\n To : +" . $obj->destination_number . $contact_name;
    $activity["Status"] = 'Completed';
    $activity["CallDurationInSeconds"] = $obj->duration;
    $activity["CallType"] = "Outbound";
    $activity["Subject"] = "Outgoing call ended (duration : " . duration($obj->duration) . ") ";
    if (property_exists($obj, 'recorded') && $obj->recorded == "true"){
      $activity["Description"] .= "\n Recording: " . $obj->recording_url;
    }
    try {
      $filter = ["profile.name" => $obj->caller_id_name, "profile.team_id" => $obj->slack_team_id];
      $query = new MongoDB\Driver\Query($filter, $options);
      $res = $manager->executeQuery($table_name, $query);
       
  
  
      foreach ($res as $doc) {
        $user = $doc;
        break;
      }

      if (!empty($user)) {
          $json .= 'user email ' . $user->profile->email;  
          $owner_id = getSalesForceUser($access_token, $user->profile->email, $domain);
          if($owner_id) {
            $json .= 'Owner Id ' . $owner_id;
            $activity["OwnerId"] = $owner_id;
          }
      }
    } catch (\MongoDB\Driver\Exception $e) {
        echo $e->getMessage(), "\n";
        $logs .= " USER DB REQUEST error ". $e->getMessage();
        exit;
    }
  break;

  case "incoming_call_ended_and_voicemail_left":
    $activity["Description"] = "Received Voicemail (duration: " . duration($obj->voicemail_duration) . ")";
    $activity["Subject"] = "Received Voicemail (duration: " . duration($obj->voicemail_duration) . ")";
    $activity["Description"] .= "\n Voicemail: " .  $obj->voicemail_url;
    $activity["Description"] .= "\n From : +" . $obj->caller_id_number . $contact_name ;
    $activity["Description"] .= "\n To : +" . $obj->destination_number;
    $activity["Description"] .= "\n Transcription : " . $obj->voicemail_transcription;
    $activity["Status"] = 'Completed';
    $activity["CallDurationInSeconds"] = $obj->voicemail_duration;
  break;

}
$logs .= "[". $date . " - " . __FILE__ . "] JSON SALESFORCE USER :\n";
$logs .= $json;
$logs .= "\n";


// Now send request to Salesforce
$url = "https://" . $domain . ".salesforce.com/services/data/v21.0/sobjects/Task/";
$activity_string = json_encode($activity);
$fplogs = fopen('/tmp/salesforce_create_activity.txt', 'a+');
  fwrite($fplogs, $activity_string);
  fclose($fplogs);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $activity_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
            'Accept: application/json',        
            'Authorization: Bearer ' . $access_token)
);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// create an array from the data that is sent back from the API
$result = json_decode($output, 1);
$logs .= "\n RESULT " . $output . "\n";
// check if an id came back
/*
{
    "id": "00T0Y00000D9vsDUAR",
    "success": true,
    "errors": []
}
*/
if ($result['success'] === true) {
  $activity = $result['id'];
  $logs .= "Created activity : " . $activity_string . " " . $result["id"] . "\n" ;
  $logs .= "JSON : " . $output . "\n";
  $success = true;
  $response['ok'] = true;
  $response['message'] .= " Successfully created activity";
} else {
  $logs .= "Failed to create activity.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] .= " Failed to create activity at Salesforce " + json_encode($result["errors"]);
}

sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);

  $fplogs = fopen('/tmp/salesforce_create_activity.txt', 'a+');
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
 * A function to search Salesforce contact by phone
 *
 * @phone       concact phone in international format 
 */

function getSalesforceContact($phone, $access_token, $domain, $name)
{
  $url = "https://" . $domain . ".salesforce.com/services/data/v36.0/parameterizedSearch";
$params = array(
    "q" => $phone,
    "fields" => ["id", "firstName", "lastName", "phone"],
  "sobjects" => [ array("name" => $name)],
    "in" => "ALL"
);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST,true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Accept: application/json',
      'Authorization: Bearer ' . $access_token
  ));

  $output = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);

$result = json_decode($output);

  if(isset($result[0]->Id)) {
    return  $result[0];
  } else {
    return null;
  }

}
function getSalesForceUser($access_token, $email, $domain){
  $url = "https://" . $domain . ".salesforce.com/services/data/v36.0/parameterizedSearch";
$params = array(
    "q" => $email,
    "fields" => ["id"],
  "sobjects" => [ array("name" => "User")],
    "in" => "ALL"
);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST,true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Accept: application/json',
      'Authorization: Bearer ' . $access_token
  ));

  $output = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);

$result = json_decode($output);

  if(isset($result[0]->Id)) {
    return  $result[0]->Id;
  } else {
    return null;
  }

}
?>

