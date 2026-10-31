<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Api\Contract;
use RedeemID\Api\Events;

/**
 * The developer API list (Api\Contract), the code and docs/developer-api.md
 * must agree: a listed hook that stopped firing, a new hook nobody listed, or
 * a name the docs page forgot all fail here.
 */
final class ApiContractTest extends TestCase {
	private static string $src = '';

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	private static function source(): string {
		if ( '' === self::$src ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/src', \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $files as $file ) {
				if ( 'php' === $file->getExtension() ) {
					self::$src .= file_get_contents( $file->getPathname() );
				}
			}

			foreach ( glob( self::root() . '/blocks/*/index.php' ) as $file ) {
				self::$src .= file_get_contents( $file );
			}
		}

		return self::$src;
	}

	private static function docs(): string {
		return (string) file_get_contents( self::root() . '/docs/developer-api.md' );
	}

	public function test_every_listed_action_fires(): void {
		$catalog = array_column( Events::CATALOG, 'action' );

		foreach ( Contract::ACTIONS as $action ) {
			if ( Events::HOOK === $action || in_array( $action, $catalog, true ) ) {
				continue; // Fired by Events::emit().
			}

			$this->assertSame( 1, preg_match( '/do_action\(\s*\'' . preg_quote( $action, '/' ) . '\'/', self::source() ), "$action is listed but never fires." );
		}
	}

	public function test_every_action_that_fires_is_listed(): void {
		preg_match_all( '/do_action\(\s*\'(redeemid_[a-z0-9_]+)\'/', self::source(), $m );

		foreach ( array_unique( $m[1] ) as $action ) {
			$this->assertContains( $action, Contract::actions(), "$action fires but is not in Api\\Contract." );
		}
	}

	public function test_every_event_has_a_listed_shortcut(): void {
		foreach ( Events::CATALOG as $name => $event ) {
			$this->assertSame( 'redeemid_' . $name, $event['action'] );
			$this->assertContains( $event['action'], Contract::ACTIONS );
		}
	}

	public function test_listed_filters_and_applied_filters_match(): void {
		foreach ( Contract::filters() as $filter ) {
			$this->assertSame( 1, preg_match( '/apply_filters\(\s*\'' . preg_quote( $filter, '/' ) . '/', self::source() ), "$filter is listed but never applied." );
		}

		preg_match_all( '/apply_filters\(\s*\'(redeemid_[a-z0-9_]+)\'/', self::source(), $m );

		foreach ( array_unique( $m[1] ) as $filter ) {
			$this->assertContains( $filter, Contract::filters(), "$filter is applied but is not in Api\\Contract." );
		}
	}

	public function test_listed_functions_and_defined_functions_match(): void {
		$functions = (string) file_get_contents( self::root() . '/src/functions.php' );
		preg_match_all( '/^function (redeemid_[a-z0-9_]+)\(/m', $functions, $m );

		$this->assertEqualsCanonicalizing( [ ...Contract::FUNCTIONS, ...Contract::INTERNAL_FUNCTIONS ], $m[1] );
	}

	public function test_listed_routes_are_registered(): void {
		foreach ( [ ...Contract::ROUTES, ...Contract::INTERNAL_ROUTES ] as $route ) {
			[ $method, $path ] = explode( ' ', $route, 2 );

			$this->assertContains( $method, [ 'GET', 'POST' ] );

			$this->assertTrue( str_contains( self::source(), "'" . $path . "'" ), "$route is listed but not registered." );
		}
	}

	public function test_the_docs_page_names_everything_stable(): void {
		$names = [
			...Contract::FUNCTIONS,
			...Contract::ACTIONS,
			...Contract::FILTERS,
			...Contract::EXPERIMENTAL_FILTERS,
			...Contract::ROUTES,
			...array_map( static fn( $m ) => 'redeemid.' . $m . '(', Contract::CLIENT_METHODS ),
			...Contract::CLIENT_EVENTS,
			...array_keys( Events::CATALOG ),
			Contract::CLIENT_HANDLE,
		];

		foreach ( $names as $name ) {
			$this->assertStringContainsString( $name, self::docs(), "docs/developer-api.md doesn't mention $name." );
		}
	}

	public function test_the_browser_client_has_every_listed_method_and_event(): void {
		$client = (string) file_get_contents( self::root() . '/assets/client.js' );

		foreach ( Contract::CLIENT_EVENTS as $event ) {
			$this->assertStringContainsString( "'" . $event . "'", $client );
		}

		preg_match( '/window\.redeemid = Object\.freeze\(\{([^}]*)\}\)/', $client, $m );
		$this->assertNotEmpty( $m );

		foreach ( Contract::CLIENT_METHODS as $method ) {
			$this->assertMatchesRegularExpression( '/\b' . $method . '\b/', $m[1], "window.redeemid has no $method." );
		}
	}

	public function test_the_runtime_version_matches_the_contract(): void {
		$plugin = (string) file_get_contents( self::root() . '/redeemid.php' );

		$this->assertMatchesRegularExpression( '/define\( \'REDEEMID_API_VERSION\', \'' . preg_quote( Contract::VERSION, '/' ) . '\' \);/', $plugin );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Contract::VERSION );
	}
}
