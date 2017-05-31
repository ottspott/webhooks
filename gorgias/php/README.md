# Create ticket in Gorgias when calls are placed or received in Ottspott

Ticket creation is triggered by the Ottspott phone events listed below :
- New incoming call started
- Incoming call answered
- Incoming call ended and missed
- Incoming call ended and answered
- New outgoing call started
- Outgoing call ended
- Voicemail received

Ottspott passes as much information as possible including contact identification.
Bear in mind that contacts in Gorgias are not imported in Ottspott, so that
your Gorgias account has to do any matching internally.

Here is an example of a URL to set as a webhook in Ottspott :
```
https://ottspott-webhooks.apidaze.io/gorgias/create_ticket.php?apiToken=yourGorgiasAPIToken&domain=yourDomain&requesterEmail=support@domain.com&requesterName=Ottspott
```
