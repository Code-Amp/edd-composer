<?php
/**
 * Tests for Composer repository routing and information.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Products;
use EDD_Composer\Licensing\Authenticator;
use EDD_Composer\Licensing\Entitlements;
use EDD_Composer\Licensing\Order_Resolver;
use EDD_Composer\Repository\Download;
use EDD_Composer\Repository\Package_Index;
use EDD_Composer\Repository\Responses;
use EDD_Composer\Repository\Router;
use EDD_Composer\Repository\Versioned_Files;
use EDD_Composer\Settings;

/**
 * Verifies rewrite registration, URLs, and public information.
 *
 * @since 0.1.0
 */
final class RouterTest extends WP_UnitTestCase {
	/**
	 * Restores routing options and filters after each test.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		set_query_var( Router::ACTION_QUERY_VAR, '' );
		delete_option( Router::REWRITE_VERSION_OPTION );
		delete_option( Settings::OPTION_NAME );
		remove_all_filters( 'edd_composer_repository_base_url' );
		remove_all_filters( 'edd_composer_allowed_repository_origins' );
		remove_all_filters( 'edd_composer_repository_name' );
		parent::tear_down();
	}

	/**
	 * Confirms all planned v1 endpoint patterns are registered.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_registers_repository_rewrite_rules_and_query_vars() {
		global $wp, $wp_rewrite;

		Router::register_rewrite_rules();

		$this->assertArrayHasKey( '^composer/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^composer/packages\.json$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^composer/download/([^/]+)/([^/]+)/?$', $wp_rewrite->extra_rules_top );
		$this->assertContains( Router::ACTION_QUERY_VAR, $wp->public_query_vars );
		$this->assertContains( Router::PRODUCT_QUERY_VAR, $wp->public_query_vars );
		$this->assertContains( Router::VERSION_QUERY_VAR, $wp->public_query_vars );
	}

	/**
	 * Confirms generated URLs use the current WordPress origin and filters.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_generates_same_origin_repository_urls() {
		$this->assertSame( home_url( '/composer' ), Router::get_base_url() );
		$this->assertSame( home_url( '/composer/packages.json' ), Router::get_packages_url() );
		$this->assertSame(
			home_url( '/composer/download/example-package' ),
			Router::get_download_base_url( 'example-package' )
		);

		add_filter(
			'edd_composer_repository_base_url',
			static function () {
				return home_url( '/composer/' );
			}
		);

		$this->assertSame( home_url( '/composer' ), Router::get_base_url() );
	}

	/**
	 * Confirms canonical redirects are disabled only within the repository path.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_prevents_repository_canonical_redirects() {
		$router = $this->router();

		$this->assertFalse(
			$router->prevent_canonical_redirect( home_url( '/composer/' ), home_url( '/composer' ) )
		);
		$this->assertFalse(
			$router->prevent_canonical_redirect( home_url( '/composer/packages.json/' ), home_url( '/composer/packages.json' ) )
		);
		$this->assertSame(
			home_url( '/ordinary-page/' ),
			$router->prevent_canonical_redirect( home_url( '/ordinary-page/' ), home_url( '/ordinary-page' ) )
		);
	}

	/**
	 * Confirms proxy-forwarded internal routes remain exempt from canonical redirects.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_prevents_canonical_redirects_for_external_repository_paths() {
		add_filter( 'edd_composer_repository_base_url', static fn() => 'https://packages.example.com/private-repository' );
		add_filter(
			'edd_composer_allowed_repository_origins',
			static function ( $origins ) {
				$origins[] = 'https://packages.example.com';
				return $origins;
			}
		);

		$router = $this->router();

		$this->assertFalse(
			$router->prevent_canonical_redirect( home_url( '/composer/' ), home_url( '/composer/packages.json' ) )
		);

		set_query_var( Router::ACTION_QUERY_VAR, 'packages' );
		$this->assertFalse(
			$router->prevent_canonical_redirect( home_url( '/other/' ), home_url( '/forwarded-internally' ) )
		);

		set_query_var( Router::ACTION_QUERY_VAR, '' );
		$this->assertSame(
			home_url( '/ordinary-page/' ),
			$router->prevent_canonical_redirect( home_url( '/ordinary-page/' ), home_url( '/ordinary-page' ) )
		);
	}

	/**
	 * Confirms rewrite schemas flush once and record their version.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_records_rewrite_schema_after_flush() {
		$this->router()->maybe_flush_rewrite_rules();

		$this->assertSame( Router::REWRITE_VERSION, get_option( Router::REWRITE_VERSION_OPTION ) );
	}

	/**
	 * Confirms the landing document is generic and points at packages.json.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_repository_information_uses_the_configured_title() {
		$settings  = new Settings();
		$responses = new Responses( $settings );
		$data      = $responses->get_info_data();

		$this->assertSame( 'EDD Composer Repository', $data['name'] );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $data['host'] );
		$this->assertSame( home_url( '/composer/packages.json' ), $data['packages'] );

		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'repository_name' => 'Code Amp Packages',
				'vendor'          => 'code-amp',
				'products'        => array(),
			),
			false
		);

		$this->assertSame( 'Code Amp Packages', $responses->get_info_data()['name'] );
	}

	/**
	 * Creates an isolated router service.
	 *
	 * @since 0.1.0
	 * @return Router
	 */
	private function router() {
		$settings  = new Settings();
		$versioned = new Versioned_Files();
		$products  = new Products( $versioned );
		$download  = new Download(
			$settings,
			$versioned,
			new Authenticator(),
			new Entitlements(),
			new Order_Resolver()
		);

		return new Router( new Package_Index( $settings, $products ), new Responses( $settings ), $download );
	}
}
