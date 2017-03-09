/**
 * @file 
 * An Ottspott Webhook that creates a ticket in Zendesk.
 */

var rp = require('request-promise');
var express = require('express');
var bodyParser = require ('body-parser');
var app = express();

app.use(bodyParser.json());

app.post("/webhook/ottspott", function(request, response) {
  console.log("URL " + request.url); 
  console.log("Received JSON from Ottspott : " + JSON.stringify(request.body));

  if (typeof request.body === "undefined" || typeof request.body.event === "undefined"){
    console.log("Empty or invalid body in request, returning");
    response.json({ok: false});
    return;
  }

  if (typeof request.query.accessToken === "undefined" || request.query.domain === "undefined"){
    console.log("accessToken or Zendesk domain undefined, query : " + JSON.stringify(request.query));
    response.json({ok: false});
    return;
  }

  var zendesk_credentials = {
    domain : request.query.domain,
    accessToken: request.query.accessToken
  }

  var user = {};
  var subject = "";
  var requester = {};
  var body = "";

  switch (request.body.event){
    case 'new_incoming_call':
      subject = "Incoming call started";
      user = searchUserByPhone(zendesk_credentials, request.body.caller_id_number);
      requester = buildZendeskRequester(user, request.body.caller_id_name, request.body.caller_id_number);
      body = "Call has just started";
      break;
    case 'incoming_call_answered':
      subject = "Incoming call answered";
      user = searchUserByPhone(zendesk_credentials, request.body.caller_id_number);
      requester = buildZendeskRequester(user, request.body.caller_id_name, request.body.caller_id_number);
      body = request.body.detailed_status;
      break;
    case 'incoming_call_ended_and_missed':
      subject = "Missed call";
      user = searchUserByPhone(zendesk_credentials, request.body.caller_id_number);
      requester = buildZendeskRequester(user, request.body.caller_id_name, request.body.caller_id_number);
      body = "Call has been left unanswered";
      break;
    case 'incoming_call_ended_and_answered':
      subject = "Incoming call ended";
      user = searchUserByPhone(zendesk_credentials, request.body.caller_id_number);
      requester = buildZendeskRequester(user, request.body.caller_id_name, request.body.caller_id_number);
      body = request.body.detailed_status + " (duration : " + niceDuration(request.body.duration) + ")";
      break;
    case 'new_outgoing_call':
      subject = "Outgoing call started";
      user = searchUserByPhone(zendesk_credentials, request.body.destination_number);
      requester = buildZendeskRequester(user, request.body.callee_id_name, request.body.destination_number);
      body = "Call has just started";
      break;
    case 'outgoing_call_ended':
      subject = "Outgoing call ended";
      user = searchUserByPhone(zendesk_credentials, request.body.destination_number);
      requester = buildZendeskRequester(user, request.body.callee_id_name, request.body.destination_number);
      body = request.body.detailed_status + " (duration : " + niceDuration(request.body.duration) + ")";
      break;
    case 'incoming_call_ended_and_voicemail_left':
      subject = "Received Voicemail";
      user = searchUserByPhone(zendesk_credentials, request.body.caller_id_number);
      requester = buildZendeskRequester(user, request.body.callee_id_name, request.body.destination_number);
      body = "Voicemail has just been left";
      break;
    case 'sms_received':
      break;
    case 'sms_sent':
      break;
  }

  var url = "https://" +  zendesk_credentials.domain + ".zendesk.com/api/v2/tickets.json";

  var options = {
    method: "POST",
    uri: url,
    headers: {
      "Authorization": "Bearer " + zendesk_credentials.accessToken,
      "Content-type" : "application/json",
      "Accept": "application/json"
    },
    body: {
      ticket: {
        type: "call",
        tags: ['Ottspott', 'call'],
        subject: subject,
        comment: {
          body: body
        },
        requester: requester,
        priority: "normal"
      },
    },
    json: true
  };

  rp(options)
    .then(function (response) {
      console.log("Submitted Zendesk ticket : " + JSON.stringify(response));
      response.status(response.statusCode);
      response.json({ok: true, message: "Ticket created"});
    })
  .catch(function (error) {
    console.log("Submitted Zendesk, got error : " + JSON.stringify(error));
    response.status(error.statusCode);
    response.json({ok: false, message: error.error});
  });
});

app.listen(4443, function () {
  console.log('App listening on port 4443');
});

/**
 * Tries to find a user in Zendesk from a phone number in Ottspott.
 *
 * Query Zendesk to find a user from a phone number using Zendesk's search
 * API. Return the first user that matches on success, or an empty value
 * if nothing has been found. 
 *
 * @param {Object} zendesk_credentials An object that contains Zendesk
 *   domain and accessToken.
 * @param string phonNumber The phone number to search the user
 *
 * @return {Object}
 */
function searchUserByPhone(zendesk_credentials, phoneNumber){
  var url = "https://" +  zendesk_credentials.domain + ".zendesk.com/api/v2/search.json?";

  var options = {
    uri: url,
    qs: {
      query: "type:user phone:" + phoneNumber
    },
    headers: {
      "Authorization": "Bearer " + zendesk_credentials.accessToken,
      "Content-type" : "application/json",
      "Accept": "application/json"
    },
    json: true
  };

  rp(options)
    .then(function (response) {
      console.log("Queried Zendesk : " + JSON.stringify(response));
      return response.results[0];
    })
  .catch(function (error) {
    console.log("Queried Zendesk, got error : " + JSON.stringify(error));
    return {};
  });
}

/**
 * Builds 'requester' field in a Zendesk creation ticket request.
 *
 * Depending on the user search result we have from Zendesk for a given
 * phone number, we're able to populate this field with relevant Zendesk
 * information for this user. If we don't find anything in Zendesk, then
 * we rely on the information gotten from Ottspott, if any.
 *
 * @param {Object} user The user object retrieved from Zendesk, can be empty.
 * @param string name The name of the contact in the associated call.
 * @param string number The phone number of the caller or callee in
 *   the associated call.
 *
 * @return {Object} The requester object to send in the Zendesk ticket creation
 *   request.
 */
function buildZendeskRequester(user, name, phoneNumber){
  var requester = {
    locale_id: 1
  };

  if (isEmpty(user)){
    requester.name = typeof name === "undefined" ? "+" + phoneNumber : name + "(+" + phoneNumber + ")";
  } else {
    requester.name = user.name;
    requester.email = user.email;
  }

  return requester;
}

function niceDuration(duration){
  return Math.floor(duration / 60) + "m " + duration % 60 + "s";
}

/**
 * Check if a given object is empty.
 *
 * See  http://stackoverflow.com/questions/679915/how-do-i-test-for-an-empty-javascript-object
 */
function isEmpty(obj){
  if (obj === null || typeof obj === "undefined") {
    return true;
  }

  return Object.keys(obj).length === 0 && obj.constructor === Object;
}
