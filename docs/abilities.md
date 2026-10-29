# Abilities and MCP

On WordPress 6.9 and later, RedeemID registers its own abilities with the [Abilities API](https://developer.wordpress.org/apis/abilities/). An ability is a described, permission-checked action with JSON input and output, so anything that speaks the API can use it: the abilities REST routes, the JavaScript client, and AI agents through the [MCP Adapter](https://github.com/WordPress/mcp-adapter). On older WordPress, nothing is registered and nothing else changes.

The point is support work: "why can't jane@example.com read the March report?" becomes one call that answers the same way the gate does.

## What's registered

Every ability is in the `redeemid` category and read-only. None of them grants access, changes a level, edits a rule or calls Shopify. Shopify stays the record, and these read what WordPress has already synced.

### For staff

These need the RedeemID admin capability (`manage_options`).

| Ability | Answers |
|---|---|
| `redeemid/explain-access` | Can this viewer read this item, and why? Takes `post_id` or `url`, and a viewer: `as` = `guest`, `signed-in`, a level slug, `tags` (with `tags`) or `user` (with `user_id`). Returns every rule layer, the effective rule, the level a locked viewer is offered, what they see instead, which blocks show, and how search, comments and search engines treat it. The same trace as Access → Test and `wp redeemid access explain`. |
| `redeemid/explain-features` | Which features the viewer holds, and the route and test that grants or refuses each. Same viewer input. |
| `redeemid/list-levels` | Every level: its tag condition, the levels it includes and opens, its badge and its products. |
| `redeemid/get-rules` | The site default, conflict mode, post type and taxonomy rows, the meter, and every term rule. |
| `redeemid/get-member` | One user's synced record, by `user_id` or `email` (WordPress or Shopify address): linked, status, tags, levels, features, last sync and its source, level history. |
| `redeemid/site-health` | The Health checklist, current webhook problems and the last bulk resync. Configuration values are never shown, only whether each is set. |
| `redeemid/level-changes` | Who joined or left a level since a date (`since`, default `7 days ago`; optional `level`). The same list as `wp redeemid access changes`. |

### For members

These need a signed-in user and only ever describe that user.

| Ability | Answers |
|---|---|
| `redeemid/my-membership` | The caller's levels, dates, ended levels, features and last sync: the same shape as `GET /redeemid/v1/account`. |
| `redeemid/can-i-read` | Can the caller read this item (`post_id` or `url`)? If not, the reason, the level that would unlock it, or when drip opens it. Drafts and private items the caller can't see answer "no such item". Free metered reads aren't counted. |

## Failing closed

A post, user or viewer that doesn't exist is an error, never an answer. A typo in `as` is an error rather than a quiet guest answer. A guest can run nothing, and a signed-in member can't run the staff abilities.

No ability returns a token, a client secret, a webhook secret or a raw Shopify response. Customer email and name appear only in `get-member` and `level-changes`, for staff, as on the Customers screen.

## Connecting an AI agent

1. Install and activate the **MCP Adapter** plugin ([wordpress.org](https://wordpress.org/plugins/mcp-adapter/) or [GitHub](https://github.com/WordPress/mcp-adapter)). RedeemID doesn't bundle it.
2. Create an application password for the user the agent acts as (Users → Profile → Application Passwords). Use an administrator for the staff abilities. The agent can do only what that user can.
3. Point the agent at the default server, either over HTTP at `https://your-site/wp-json/mcp/mcp-adapter-default-server` with the user name and application password, or locally with `wp mcp-adapter serve --server=mcp-adapter-default-server --user=<login>`.

The default server lists abilities through its discover, get-info and execute tools, so the agent finds RedeemID's by name and description.

Without an agent, the same abilities run over REST: `GET /wp-json/wp-abilities/v1/abilities?category=redeemid` lists them, and `GET /wp-json/wp-abilities/v1/abilities/redeemid/list-levels/run` runs one.

## Turning them off for MCP

All RedeemID abilities are marked public for the MCP Adapter. To keep one, or all, off MCP while leaving REST and PHP alone, use core's filter:

```php
add_filter( 'wp_register_ability_args', static function ( array $args, string $name ): array {
	if ( str_starts_with( $name, 'redeemid/' ) ) {
		$args['meta']['mcp']['public'] = false;
	}

	return $args;
}, 10, 2 );
```
