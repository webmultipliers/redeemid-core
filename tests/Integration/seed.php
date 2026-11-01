<?php
/**
 * Seeds a throwaway WordPress for the leak tests. Run inside WordPress:
 *
 *     REDEEMID_LEAK_SEED=/tmp/leak.json wp eval-file tests/Integration/seed.php
 *
 * It writes the IDs, links and sign-in cookies LeakTest needs to the JSON
 * file named by REDEEMID_LEAK_SEED. Never run it on a real site: it adds
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
			'mode'   => Context::ITEM_LEVEL,
			'levels' => [ 'gold' ],
		]
	);
	// Saving again rebuilds the public search text with the rule in place.
	wp_update_post( [ 'ID' => $item ] );
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
	'comment' => (int) $comment_id,
	'cookies' => [
		'reader' => $cookie( $reader ),
		'linked' => $cookie( $linked ),
		'gold'   => $cookie( $gold ),
	],
];

// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- CI writes to its temp dir.
file_put_contents( $out, wp_json_encode( $seed, JSON_PRETTY_PRINT ) );
WP_CLI::success( "Leak test seed written to {$out}" );
