# The Overwatch feed

civi's "Overwatch" contract is unknown (civi is unreachable), so the feed is
generic, versioned and read-only. Both a pull endpoint and a signed push exist;
civi picks.

## Pull

`GET /council/api/v1/feed?since=<seq>` (authorization ability `feed`) returns
`change-council/feed.v1` ([schema](../resources/schema/feed.v1.json)):

```json
{
  "schema": "change-council/feed.v1",
  "origin": "fancy",
  "cursor": 42,
  "events": [
    {"seq": 41, "at": "2026-10-27T12:00:00.000000Z", "change": "CC-fancy-0001", "actor": "tally",
     "type": "round.closed", "data": {"round": 1, "outcome": "approved", "reason": "[text in the lab]", "counts": {"approve": 1}}, "hash": "<64 hex>"}
  ],
  "changes": [{"ref": "CC-fancy-0001", "origin": "fancy", "title": "...", "state": "approved", "current_round": 1, "...": "..."}]
}
```

- `events` are the audit log after `since`, in order, at most 200 per page.
  Pass `cursor` back as `since`. Each event's `hash` is its link in the audit
  chain, so a reader can check continuity.
- `changes` snapshots every Change the page's events touched.
- `origin` is on every page, so one Overwatch can merge several labs
  (fancy-labs, Prism's lab).
- Reply free text never appears: a `note` or `reason` in event data is replaced
  by `[text in the lab]`.

## Push

Set `COUNCIL_FEED_WEBHOOK_URL` and schedule `php artisan council:push-feed`.
Each run POSTs the next page with:

```
Content-Type: application/json
Council-Key-Id: <key_id>
Council-Signature: t=<unix seconds>,ed25519=<base64 signature over "<t>.<body>">
```

A receiver verifies with the lab's public key and rejects a `t` more than 300s
from its clock (`WebhookEmitter::verify` is the reference). A 2xx advances the
cursor. Anything else backs off 2^attempts minutes (capped at 60) and, after
`council.feed.max_attempts` (default 8), dead-letters: it stops, and
`council_webhooks.dead_lettered_at` is set for the dashboard.

## Versioning

Additive changes stay `feed.v1`: a new event type, a new optional field, a new
state or class. Readers must ignore what they do not know. Removing or renaming
anything is `feed.v2`, served beside v1.

## Open questions for civi (through the owner)

1. Pull or push? Where is Overwatch hosted, and can it reach a `.gen` lab, or
   must the lab push out?
2. Auth: is the Ed25519 signature acceptable for push, and what credential
   does Overwatch present for pull (the host maps it to the `feed` ability)?
3. Does it need every event, or Change snapshots only? Retention and backfill?
4. Will it ever need to write back (owner decisions from Overwatch)? v1 says no.
5. Will it aggregate several origins? Every page already carries `origin`.
