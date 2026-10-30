# Handling privacy requests

Every privacy request for a member is handled in WordPress's own tools, **Tools → Export Personal Data** and **Tools → Erase Personal Data**, whether the person asked on this site, from the Member Account block, or through your Shopify store. Each request has a **Shopify** column that says what happened on the Shopify side.

Shopify stays the record of the customer. WordPress never erases Shopify data itself; it can only ask Shopify to, and Shopify's own `customers/redact` finishes the job here.

## Where requests come from

| Started | What arrives in Tools | What staff do |
| --- | --- | --- |
| The person, on WordPress's or the Member Account block's form | A pending request; WordPress emails the person to confirm | Wait for confirmation, then export or erase as usual |
| Shopify: the store owner requests a customer's data | A **confirmed** export request, marked "From Shopify data request #…" | Download the export and send it to the store owner, then mark the request complete |
| Shopify: the store owner erases a customer | An erase request marked "Shopify redacted this customer". RedeemID's data is already gone | If the WordPress account stays, decide whether to erase the rest of their WordPress data (comments, other plugins), or mark the request complete. A shadow user is deleted, so that request is already complete |
| Shopify: the app is uninstalled (`shop/redact`) | Nothing per person. Every account is unlinked and customer numbers are cleared from the logs | Nothing |

Shopify sends its requests only to apps that declare the compliance topics. The setup wizard's **Register webhooks** step shows the lines to add to your Dev Dashboard app, with this site's URL. RedeemID → Debug shows when the last one arrived.

## Exporting

RedeemID's part of the export ("Shopify link and synced data") holds:

- the synced Shopify fields: customer ID and number, email, name, tags, status, marketing consent, purchase summary, membership dates and level history, sync times
- the members-only items read this period, if the site meters reading
- when they last started a checkout from the site, and any cart in progress
- their rows in RedeemID's activity log and the webhook deliveries about their customer number, from the last 30 days

Shopify holds the rest: orders, addresses and the full customer record. Shopify has no API to start its customer data report, so the Shopify column links to the customer in Shopify admin, where **More actions → Request customer data** sends the report to the store owner.

## Erasing

RedeemID's eraser removes the Shopify link, the synced fields, tokens, the meter and the cart, and strips the person from the activity log and webhook deliveries. The rows stay, without anything that points to the person, so delivery counts and reports stay right. The WordPress account stays: delete the user too if the request covers it.

Access ends here at once, because WordPress no longer holds the tags. If the person signs in with Shopify again, they are linked again and their access comes back, which is right while Shopify still grants it.

### Asking Shopify to erase too

An erase request's Shopify column offers **Also request erasure in Shopify**. It asks Shopify to erase the customer's personal data across the whole store, orders included:

1. Shopify queues the erasure and waits before carrying it out (10 days at the time of writing). **Cancel the Shopify erasure** on the same row withdraws it until then.
2. When Shopify erases the customer, it sends `customers/redact`, and RedeemID removes anything left here.

The button needs the optional Admin API scope `write_customer_data_erasure` on the Dev Dashboard app. Without it, the column says so; erase the customer in Shopify admin instead. It is never pressed automatically, and it is refused for test fixtures, which don't exist in Shopify.

## For developers

- `redeemid_shopify_erasure_requested` (`int $request_id, string $customer_gid`) fires after a successful request to Shopify.
- `redeemid_redact_opens_wp_request` (`bool $open, string $email`): return false to record every Shopify redaction as a completed request instead of leaving one open for staff.
- What RedeemID adds to a request is post meta on its `user_request` post: `_redeemid_source` (`shopify` or `member`), `_redeemid_shopify_request`, `_redeemid_customer`, `_redeemid_fixture`, `_redeemid_redacted_at` and `_redeemid_shopify_erasure`.
