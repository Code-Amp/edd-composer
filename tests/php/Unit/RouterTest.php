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
 * @since 1.0.0
 */
final class RouterTest extends WP_UnitTestCase {
	/**
	 * Restores routing options and filters after each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Router::REWRITE_VERSION_OPTION );
		remove_all_filters( 'edd_composer_repository_base_url' );
		remove_all_filters( 'edd_composer_repository_name' );
		parent::tear_down();
	}

	/**
	 * Confirms all planned v1 endpoint patterns are registered.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Confirms rewrite schemas flush once and record their version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_records_rewrite_schema_after_flush() {
		$this->router()->maybe_flush_rewrite_rules();

		$this->assertSame( Router::REWRITE_VERSION, get_option( Router::REWRITE_VERSION_OPTION ) );
	}

	/**
	 * Confirms the landing document is generic and points at packages.json.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_repository_information_is_generic() {
		$data = ( new Responses() )->get_info_data();

		$this->assertSame( 'EDD Composer Repository', $data['name'] );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $data['host'] );
		$this->assertSame( home_url( '/composer/packages.json' ), $data['packages'] );
	}

	/**
	 * Creates an isolated router service.
	 *
	 * @since 1.0.0
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

		return new Router( new Package_Index( $settings, $products ), new Responses(), $download );
	}
}
