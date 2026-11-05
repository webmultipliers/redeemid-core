<?php
/**
 * Seeds a throwaway WordPress for the leak tests. Run inside WordPress:
 *
 *     REDEEMID_LEAK_SEED=/tmp/leak.json wp eval-file tests/Integration/seed.php
 *
 * It writes the IDs, links and sign-in cookies LeakTest and the browser checks
 * (tests/Browser, lighthouserc.js) need to the JSON file named by
 * REDEEMID_LEAK_SEED. Never run it on a real site: it adds
 * posts, users and a level.
 *
 * @package RedeemID
 */

use RedeemID\Access\Context;
use RedeemID\Access\ItemRule;
use RedeemID\Access\LevelStore;
use RedeemID\Support\CustomerId;
use RedeemID\Sync\Mirror;

if ( ! defined( 'WP_CLI' ) || ! class_exists( Mirror::class ) ) {
	return;
}

$out = (string) getenv( 'REDEEMID_LEAK_SEED' );

if ( '' === $out ) {
	WP_CLI::error( "Set REDEEMID_LEAK_SEED to the JSON file to write." );
}

LevelStore::save(
	'gold',
	[
		'label'  => 'Gold',
		'groups' => [ [ 'tags' => [ 'gold-member' ], 'match' => 'any' ] ],
	]
);

$paragraph = static fn( string $text ): string => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";

// Locked behind the paywall break. The search word is only in the locked part.
$with_break = wp_insert_post(
	[
		'post_title'   => 'Leak test with a break',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => $paragraph( 'LEAKTEST-PUBLIC opening paragraph.' )
			. "<!-- wp:redeemid/paywall-break /-->\n\n"
			. $paragraph( 'LEAKTEST-SECRET-ITEM quokkaword locked paragraph.' ),
	],
	true
);

// No break: the teaser is the first blocks, the secret is past them.
$no_break = wp_insert_post(
	[
		'post_title'   => 'Leak test without a break',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => $paragraph( 'LEAKTEST-PUBLIC one.' ) . $paragraph( 'LEAKTEST-PUBLIC two.' ) . $paragraph( 'Three.' ) . $paragraph( 'LEAKTEST-SECRET-TAIL numbatword.' ),
	],
	true
);

if ( is_wp_error( $with_break ) || is_wp_error( $no_break ) ) {
	WP_CLI::error( "Could not create the test posts." );
}

foreach ( [ $with_break, $no_break ] as $item ) {
	ItemRule::save(
		$item,
		[
			'mode'       => Context::ITEM_LEVEL,
			'levels'     => [ 'gold' ],
			// The item with a break is also sold on its own (see the buyer below).
			'unlock_tag' => $with_break === $item ? 'leaktest-item' : '',
		]
	);
	// Saving again rebuilds the public search text with the rule in place.
	wp_update_post( [ 'ID' => $item ] );
}

// Every member-facing block that renders without a Shopify store, for the
// accessibility and performance checks (tests/Browser, lighthouserc.js).
$blocks = wp_insert_post(
	[
		'post_title'   => 'Member blocks',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => "<!-- wp:redeemid/auth-action /-->\n\n"
			. "<!-- wp:redeemid/access-badge /-->\n\n"
			. "<!-- wp:redeemid/member-account /-->\n\n"
			. "<!-- wp:redeemid/member-features /-->\n\n"
			. "<!-- wp:redeemid/member-preferences /-->\n\n"
			. "<!-- wp:redeemid/membership-refresh /-->\n\n"
			. "<!-- wp:redeemid/account-status /-->\n\n"
			. '<!-- wp:redeemid/membership-gate {"ruleSource":"level","level":"gold","fallbackMode":{"value":"message"},"fallbackMessage":"This part is for Gold members.","fallbackCtaLabel":"Join","showSignIn":true} -->' . "\n"
			. "<!-- wp:redeemid/membership-gate-content -->\n" . $paragraph( 'Gold members read this.' ) . "<!-- /wp:redeemid/membership-gate-content -->\n"
			. "<!-- wp:redeemid/membership-gate-fallback /-->\n"
			. "<!-- /wp:redeemid/membership-gate -->\n\n"
			. "<!-- wp:redeemid/cart-link /-->\n",
	],
	true
);

if ( is_wp_error( $blocks ) ) {
	WP_CLI::error( "Could not create the member blocks page." );
}

$comment_id = wp_insert_comment(
	[
		'comment_post_ID'  => $with_break,
		'comment_content'  => 'LEAKTEST-SECRET-COMMENT',
		'comment_approved' => 1,
		'comment_author'   => 'Leak test',
	]
);

$user = static function ( string $login ): int {
	$existing = get_user_by( 'login', $login );

	if ( $existing ) {
		return $existing->ID;
	}

	$id = wp_insert_user(
		[
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'subscriber',
		]
	);

	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not create {$login}." );
	}

	return $id;
};

// Signed in, never linked to Shopify.
$reader = $user( 'leak-reader' );

// Signed in and linked, but Shopify gave them no tag.
$linked = $user( 'leak-linked' );
Mirror::link( $linked, CustomerId::parse( 900000000001 ) );
Mirror::set_tags( $linked, [ 'newsletter' ] );
Mirror::set_status( $linked, 'enabled' );
Mirror::stamp( $linked, 'leaktest' );

// Linked with the Gold tag: the positive control.
$gold = $user( 'leak-gold' );
Mirror::link( $gold, CustomerId::parse( 900000000002 ) );
Mirror::set_tags( $gold, [ 'gold-member' ] );
Mirror::set_status( $gold, 'enabled' );
Mirror::stamp( $gold, 'leaktest' );

// Linked with only the item's own tag: bought the item with a break, nothing else.
$buyer = $user( 'leak-buyer' );
Mirror::link( $buyer, CustomerId::parse( 900000000003 ) );
Mirror::set_tags( $buyer, [ 'leaktest-item' ] );
Mirror::set_status( $buyer, 'enabled' );
Mirror::stamp( $buyer, 'leaktest' );

$cookie = static fn( int $id ): string => LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $id, time() + DAY_IN_SECONDS, 'logged_in' ) );

$seed = [
	'posts'   => [
		'break'    => [
			'id'   => $with_break,
			'link' => get_permalink( $with_break ),
		],
		'no_break' => [
			'id'   => $no_break,
			'link' => get_permalink( $no_break ),
		],
	],
	'pages'   => [
		'blocks' => [
			'id'   => $blocks,
			'link' => get_permalink( $blocks ),
		],
	],
	'comment' => (int) $comment_id,
	'cookies' => [
		'reader' => $cookie( $reader ),
		'linked' => $cookie( $linked ),
		'gold'   => $cookie( $gold ),
		'buyer'  => $cookie( $buyer ),
	],
];

// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- CI writes to its temp dir.
file_put_contents( $out, wp_json_encode( $seed, JSON_PRETTY_PRINT ) );
WP_CLI::success( "Leak test seed written to {$out}" );
