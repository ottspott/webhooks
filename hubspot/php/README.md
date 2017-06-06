# Create an engagement in HubSpot when calls are placed or received in Ottspott

## Requirements

- PHP 5.5+

## What it does

Engagement creation is triggered by Ottspott phone events :
- New incoming call started
- Incoming call answered
- Incoming call ended and missed
- Incoming call ended and answered
- New outgoing call started
- Outgoing call ended
- Voicemail received

Ottspott passes as much information as possible but this webhook will try
to find a contact in HubSpot that matches with the phone number gotten from
Ottspott (caller or callee), in order create an engagement with relevant information
on success.
