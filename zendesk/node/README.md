# Create a ticket in Zendesk when calls are placed or received in Ottspott

## What it does

Ticket creation is triggered by Ottspott phone events :
- New incoming call started
- Incoming call answered
- Incoming call ended and missed
- Incoming call ended and answered
- New outgoing call started
- Outgoing call ended
- Voicemail received

Ottspott passes as much information as possible but this webhook will try
to find a contact in Zendesk that matches with the phone number gotten from
Ottspott (caller or callee), in order create a ticket with relevant information
on success.

## Prerequisites

- Set up your account at Ottspott : https://app.ottspott.co
- Integrate Zendesk with Ottspott (check the `Integrations` section)

## How to test

This code can be tested locally using localtunnel (https://localtunnel.github.io),
or ngrok (https://ngrok.com/), or globally if you serve it from a publicly
accessible URL. We'll describe how to test it locally using localtunnel.

- Create directory
```
mkdir test_webhook_ottspott
```
- Copy the `create_ticket.js` file
- Go to `test_webhook_ottspott`, install required modules start program
```
cd test_webhook_ottspott
npm install --save express
npm install --save body-parser
npm install --save request-promise
node create_ticket.js
App listening on port 4443

```
- In another terminal, start localtunnel, e.g.
```
lt --port 4443 --subdomain mysubdomain
your url is: https://mysubdomain.localtunnel.me
```
- Now set this URL to the Webhook of your choice in Ottspott under the `Number management`
  section (those parameters can be easily retrieved from your Zendesk integration in Ottspott) :
```
https://mysubdomain.localtunnel.me/webhook/ottspott?domain=zendeskdomain&accessToken=XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```
