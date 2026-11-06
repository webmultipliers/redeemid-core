# Replacing text

**RedeemID → Settings → Text** lets a site put RedeemID in its own words. Every string the plugin can show is listed there, read from the bundled translation template (`languages/redeemid.pot`): block text, sign-in and account messages, paywall and meter text, store pages, privacy text, the block editor's labels and the admin screens.

## Replacing a string

Each string has its own field. An empty field shows the string's current text (its translation, or the original) as a placeholder; type new text to replace it, and clear the field to go back. Plural strings ("%d customer" / "%d customers") have a field for one and a field for more than one.

Only the strings you give new text change. There is no find-and-replace across strings: every replacement is defined for one string, the way Shopify's theme language editor works, so nothing changes that nobody looked at. To change a word such as "account" everywhere, search for it and replace each string that uses it.

## Languages

Replacements sit on top of translation. WordPress translates a string first, then RedeemID applies the site's replacement, so any string you leave alone keeps its translation.

When the site has more than one language installed, **Replacements for** picks the language you are editing. **Every language** applies in all of them; a replacement set for one language wins over one for every language. The list shows each string's current translation for the language you picked. A replacement has one form for one and one for more than one; a language with more plural forms uses the second for all of them.

## Placeholders and HTML

Some strings have placeholders such as `%s`, `%d` or `%1$s`, filled in when the text shows. A replacement may use the placeholders its original has, in any order or not at all, but no others, and a percent sign on its own must be written `%%`. A replacement that breaks this is refused with a note on its field, and the rest of the page still saves.

A replacement can only use the HTML tags and attributes its original uses (a link stays a link); anything else is stripped.

## Finding strings

Search matches the original, its plural, its context and your replacement. Filter by who sees a string (visitors and members, or staff only) and by where it appears. Each row lists the source files that use it. **Replaced** lists strings with a replacement; **No longer used** appears when a replacement belongs to a string this version no longer has.

## Moving and keeping replacements

Settings export and import (RedeemID → Settings → Export / import, or `wp redeemid settings export|import`) carry replacements as the `strings` section. Import checks each replacement against its original and skips any that don't fit. Uninstalling removes them.

## From code

The `redeemid_string_overrides` filter changes what is used, after what is saved, with the same shape as the option:

```php
// locale => original => text; '' is every language. Plural strings take [ one, more ].
add_filter( 'redeemid_string_overrides', function ( array $all ) {
	$all['']['Your account'] = 'Your members area';
	return $all;
} );
```

A string with a context is keyed `context . "\x04" . original`, the way gettext keys it. When the filter changes the saved values, the Text page says so.

## For contributors

The list is only as complete as `languages/redeemid.pot`. CI regenerates it and fails when it is out of date; regenerate it with `wp i18n make-pot . languages/redeemid.pot --exclude=vendor,tests,node_modules`.
