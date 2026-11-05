# Replacing text

**RedeemID → Text** lets a site put RedeemID in its own words. Every string the plugin can show is listed there, read from the bundled translation template (`languages/redeemid.pot`): block text, sign-in and account messages, paywall and meter text, store pages, privacy text, the block editor's labels and the admin screens.

## Two ways to change text

- **Swap a word everywhere.** "account" to "portal" changes every string that has the word: "Your account" reads "Your portal", "Connect your Shopify account" reads "Connect your Shopify portal". Matching is for whole words and phrases, in any case, and a capitalised or upper-case match gets a capitalised or upper-case replacement. Plurals are their own swap ("accounts" to "portals"). Swaps never touch HTML tags or placeholders. Each swap shows how many strings it changes, and the **Changed by word swaps** view lists them with what each one shows now.
- **Replace one string.** Type the new text in the string's field. Clear the field to go back to the original. A string's own replacement wins over word swaps, so to keep one string as it was while a swap changes the rest, write the original into its field.

Plural strings ("%d customer" / "%d customers") have a field for one and a field for more than one.

## Languages

Replacements sit on top of translation. WordPress translates a string first, then RedeemID applies the site's replacement, so any string you leave alone keeps its translation.

When the site has more than one language installed, **Replacements for** picks the language you are editing. **Every language** applies in all of them; a replacement or word swap set for one language wins over one for every language. The list shows each string's current translation for the language you picked. A replacement has one form for one and one for more than one; a language with more plural forms uses the second for all of them.

## Placeholders and HTML

Some strings have placeholders such as `%s`, `%d` or `%1$s`, filled in when the text shows. A replacement may use the placeholders its original has, in any order or not at all, but no others, and a percent sign on its own must be written `%%`. A replacement that breaks this is refused with a note on its field, and the rest of the page still saves.

A replacement can only use the HTML tags and attributes its original uses (a link stays a link); anything else is stripped. Word swaps can't hold HTML or `%`.

## Finding strings

Search matches the original, its plural, its context and your replacement. Filter by who sees a string (visitors and members, or staff only) and by where it appears. Each row lists the source files that use it. **Replaced** lists strings with their own replacement; **No longer used** appears when a replacement belongs to a string this version no longer has.

The Text page itself always shows RedeemID's original words, so a swap can never make the page that controls it hard to read.

## Moving and keeping replacements

Settings export and import (RedeemID → Access, or `wp redeemid settings export|import`) carry replacement text and word swaps as the `strings` and `words` sections. Import checks each replacement against its original and skips any that don't fit. Uninstalling removes both options.

## From code

Two filters change what is used, after what is saved, with the same shapes as the options:

```php
// locale => original => text; '' is every language. Plural strings take [ one, more ].
add_filter( 'redeemid_string_overrides', function ( array $all ) {
	$all['']['Your account'] = 'Your members area';
	return $all;
} );

// locale => lower-case word or phrase => replacement.
add_filter( 'redeemid_string_words', function ( array $words ) {
	$words['']['account'] = 'portal';
	return $words;
} );
```

A string with a context is keyed `context . "\x04" . original`, the way gettext keys it. When a filter changes the saved values, the Text page says so.

## For contributors

The list is only as complete as `languages/redeemid.pot`. CI regenerates it and fails when it is out of date; regenerate it with `wp i18n make-pot . languages/redeemid.pot --exclude=vendor,tests,node_modules`.
