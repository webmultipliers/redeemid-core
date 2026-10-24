# Features

A **feature** is a site behaviour a customer unlocks because of what they bought in Shopify: ad-free reading, downloads, a member badge, early access to a preview surface. Content rules answer "can you see this item?"; features answer "how does the site behave for you?". Both read the same synced mirror through the same levels, Checker and Evaluator.

A feature is never a product, a price or a checkout. The link from purchase to feature is always indirect: purchase → Shopify tag → mirror → condition → feature.

Adding one takes three steps:

1. **Register it** in code (theme or plugin).
2. **Map who gets it** on Shopify → Access → Features (or in the registration, which makes it managed in code).
3. **Wire one enforcement point**: a PHP check, block visibility, the body class, the REST field or the shortcode.

## 1. Register

```php
add_filter( 'wp2_shopify_features', function ( array $features ): array {
	$features['ad-free'] = [
		'label'       => 'Ad-free reading',
		'description' => 'Hides the ad slots in stories and on section fronts.',
		'enforcement' => [ 'body', 'block' ], // php, block, body, rest, shortcode
		'sensitive'   => false,               // true: the guarded thing must be withheld server-side
		'cache'       => 'private',           // private, vary or fragment (see Caching)
		'primary'     => true,                // list it in the member menu (at most three are shown)
		'js'          => false,               // print a presentation-only boolean (see JS signal)
	];

	return $features;
} );
```

- **Slug**: stable, lower case with dashes; a third party prefixes it with a vendor and a slash (`acme/early-access`). Renaming a slug is removing one feature and adding another. Labels can change freely.
- **Default**: off. A feature with no condition grants nobody, and an empty condition is never "public".
- **Removing a registration** leaves any stored condition inert. Checks return false, and the Features tab offers to forget it.
- **Managed in code**: add `'condition' => "gold\nAdd-on purchase = tag:ad-free-addon"` (or an array of routes) to the registration. The admin then shows that condition read-only.

## 2. Map: the condition

A condition is one or more **routes** joined by OR. Each route is one or more **tests** joined by AND. On the Features tab, each line is one route:

```
gold
Add-on purchase = tag:ad-free-addon
silver, test:role=editor
```

| Test | Form | Notes |
|---|---|---|
| Level | `gold` or `level:gold` | The primary pattern. Inclusion is expanded first, so Gold including Silver means a `silver` route also grants Gold members. `public` and `signed-in` are built in. |
| Tag | `tag:<tag>` | Read from the synced mirror, case-insensitively. Use it for a standalone add-on that stacks with any tier. |
| Custom | `test:<name>` or `test:<name>=<arg>` | Registered with `wp2_shopify_feature_tests`. Use it when no purchase is involved (staff, a role), never a fake tag. |

`Label = ` in front of a line names the route for provenance ("Ad-free reading, from Add-on purchase"). Without one, a route is named after its level or levels, a tag route is "Add-on purchase", and a custom test uses its own label. Provenance never names a tag.

A level's edit form has a **Features** box for each feature mapped in admin. Ticking one adds the route "this level" to that feature, and unticking removes it. It edits the same stored condition as the Features tab, so the two views can't disagree.

### Granted and revoked only through the mirror

There is no feature record to keep in sync. A grant is derived per request from the mirror plus the condition. A feature appears and disappears for the same reasons a content gate opens and closes: a webhook, reconciliation, sign-in, a resync, or a tag edit on Shopify → Customers (which writes the tag to Shopify).

- Any route grants the feature. It drops only when **every** route is lost: a Gold member who also bought the add-on keeps it after a downgrade to Silver, and keeps it after an add-on refund while still Gold. The Test tab and the account block show every granting route, so this case doesn't read as "the refund did not work".
- There is no manual grant in WordPress. To give one customer a feature, give them the tag in Shopify.

### Fail closed

| Visitor | Result |
|---|---|
| Guest | Only Public features |
| Signed in, no linked customer | Only Signed in and custom-test routes |
| Linked, disabled in Shopify | Only Public features |
| Linked, never synced or outside the freshness window (or the last sync failed with "fail closed") | Only Public features |
| A custom test that throws, returns anything but `true`, or isn't registered | That route fails; other features still resolve |
| An unknown slug | Not granted, no error |

This is one step stricter than content: the built-in Signed in level also withholds a feature from a linked customer who is disabled or stale.

## 3. Enforce

Pick one primary enforcement point per feature, and declare it in `enforcement`.

