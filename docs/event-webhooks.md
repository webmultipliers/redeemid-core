# Event webhooks

RedeemID can send its [events](developer-api.md#the-event-stream-stable) as signed JSON to one address you choose: a CRM, an email service, Zapier, Make, or your own endpoint. Use it when another system needs to know what happened on the site, such as a reader running out of free articles or a member losing a level, without anyone writing PHP.

It doesn't replace your CRM's Shopify integration. Customers, tags, orders, preferences and the page a purchase started on already live in Shopify, and your CRM should read them there. Event webhooks carry what only WordPress sees: walls, the meter, checkouts started from a page, and the moment a level changes on the site.

## Turn it on

1. Go to **RedeemID → Settings → Event webhooks** and enter an https address under **Send events to**.
2. Tick the events to send. The default list leaves out `wall_shown` and `meter_used`, because they fire on page views and reads, so each one can mean a background job per view on a busy site.
3. Optionally tick **Include the Shopify customer ID for linked members** (see [Matching people](#matching-people)).
4. Save, open **Signing secret**, copy the secret into the receiver, then press **Send a test event**.

Clear the address to turn it off. Queued events that haven't gone yet are dropped.

## What is sent

One `POST` per event, with `Content-Type: application/json`:

```json
{
  "id": "6f1c1f8e-3d0a-4f36-9a7c-5f3f0c9b2d11",
  "site": "https://example.com/",
  "name": "meter_exhausted",
  "version": 1,
  "time": 1793548800,
  "props": { "post_id": 42, "limit": 3, "period": "month", "user_id": 7 },
  "customer": "gid://shopify/Customer/1234567890"
}
```

- `name`, `version`, `time` and `props` are the event's envelope, exactly as the `redeemid_event` action gets it. The [developer API](developer-api.md#the-event-stream-stable) lists every event and its props.
- `id` is unique per event and stays the same on every retry. Use it to drop duplicates.
- `customer` is there only when the setting is on and the user is linked to a Shopify customer. It is looked up when the event is sent.
- No event carries an email address, a name or Shopify tags.
- **Send a test event** sends `"name": "test"` with the staff member's `user_id`, straight away, and shows the answer's status.

Headers:

| Header | Value |
| --- | --- |
| `X-RedeemID-Event` | The event name |
| `X-RedeemID-Delivery` | The same value as `id` |
| `X-RedeemID-Signature` | `t=<unix time>,v1=<hex>` |
| `User-Agent` | `RedeemID/<plugin version>` |

## Check the signature

`v1` is the HMAC-SHA256 of `<t>.<raw body>`, keyed with the signing secret, as lowercase hex. Check it against the raw body (before any JSON parsing) with a constant-time compare, and refuse a `t` more than five minutes old, so a captured delivery can't be replayed.

```php
function verify_redeemid( string $body, string $header, string $secret ): bool {
	if ( ! preg_match( '/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m ) || abs( time() - (int) $m[1] ) > 300 ) {
		return false;
	}

	return hash_equals( hash_hmac( 'sha256', $m[1] . '.' . $body, $secret ), $m[2] );
}
```

```js
// Node
const crypto = require( 'crypto' );

function verifyRedeemid( body, header, secret ) {
	const m = /^t=(\d+),v1=([a-f0-9]{64})$/.exec( header || '' );
	if ( ! m || Math.abs( Date.now() / 1000 - Number( m[ 1 ] ) ) > 300 ) return false;
	const expected = crypto.createHmac( 'sha256', secret ).update( `${ m[ 1 ] }.${ body }` ).digest( 'hex' );
	return crypto.timingSafeEqual( Buffer.from( expected ), Buffer.from( m[ 2 ] ) );
}
```

No-code tools such as Zapier and Make can take the JSON without checking the signature. If you skip the check, keep the address secret, because anyone who knows it can post to it.

**Make a new secret** replaces the secret at once. Deliveries signed after that use the new one, so update every receiver.

## Delivery and retries

Events are queued and sent in the background, with Action Scheduler when it is installed and WP-Cron otherwise, so a page never waits for the receiver. A delivery counts only when the receiver answers 2xx within five seconds. Redirects are not followed.

A failed delivery is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours, which is six tries over about 15 hours. Then it is dropped. Retries and drops show under **RedeemID → App events** as `outbound.retry` and `outbound.dropped`, with the status the receiver gave. Deliveries can arrive out of order, so sort by `time`.

WordPress stores nothing new for this: no copy of sent events and no delivery table. The queue holds an event only until it is sent or dropped.

## Matching people

Events name people by WordPress user ID (`user_id`, 0 for guests), which your CRM doesn't know. With **Include the Shopify customer ID for linked members** on, events about a linked member also carry `customer`, the Shopify customer GID. A CRM or email service that syncs your Shopify customers can match on it. Guests and users who aren't linked never carry one.

## Privacy

An event sent is held by the receiver. Erasing a member's data in WordPress (Tools → Erase Personal Data) or in Shopify doesn't remove what the receiver already got. Name the receiver in your privacy policy. When you get an erasure request, pass it on to the receiver too, by the Shopify customer ID if you send it.

## Change or skip a payload from code

`redeemid_outbound_payload` filters each body before it is queued (the customer ID is added later). Return `null` to skip that event:

```php
add_filter( 'redeemid_outbound_payload', function ( ?array $payload ) {
	if ( $payload && 'checkout_started' === $payload['name'] && empty( $payload['props']['signed_in'] ) ) {
		return null; // Only signed-in checkouts.
	}

	return $payload;
} );
```

The address, events and customer setting can also be forced from code with `redeemid_setting_outbound_url`, `redeemid_setting_outbound_events` and `redeemid_setting_outbound_customer`.

To send to more than one place, point the address at your own endpoint or an automation tool and fan out from there, or subscribe to `redeemid_event` in PHP.
