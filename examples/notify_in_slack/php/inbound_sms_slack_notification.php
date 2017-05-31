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

// check token 

if (($result['token'])==""){
  $logs .= "Cannot find token bot slack, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "token  slack value is zero";
}


if (!isset($result['token'])){
  $logs .= "Cannot find token bot slack, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "token  slack is missing or invalid";
}

if (($result['slack_channel_id'])==""){
  $logs .= "Cannot find channel id slack, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "channel slack value is zero";
}


if (!isset($result['slack_channel_id'])){
  $logs .= "Cannot find channel id slack, returning.\n";
  $success = false;
  $response['ok'] = false;
  $response['message'] = "channel slack is missing or invalid";
}

if ($success == false){
  sendResponseAndExit($response, $logs);
}

//stock valeur token and channek id  slack
$token_bot = ($result['token']);
$channel_id = ($result['slack_channel_id']);

$logs .= "[". $date . " - " . __FILE__ . "] JSON RECEIVED FROM OTTSPOTT :\n";
$logs .= $json;
$logs .= "\n";

$fplogs = fopen('/tmp/log_test_yann.txt', 'a+');
fwrite($fplogs, $logs);
fclose($fplogs);


$logs = "";


// send message to slack 

$message = ($obj->message);
$caller_id_number = ($obj->caller_id_number);
$name_contact = ($obj->contact_data->title);
$company_contact = ($obj->contact_data->company);

if (!isset($name_contact)){
$attachments = array([
            'fallback' => 'text',
            'pretext'  => 'SMS received',
            'text' =>  "*" .'From:' ."*" .' ' .'+'.$caller_id_number ."\n" .$message,
            'color'    => '#7CD197',
            'mrkdwn_in' => array (
                    'text',
                    'pretext'
            )
        ]);

} elseif (isset($name_contact)&&(!isset($company_contact))) {
$attachments = array([
            'fallback' => 'text',
            'pretext'  => 'SMS received',
            'text' =>  "*" .'From:' ."*" .' ' .'+'.$caller_id_number .' ' .'(' .$name_contact .')' ."\n" .$message,
            'color'    => '#7CD197',
            'mrkdwn_in' => array (
                    'text',
                    'pretext'
            )
        ]);
} else {
$attachments = array([
            'fallback' => 'text',
            'pretext'  => 'SMS received',
            'text' =>  "*" .'From:' ."*" .' ' .'+'.$caller_id_number .' ' .'(' .$name_contact .' ' .$company_contact .')'  ."\n" .$message,
            'color'    => '#7CD197',
            'mrkdwn_in' => array (
                    'text',
                    'pretext'
            )
        ]);
}

$json_attachments = json_encode($attachments);
 $ch = curl_init("https://slack.com/api/chat.postMessage");
    $data = http_build_query([
        "token" => $token_bot,
        "channel" => $channel_id,
        "attachments" => $json_attachments,
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);

    return $return;





$result = json_decode($return, 1);

if (!empty ($result['ok'])) {
  $logs .= "JSON : " . $return . "\n";
  $success = true;
  $result['ok'] = true;
  $response['message'] = "Successfully message sent";
  echo ("response vaut $response");
}else {
  $logs .= "Failed to send message.\n";
  $success = false;
  $result['ok'] = false;
  $response['message'] = "Failed to send message on Slack";
}


sendResponseAndExit($response, $logs);

function sendResponseAndExit($response, $logs){
  header('Content-Type: application/json');
  echo json_encode($response);
  $fplogs = fopen('/tmp/log_test_yann.txt', 'a+');
  fwrite($fplogs, $logs);
  fclose($fplogs);
  exit;
}

?>

