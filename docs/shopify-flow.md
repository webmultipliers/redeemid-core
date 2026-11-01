# Tagging customers with Shopify Flow

RedeemID reads customer tags; it never decides who should have them. Something in Shopify adds a tag when a customer buys a membership and removes it when the membership ends. That is usually [Shopify Flow](https://apps.shopify.com/flow), or a subscription or membership app that tags customers itself. WordPress then mirrors the tags, and access levels (RedeemID → Access → Levels) point at them.

Because the state lives in Shopify, renewals, cancellations, failed payments, pauses and fixed terms are all handled where the billing happens, and WordPress follows within seconds through the `customer.tags_added` and `customer.tags_removed` webhooks.

## One-time purchase

Workflow 1, grant:

1. Trigger: **Order paid**.
2. Condition: any line item's product is your membership product (match on product ID, or on a product tag such as `membership-gold` so new products join without editing the workflow).
3. Action: **Add customer tags**: `gold`.

Workflow 2, revoke:

1. Trigger: **Order cancelled**, and a second workflow on **Refund created** if refunds should end access.
2. Same condition as above.
3. Action: **Remove customer tags**: `gold`.

If more than one product grants the same tag, check before removing it that the customer has no other paid order for one of them, or give each product its own tag and let the level match any of them.

## Recipes

These are written from Shopify's Flow documentation and have **not yet been run against a live store**, so check each step's name in your Flow editor. Each one only adds or removes tags (and optionally sets a display date); RedeemID follows whatever the tags say.

### Time pass (for example 30 days)

One workflow, so the end is scheduled the moment access starts:

1. Trigger: **Order paid**.
2. Condition: a line item's product has the tag `pass-30`.
3. Action: **Add customer tags**: `pass`.
4. Optional, for the Member Account card: **Update customer metafield** `membership.renews_on` (type date) to the order date plus 30 days. The card shows it as the end date.
5. **Wait** 30 days.
6. Condition: the customer has no newer paid order for a `pass-30` product (otherwise the newer run removes the tag when it ends).
7. Action: **Remove customer tags**: `pass`.

Point a level at `pass`. A 24-hour or 7-day pass is the same workflow with a shorter **Wait**.

### Fixed term (for example 12 months)

Add a **Wait** step to the grant workflow, after the tag is added, then **Remove customer tags**. A renewal purchase starts its own run, so give a renewal its own tag or check for a newer paid order before removing, as in step 6 above.

### Subscription start and cancel

Subscription apps (Shopify Subscriptions, Recharge, Appstle and others) know when a contract is active, paused, failed or cancelled; RedeemID's app can't read another app's contracts. Use the app's own customer tagging if it has one. Otherwise use its Flow triggers (their names differ by app):

1. When a contract is created or becomes active: **Add customer tags** `gold-sub`, and optionally **Update customer metafield** `membership.member_since`.
2. When a contract renews: optionally update `membership.renews_on` to the next billing date.
3. When a contract is cancelled, expires or fails for good: **Remove customer tags** `gold-sub`. If cancelling should keep access to the end of the paid period, add a **Wait** until the period ends before removing.

Point the level at `gold-sub` (or at `gold` *or* `gold-sub`, if one-time buyers get `gold`).

### Gift membership

The giver buys a gift product and enters the recipient's email in a field on the product page (a line item property, for example `Recipient email`). Then:

1. Trigger: **Order paid**.
2. Condition: a line item's product has the tag `gift-gold`.
3. Find or create the recipient: **Send Admin API request** with `customerCreate` (email from the line item property). If the email already belongs to a customer, look it up first with **Get customer data** filtered by email and use that customer.
4. On the recipient: **Add customer tags** `gift-gold`, and **Update customer metafield** `membership.gift_until` (type date) to the order date plus the gift's length. The Member Account card shows "Gift membership: Until …" from it.
5. Tell the recipient: send an email (Shopify Email or your email app) with the link to sign in on your site.
6. **Wait** for the gift's length, then **Remove customer tags** `gift-gold`.

The recipient signs in with their own customer account, and a level matching `gift-gold` opens the content. The giver gets nothing extra; RedeemID never shares one customer's access with another.

### Team seats

Use Shopify B2B (companies with contacts, a Shopify Plus feature). Each contact is their own customer: tag a contact `team-gold` when they're added to the company and remove it when they're removed, with Flow or by hand. Let the Gold level match `gold` or `team-gold`. RedeemID keeps no seat list of its own; see [seats-and-gifts.md](seats-and-gifts.md).

## Complimentary and manual access

Add the tag by hand in Shopify, or from RedeemID → Customers → a customer in WordPress (which writes to Shopify). Use a separate tag such as `gold-comp`, and let the Gold level match either tag, so no purchase workflow ever removes it.

## Start and renewal dates

The **Member Account** block shows when a membership started and when it renews. To show Shopify's dates rather than the date WordPress first saw the tag, have the workflow set customer metafields in the `membership` namespace, as dates (`YYYY-MM-DD`):

- `membership.member_since`, set with **Update customer metafield** when the tag is added;
- `membership.renews_on`, set by the subscription app's renewal trigger, or by the fixed-term workflow before its **Wait** step;
- `membership.gift_until`, set by the gift workflow above.

They are read on every resync (a member's **Refresh Membership**, staff's resync, or **Resync all customers**), and they only change what the card says, never access.

## Bringing customers back after checkout

In Shopify, Settings → Checkout → Order status page → Additional scripts, or in the order confirmation email template, add a link to the return link shown under RedeemID → Settings in WordPress (it ends in `/?redeemid_return=1`). A customer who bought while signed in goes back to the page they bought from, and sees "Your membership is ready" as soon as the tag lands. A guest is asked to log in with Shopify using the email from their order.

## Checking it works

- RedeemID → Customers in WordPress shows each linked customer's synced tags and the webhook that last changed them.
- RedeemID → Access → Test shows what a given tag set or user gets on any item.
- A signed-in customer who has just paid can use the **Refresh Membership** block instead of waiting for the webhook.
