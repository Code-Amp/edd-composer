<?php
/**
 * Tests for administration REST routes.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Settings;

/**
 * Verifies REST permissions, reads, validation, and persistence.
 *
 * @since 1.0.0
 */
final class AdminControllerTest extends WP_UnitTestCase {
	/**
	 * REST server used by each test.
	 *
	 * @since 1.0.0
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Creates a fresh REST server and registers plugin routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	/**
	 * Restores settings and current user after each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		delete_transient( Settings::PACKAGE_INDEX_TRANSIENT );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Confirms unauthorized users cannot read administration data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_routes_reject_unauthorized_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/edd-composer/v1/settings' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'edd_composer_rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * Confirms managers can read settings and product discovery responses.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_manager_can_read_settings_and_products() {
		$this->set_manager_user();
		$download_id = $this->create_download_with_version();

		$settings_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/edd-composer/v1/settings' ) );

		$this->assertSame( 200, $settings_response->get_status() );
		$this->assertSame( 'EDD Composer Repository', $settings_response->get_data()['settings']['repository_name'] );
		$this->assertSame( 'vendor', $settings_response->get_data()['settings']['vendor'] );
		$this->assertStringEndsWith( '/composer', $settings_response->get_data()['repository']['url'] );
		$this->assertContains(
			$download_id,
			wp_list_pluck( $settings_response->get_data()['products'], 'id' )
		);
	}

	/**
	 * Confirms a valid settings document is persisted and returned.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_manager_can_save_valid_settings() {
		$this->set_manager_user();
		$download_id = $this->create_download_with_version();
		set_transient( Settings::PACKAGE_INDEX_TRANSIENT, array( 'stale' ), HOUR_IN_SECONDS );
		$request = new WP_REST_Request( 'POST', '/edd-composer/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'schema_version'  => 1,
					'repository_name' => 'Code Amp Packages',
					'vendor'          => 'code-amp',
					'products'        => array(
						(string) $download_id => array(
							'enabled'      => true,
							'package_slug' => 'example-package',
							'type'         => 'wordpress-plugin',
							'description'  => 'Example package.',
							'require_php'  => '>=8.0',
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$saved    = get_option( Settings::OPTION_NAME );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Code Amp Packages', $saved['repository_name'] );
		$this->assertSame( 'code-amp', $saved['vendor'] );
		$this->assertTrue( $saved['products'][ (string) $download_id ]['enabled'] );
		$this->assertFalse( get_transient( Settings::PACKAGE_INDEX_TRANSIENT ) );
		$this->assertSame( 1, $response->get_data()['repository']['package_count'] );
	}

	/**
	 * Confirms failed option persistence produces an explicit server error.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_failed_settings_persistence_returns_server_error() {
		$this->set_manager_user();
		$download_id = $this->create_download_with_version();
		$request     = new WP_REST_Request( 'POST', '/edd-composer/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'repository_name' => 'Blocked write',
					'vendor'          => 'code-amp',
					'products'        => array(
						(string) $download_id => array(
							'enabled'      => true,
							'package_slug' => 'example-package',
							'type'         => 'wordpress-plugin',
							'description'  => 'Example package.',
							'require_php'  => '>=8.0',
						),
					),
				)
			)
		);
		$block_write = static fn( $new_value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . Settings::OPTION_NAME, $block_write, 10, 2 );

		$response = $this->server->dispatch( $request );

		remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $block_write, 10 );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'edd_composer_settings_save_failed', $response->get_data()['code'] );
	}

	/**
	 * Confirms malformed settings are rejected without changing the option.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_invalid_settings_are_rejected_without_persistence() {
		$this->set_manager_user();
		$request = new WP_REST_Request( 'POST', '/edd-composer/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'repository_name' => 'Code Amp Packages',
					'vendor'          => 'Invalid Vendor',
					'products'        => array(),
				)
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'edd_composer_invalid_vendor', $response->get_data()['code'] );
		$this->assertSame(
			array(
				'schema_version'  => 1,
				'repository_name' => 'EDD Composer Repository',
				'vendor'          => 'vendor',
				'products'        => array(),
			),
			get_option( Settings::OPTION_NAME )
		);
	}

	/**
	 * Confirms an invalid product cannot be newly enabled through the REST API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_product_without_versioned_files_cannot_be_enabled() {
		$this->set_manager_user();
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => 'Unversioned Package',
			)
		);
		$request     = new WP_REST_Request( 'POST', '/edd-composer/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'repository_name' => 'EDD Composer Repository',
					'vendor'          => 'vendor',
					'products'        => array(
						(string) $download_id => array(
							'enabled'      => true,
							'package_slug' => 'unversioned-package',
							'type'         => 'wordpress-plugin',
							'description'  => '',
							'require_php'  => '>=7.4',
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'edd_composer_product_cannot_be_enabled', $response->get_data()['code'] );
		$this->assertSame(
			array(
				'schema_version'  => 1,
				'repository_name' => 'EDD Composer Repository',
				'vendor'          => 'vendor',
				'products'        => array(),
			),
			get_option( Settings::OPTION_NAME )
		);
	}

	/**
	 * Creates a user with EDD management access.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function set_manager_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_shop_settings' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Creates a published Download with one valid versioned file.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private function create_download_with_version() {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => 'Example Package',
				'post_name'   => 'example-package',
			)
		);
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );

		return $download_id;
	}
}
