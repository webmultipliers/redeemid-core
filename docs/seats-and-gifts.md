# Seats and gifts: design note

Status: **decided 2026-11-01.** Seats use Shopify B2B only; gifts use a Flow workflow plus the `membership.gift_until` line on the Member Account card. Both recipes are in [shopify-flow.md](shopify-flow.md#recipes). No owner screen and no gift checkout field were built. The rest of this note records why.

## The rule they must keep

Shopify is the only source of entitlements. WordPress mirrors the tags Shopify holds on each customer and decides access from them. WordPress never grants access Shopify doesn't know about, and never keeps access after Shopify takes it away. A tool in WordPress may *write* to Shopify (as the Customers screen's tag editor does), because the grant then lives in Shopify.

That rules out the two shortcuts most membership plugins take:

- **Sharing one customer across several WordPress users.** The mirror is one Shopify customer to one WordPress user. A second user riding on someone else's tags is a WordPress-side grant.
- **Invited WordPress users holding a seat.** A seat list stored in WordPress is a grant Shopify can't see, revoke or report on.

## Seats

**Model: one Shopify customer per seat, managed in Shopify B2B.** Every person who reads is a Shopify customer with their own tag, exactly like an individual member. What changes is who buys and who manages the list.

1. The buyer (the "account owner") buys a team plan in Shopify. The store sets the team up as a B2B company (Shopify Plus) with locations and contacts, where each contact is a customer.
2. Flow tags each contact `team-gold` when they're added to the company and removes the tag when they're removed. The seat count is enforced in Shopify, by whoever manages the company (the store, or the owner through B2B self-serve). RedeemID reads no seat count.
3. Each colleague signs in with their own customer account. Their tags arrive like anyone's, and a level (`gold` granted by `gold` *or* `team-gold`) opens the content.
4. When the team's subscription ends, Flow removes every team tag for that company. The members lose access as the webhooks arrive, as with any lapsed member.

A WordPress "Your team" screen that would create customers and count seats against a `membership.seats` metafield was considered and dropped (decided 2026-11-01, confirmed 2026-11-05): B2B already holds the list, and counting would cost an Admin API search on every add.

## Gifts

**Model: the recipient becomes a Shopify customer with a tag and an end date held in Shopify.**

1. The giver buys a gift product in Shopify and enters the recipient's email at checkout (a line item property).
2. Flow, on the paid order, finds or creates the recipient as a customer, adds the tag (`gift-gold`), and waits: a **Wait** step for the gift's length, then removes the tag. The end date lives in Flow (and can be mirrored to a `membership.gift_until` metafield for display).
3. Shopify Email (from the same Flow) tells the recipient how to sign in. The recipient signs in with their own customer account and the tag opens the content.
4. When the wait ends, Flow removes the tag, and access ends as the webhook arrives.

WordPress's part is display only: the account card can show "Gift from …, until 12 March" from the metafields, and plan cards can offer "Give Gold" as an ordinary product.

What stays out:

- **Gift links that open an article for a non-member.** That's WordPress granting access to a reader Shopify doesn't know. If a "share this article" feature is wanted, it should be a time-limited, single-item exception designed separately, not a membership.
- **A gift end date enforced by WordPress.** If the tag lingers in Shopify, the fix is the Flow, not a WordPress timer.

## Decided

1. Seats: Shopify B2B only. No RedeemID owner screen.
2. Gifts: the Flow recipe and the account-card line. No gift checkout field on the plan card.
