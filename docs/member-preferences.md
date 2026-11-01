# Member preferences

Members can see and change fields you define on their Shopify customer, such as the topics they follow or their skill level. You decide every field and who may touch it. The answers live on the customer in Shopify, ready for Flow, segments and email. WordPress keeps no copy.

RedeemID ships the plumbing, not a form. Your theme, a forms plugin or a headless front end shows the fields and saves them through the API below.

## Set it up

1. **Define the fields in Shopify.** Go to Settings → Custom data → Customers and add a metafield definition for each one. The definition sets the name, the type and any limits, such as a list of allowed choices or a minimum and maximum.
2. **Choose who may touch each field.** Go to RedeemID → Settings → Member preferences. RedeemID lists your customer definitions. Set each one to **Hidden**, **Members can see** or **Members can change**, then save.
3. **Show the fields.** Use `redeemid_preferences()` and `redeemid_set_preferences()` in PHP, or `GET` and `POST /wp-json/redeemid/v1/account/preferences` from JavaScript. Examples are below.

Fields in the `membership` namespace are never offered, because RedeemID reads membership dates from them. Preferences never open content: access still comes only from tags.

## Field types

| Shopify type | Value you send and get back |
| --- | --- |
| Single line text | A string. With choices, one of them |
| Multi-line text | A string |
| List of single line text | A list of strings. With choices, each one of them |
| True or false | `true` or `false` |
| Integer | A whole number |
| Decimal | A number, as a string |
| Date | `YYYY-MM-DD` |

Other types, such as references, files, JSON, money and ratings, are listed as not supported and can't be turned on. Every value is checked against the definition's choices, minimum, maximum and length before it goes to Shopify. An empty value (`''`, `null` or an empty list) clears the field.

## What a field looks like

```php
[
	'key'         => 'preferences.topics',
	'name'        => 'Topics',
	'description' => 'What you want to hear about',
	'type'        => 'list.single_line_text_field',
	'choices'     => [ 'Politics', 'Food', 'Travel' ],
	'min'         => null,
	'max'         => null,
	'editable'    => true,
	'value'       => [ 'Food' ],
]
```

`redeemid_preferences()` returns a list of these in the order of the settings page. A visitor who isn't signed in and linked to a Shopify customer gets a `WP_Error`. A site with no fields chosen gets `[]`.

## Saving from a forms plugin

Map the form's answers to `namespace.key` names and save them when the form is submitted. This example uses Gravity Forms' `gform_after_submission` action. Hook names belong to the plugin, so check them against the version you run.

```php
add_action( 'gform_after_submission_3', function ( $entry ) {
	$saved = redeemid_set_preferences(
		[
			'preferences.topics' => array_filter( [ rgar( $entry, '1.1' ), rgar( $entry, '1.2' ), rgar( $entry, '1.3' ) ] ),
			'preferences.level'  => rgar( $entry, '2' ),
		]
	);

	if ( is_wp_error( $saved ) ) {
		error_log( 'Preferences not saved: ' . $saved->get_error_message() );
	}
} );
```

Save only for signed-in members: show the form to them alone, for example with the block **Access** setting.

## Saving from JavaScript

The browser client's state carries the REST nonce, so a script can read and save without a nonce in the cached page.

```js
const { nonce, linked } = await redeemid.state();

if (linked) {
	const response = await fetch('/wp-json/redeemid/v1/account/preferences', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
		body: JSON.stringify({ values: { 'preferences.topics': ['Food', 'Travel'] } }),
	});
	const fields = await response.json();
}
```

A refused save answers `400` with `data.fields`, which maps each refused key to a reason: `unknown_field`, `read_only`, `invalid_type`, `invalid_choice`, `invalid_length` or `invalid_range`. Saves are limited to 10 a minute per member (`429`).

## What happens on save

- RedeemID writes to the member's own customer with the Admin API (`metafieldsSet`, or `metafieldsDelete` to clear), then reads the fields back. The customer comes from the member's link, never from the request.
- The `preferences_saved` event fires with the field names changed and the user ID, never the answers ([Developer API](developer-api.md)).
- The log records the field names, never the answers.

In Shopify, use the answers like any customer metafield: build a segment, branch a Flow workflow, or personalise an email.
