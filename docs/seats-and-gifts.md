# Seats and gifts: design note

Status: **not built.** This note records how team seats and gift memberships could work without breaking RedeemID's one rule, so the decision is made before any code is.

## The rule they must keep

Shopify is the only source of entitlements. WordPress mirrors the tags Shopify holds on each customer and decides access from them. WordPress never grants access Shopify doesn't know about, and never keeps access after Shopify takes it away. A tool in WordPress may *write* to Shopify (as the Customers screen's tag editor does), because the grant then lives in Shopify.

That rules out the two shortcuts most membership plugins take:

- **Sharing one customer across several WordPress users.** The mirror is one Shopify customer to one WordPress user. A second user riding on someone else's tags is a WordPress-side grant.
- **Invited WordPress users holding a seat.** A seat list stored in WordPress is a grant Shopify can't see, revoke or report on.

## Seats

**Model: one Shopify customer per seat.** Every person who reads is a Shopify customer with their own tag, exactly like an individual member. What changes is who buys and who manages the list.

1. The buyer (the "account owner") buys a team plan in Shopify: a product with a quantity, or a subscription with a seat count. Shopify Flow (or the subscription app) tags the owner `team-owner` and records the seat count in a customer metafield on the owner, for example `membership.seats = 10`.
2. The owner adds people. Two ways, both ending in Shopify:
   - **In Shopify:** a B2B company with locations and contacts (Shopify Plus), where each contact is a customer. Flow tags each contact `team-gold` when they're added to the company and removes the tag when they're removed.
   - **From WordPress, writing to Shopify:** a RedeemID "Your team" screen for the owner that finds or creates the colleague as a Shopify customer (`customerCreate`) and adds the `team-gold` tag (`tagsAdd`), refusing once the count of customers carrying the owner's team tag reaches `membership.seats`. Removing someone removes the tag in Shopify. WordPress holds no seat list; the list is "customers tagged for this team", read from Shopify.
3. Each colleague signs in with their own Shopify customer account. Their tags arrive like anyone's, and a level (`gold` granted by `gold` *or* `team-gold`) opens the content.
4. When the owner's subscription ends, Flow removes the owner's tags and every team tag for that team. The members lose access as the webhooks arrive, as with any lapsed member.

Open questions for the build:

- How a team tag names its team. One tag per team (`team-acme`) scales badly in level conditions; a shared `team-gold` tag plus a metafield `membership.team = <owner id>` keeps levels simple and lets Flow find a team's members.
- Whether the owner screen is worth building, or whether Shopify B2B covers the stores that need seats.
- Seat counting needs a Shopify customer search by tag and metafield on every add, at Admin API cost.

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

## What to decide

1. Seats: Shopify B2B only, or also a RedeemID owner screen that writes to Shopify?
2. Gifts: is a Flow template plus account-card display enough, or is a gift checkout field on the plan card wanted?
3. Both depend on Flow templates; the importable templates already planned would come first.
