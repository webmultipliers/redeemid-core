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

## Fixed term (for example 12 months)

Add a **Wait** step to the grant workflow, after the tag is added, then **Remove customer tags**. A renewal purchase starts its own run, so give a renewal its own tag or check for a newer paid order before removing.

## Subscriptions

Subscription apps (Shopify Subscriptions, Recharge, Appstle and others) know when a contract is active, paused, failed or cancelled; RedeemID's app can't read another app's contracts. Use the app's own customer tagging if it has one, or its Flow triggers, to add a tag while a subscription is active and remove it when it ends. Point the level at that tag.

## Complimentary and manual access

Add the tag by hand in Shopify, or from RedeemID → Customers → a customer in WordPress (which writes to Shopify). Use a separate tag such as `gold-comp`, and let the Gold level match either tag, so no purchase workflow ever removes it.

## Start and renewal dates

The **Member Account** block shows when a membership started and when it renews. To show Shopify's dates rather than the date WordPress first saw the tag, have the workflow set customer metafields in the `membership` namespace, as dates (`YYYY-MM-DD`):

- `membership.member_since`, set with **Update customer metafield** when the tag is added;
- `membership.renews_on`, set by the subscription app's renewal trigger, or by the fixed-term workflow before its **Wait** step.

They are read on every sync.

## Bringing customers back after checkout

In Shopify, Settings → Checkout → Order status page → Additional scripts, or in the order confirmation email template, add a link to the return link shown under RedeemID → Settings in WordPress (it ends in `/?redeemid_return=1`). A customer who bought while signed in goes back to the page they bought from, and sees "Your membership is ready" as soon as the tag lands. A guest is asked to log in with Shopify using the email from their order.

## Checking it works

- RedeemID → Customers in WordPress shows each linked customer's synced tags and the webhook that last changed them.
- RedeemID → Access → Test shows what a given tag set or user gets on any item.
- A signed-in customer who has just paid can use the **Refresh Membership** block instead of waiting for the webhook.