| Point | How | Notes |
|---|---|---|
| PHP | `wp2_shopify_has_feature( 'ad-free' )` | Branch on the boolean, with the default branch as the not-granted behaviour. Never read tags or user meta yourself. Pass a user ID or `WP_User` to check someone other than the current visitor. |
| Block visibility | Block sidebar → **Access** → **Feature**, then **holds this feature** or **does not hold it** | Decided in `pre_render_block`, so a hidden block is never sent. "Does not hold it" is for upgrade prompts. Combines with the block's content Access setting (both must pass). The picker lists features that declare `block`. |
| Body signal | `wp2-feature-<slug>` on `<body>` (a slash becomes `--`) | Only for granted features that declare `body`. It names the feature, never the tag behind it. |
| REST | `GET /wp-json/wp2-shopify/v1/features` → `{features: [...]}`, and the `wp2_features` field on `/wp/v2/users/me` | The current user's own slugs only, for features that declare `rest`. Another user's record never carries it, and guests get Public features only. |
| Shortcode | `[wp2_feature slug="ad-free"]…[/wp2_feature]`, `not="1"` for the inverse | Takes a registered slug, never a condition. For classic themes and widgets. |

The current visitor's set is resolved once per request, for every feature, and memoized. Twenty feature-aware blocks cost one resolution. `?wp2_preview_as=<level>` (editors) previews features as a holder of that level.

**Display is not enforcement.** For a `sensitive` feature (a file, a dataset, a price, a capability), withhold the thing itself on the server, at the endpoint or data layer, with a PHP check. Block visibility, the body class or the JS signal can shape the presentation on top of that, but must never be the only barrier.

### JS signal

A feature registered with `'js' => true` appears in `window.wp2ShopifyFeatures` as `slug: true|false` for the current visitor. Only bare booleans are printed, and only for those features. It is **presentation only**: anyone can flip it in dev tools, so it must never unlock anything. Re-check on the server for anything that matters. A page that carries it for a signed-in visitor is sent `Cache-Control: private` and `DONOTCACHEPAGE`.

### Member menu and account

- `wp2_shopify_granted_features()` returns `slug => {label, provenance, routes, primary}`, with no tags and no customer data.
- `wp2_shopify_menu_features()` returns granted features registered `primary`, at most three, for a member menu.
- The **Membership Features** block (`wp2-shopify/member-features`) lists them on the account page ("Ad-free reading, from Gold and Add-on purchase"). Its menu mode shows the primary ones as chips for a header. "Not included" rows appear only if you switch them on. It is a dynamic island, so the cached page holds a placeholder.

## Caching

Feature-dependent output is personalised. Each feature declares one caching option in `cache`:

| Option | Fits | What the plugin does |
|---|---|---|
| `private` | The feature changes much of the page | When a signed-in visitor's page uses it, the page is sent `DONOTCACHEPAGE` and, while headers can still be sent, `Cache-Control: private, no-store`. Body-class and JS features are resolved on `template_redirect`, so the header goes out in time. |
| `vary` | A small, stable set of combinations, behind a cache that can vary on a cookie | `wp2_shopify_fset` carries an opaque hash of the visitor's granted `vary` features (empty for guests). Whether your cache plugin or CDN can vary on it is for you to verify on your stack. |
| `fragment` | Only a small part varies | Nothing. Render the varying part per visitor yourself, for example as a dynamic island. |

A missing or unknown option falls back to `private`, and the Features tab flags it. Guests can only hold Public features, which every guest holds alike, so guest pages stay cacheable. Page caches should already bypass the `wordpress_logged_in_*` cookie (see the README).

Two acceptance cases to run through your production cache: a holder then a non-holder requesting the same URL, and the reverse order.

When a mapping changes, `wp2_shopify_features_mapping_changed` (`string[] $slugs`) fires. Features are site-wide, so purge the whole site.

## Testing

- **Shopify → Access → Test**: choose a viewer (a guest, a level, a tag set or a real user) and the tab prints every feature with its routes and tests: which route passed, which test inside it failed and why. Pick an item as well to see the item trace, including blocks with a feature condition.
- **Shopify → Access → Features → Preview**: which route grants each feature for a tag set or a user.
- `wp wp2-shopify access features --as=tags --tags=silver,ad-free-addon` prints the same trace, and `--format=json` gives it as JSON.

## Hooks

- `wp2_shopify_features` (filter): registrations.
- `wp2_shopify_feature_tests` (filter): `name => [ 'label' => ..., 'callback' => fn( Member $member, string $arg, int $user_id, int $now ): bool ]`. Guests and disabled or stale customers never reach a callback.
- `wp2_shopify_feature_decision` (filter): `( bool $granted, string $slug, int $user_id, Grant $grant, Member $member )`. It can withhold any feature, but it can only grant to a signed-in, linked, enabled customer with a fresh mirror. A filter that throws or returns a non-boolean leaves the feature not granted. Each change is logged as `feature.decision_filtered`, once an hour per feature, user and change.
- `wp2_shopify_features_changed` (action): `( int $user_id, string[] $added, string[] $removed )`, after a request whose mirror writes changed a customer's set. Use it for cache purging, logging or a downstream integration, never to re-decide the grant. Going stale is a matter of time passing, not a write, so it fires no event; the feature simply stops being granted.
- `wp2_shopify_features_mapping_changed` (action): `( string[] $slugs )`.

## Not in v1

No feature pricing, seats or gifts, no time-bound features (tags carry no start date), no per-feature reporting, and no WordPress-side manual grants.
