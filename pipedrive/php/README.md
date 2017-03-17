# Create an activity in Pipedrive when calls are placed or received in Ottspott

## How to use it

Ottspott serves URLs of the form `https://ottspott-webhooks.apidaze.io/pipedrive/create_activity.php?apiToken=xxxxxxxxxxxxx&domain=mypipedrivedomain`. Just replace the `api_token` and `domain` parameter values with your personal information in Pipedrive, and attach the URL to the Webhooks of your choice in Ottspott.

## What it does

Activity creation is triggered by the Ottspott phone events listed below :
- New incoming call started
- Incoming call answered
- Incoming call ended and missed
- Incoming call ended and answered
- New outgoing call started
- Outgoing call ended
- Voicemail received

Ottspott passes as much information as possible including Pipedrive contact
details stored in Ottspott.
