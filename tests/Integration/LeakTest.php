<?php

namespace RedeemID\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end leak tests: a real WordPress, seeded by seed.php, fetched over
 * HTTP the way a reader, a feed reader, a search box or a scraper would.
 * Locked text must never come back on any public surface to anyone who
 * can't read the item, and must come back to a member (so the test can't
 * pass by locking everything).
 *
 * Runs in CI's "Leak tests" job. Skipped unless REDEEMID_LEAK_URL (the
 * site) and REDEEMID_LEAK_SEED (the JSON seed.php wrote) are set.
 */
final class LeakTest extends TestCase {
	private const SECRET = 'LEAKTEST-SECRET';

	/** @var array<string, mixed>|null */
	private static ?array $seed = null;

	private static string $url = '';

	public static function setUpBeforeClass(): void {
		$url  = (string) getenv( 'REDEEMID_LEAK_URL' );
		$file = (string) getenv( 'REDEEMID_LEAK_SEED' );

		if ( '' === $url || '' === $file || ! is_readable( $file ) ) {
			return;
		}

		self::$url  = rtrim( $url, '/' );
		self::$seed = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local file CI wrote.
	}

	protected function setUp(): void {
		if ( null === self::$seed ) {
			$this->markTestSkipped( 'Set REDEEMID_LEAK_URL and REDEEMID_LEAK_SEED (see tests/Integration/seed.php).' );
		}
	}

	/**
	 * Every public surface that can carry a post's text, its comments or a
	 * hint that the locked text exists.
	 *
	 * @return array<string, array{string}>
	 */
	public static function surfaces(): array {
		return [
			'single (break)'           => [ '/?p={break}' ],
			'single (no break)'        => [ '/?p={no_break}' ],
			'home'                     => [ '/' ],
			'category archive'         => [ '/?cat=1' ],
			'author archive'           => [ '/?author=1' ],
			'search, locked word'      => [ '/?s=quokkaword' ],
			'search, tail word'        => [ '/?s=numbatword' ],
			'RSS feed'                 => [ '/?feed=rss2' ],
			'Atom feed'                => [ '/?feed=atom' ],
			'comments feed'            => [ '/?feed=comments-rss2' ],
			'post comments feed'       => [ '/?p={break}&feed=rss2' ],
			'REST post'                => [ '/?rest_route=/wp/v2/posts/{break}' ],
			'REST post (no break)'     => [ '/?rest_route=/wp/v2/posts/{no_break}' ],
			'REST posts list'          => [ '/?rest_route=/wp/v2/posts' ],
			'REST posts, embed'        => [ '/?rest_route=/wp/v2/posts&_embed=1' ],
			'REST post search'         => [ '/?rest_route=/wp/v2/posts&search=quokkaword' ],
			'REST site search'         => [ '/?rest_route=/wp/v2/search&search=quokkaword' ],
			'REST comments'            => [ '/?rest_route=/wp/v2/comments&post={break}' ],
			'REST comment'             => [ '/?rest_route=/wp/v2/comments/{comment}' ],
			'REST post, edit context'  => [ '/?rest_route=/wp/v2/posts/{break}&context=edit' ],
			'oEmbed JSON'              => [ '/?rest_route=/oembed/1.0/embed&url={break_link}' ],
			'oEmbed iframe'            => [ '/?p={break}&embed=true' ],
			'sitemap'                  => [ '/?sitemap=posts&sitemap-subtype=post&paged=1' ],
		];
	}

	#[DataProvider( 'surfaces' )]
	public function test_guest_never_sees_locked_text( string $path ): void {
		$this->assertNoSecret( $path, '' );
	}

	#[DataProvider( 'surfaces' )]
	public function test_signed_in_reader_never_sees_locked_text( string $path ): void {
		$this->assertNoSecret( $path, self::$seed['cookies']['reader'] );
	}

	#[DataProvider( 'surfaces' )]
	public function test_linked_member_without_the_tag_never_sees_locked_text( string $path ): void {
		$this->assertNoSecret( $path, self::$seed['cookies']['linked'] );
	}

	public function test_locked_page_shows_teaser_and_wall(): void {
		[ $status, $body ] = $this->fetch( '/?p={break}', '' );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'LEAKTEST-PUBLIC', $body );
		$this->assertStringContainsString( 'redeemid-paywall', $body );
	}

	public function test_access_header_tells_caches_who_saw_what(): void {
		[ , , $guest ] = $this->fetch( '/?p={break}', '' );
		[ , , $gold ]  = $this->fetch( '/?p={break}', self::$seed['cookies']['gold'] );

		$this->assertSame( 'locked', $guest['x-redeemid-access'] ?? '' );
		$this->assertSame( 'granted', $gold['x-redeemid-access'] ?? '' );
		$this->assertStringContainsString( 'private', $gold['cache-control'] ?? '' );
	}

	/**
	 * A search for a word that is only in the locked part must not list the
	 * item, or the result itself confirms the word is there.
	 *
	 * @return array<string, array{string}>
	 */
	public static function searches(): array {
		return [
			'search page'        => [ '/?s=quokkaword' ],
			'search page (tail)' => [ '/?s=numbatword' ],
			'REST posts search'  => [ '/?rest_route=/wp/v2/posts&search=quokkaword' ],
			'REST site search'   => [ '/?rest_route=/wp/v2/search&search=quokkaword' ],
		];
	}

	#[DataProvider( 'searches' )]
	public function test_search_does_not_confirm_a_locked_word( string $path ): void {
		foreach ( [ '', self::$seed['cookies']['reader'] ] as $cookie ) {
			[ , $body ] = $this->fetch( $path, $cookie );

			$this->assertStringNotContainsString( 'Leak test with', $body, "{$path} listed a post for a word only in its locked part." );
		}
	}

	public function test_gold_member_reads_the_locked_text(): void {
		[ $status, $body ] = $this->fetch( '/?p={break}', self::$seed['cookies']['gold'] );

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'LEAKTEST-SECRET-ITEM', $body );
		$this->assertStringContainsString( 'LEAKTEST-SECRET-COMMENT', $body );
	}

	public function test_gold_member_finds_the_locked_word(): void {
		[ , $body ] = $this->fetch( '/?s=quokkaword', self::$seed['cookies']['gold'] );

		$this->assertStringContainsString( 'Leak test with a break', $body );
	}

	private function assertNoSecret( string $path, string $cookie ): void {
		[ $status, $body ] = $this->fetch( $path, $cookie );

		$this->assertLessThan( 500, $status, "{$path} failed with HTTP {$status}." );
		$this->assertStringNotContainsString( self::SECRET, $body, "Locked text leaked on {$path}." );
	}

	/**
	 * @return array{int, string, array<string, string>}
	 */
	private function fetch( string $path, string $cookie ): array {
		$posts = self::$seed['posts'];
		$path  = strtr(
			$path,
			[
				'{break}'      => (string) $posts['break']['id'],
				'{no_break}'   => (string) $posts['no_break']['id'],
				'{break_link}' => rawurlencode( (string) $posts['break']['link'] ),
				'{comment}'    => (string) self::$seed['comment'],
			]
		);

		$headers = [];
		$curl    = curl_init( self::$url . $path );

		curl_setopt_array(
			$curl,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_COOKIE         => $cookie,
				CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$headers ): int {
					if ( str_contains( $line, ':' ) ) {
						[ $name, $value ]                         = explode( ':', $line, 2 );
						$headers[ strtolower( trim( $name ) ) ] = trim( $value );
					}

					return strlen( $line );
				},
			]
		);

		$body   = (string) curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );

		return [ $status, $body, $headers ];
	}
}
