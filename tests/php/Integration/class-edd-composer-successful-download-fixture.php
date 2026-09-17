<?php
/**
 * Provisions an isolated live-download fixture for the wp-env HTTP check.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Repository\Package_Index;
use EDD_Composer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the successful protected-download integration fixture.
 *
 * @since 1.0.0
 */
final class EDD_Composer_Successful_Download_Fixture {
	/**
	 * Temporary option containing fixture resources and the prior settings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const STATE_OPTION = 'edd_composer_test_successful_download_fixture';

	/**
	 * Fixture package version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VERSION = '1.0.0';

	/**
	 * Runs the requested fixture operation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Fixture action.
	 * @throws RuntimeException When the action is invalid or fixture setup fails.
	 * @return void
	 */
	public static function run( $action ) {
		if ( 'cleanup' === $action ) {
			self::cleanup();
			return;
		}

		if ( 'setup' !== $action ) {
			throw new RuntimeException( 'Expected a setup or cleanup fixture action.' );
		}

		self::setup();
	}

	/**
	 * Creates a real Download, order, license, activation, and ZIP file.
	 *
	 * @since 1.0.0
	 * @throws RuntimeException When a fixture resource cannot be created.
	 * @throws Throwable When a fixture API raises another setup error.
	 * @return void
	 */
	private static function setup() {
		self::cleanup();

		$existing_settings = get_option( Settings::OPTION_NAME, false );
		$identifier        = strtolower( wp_generate_password( 12, false, false ) );
		$package_slug      = 'integration-check-' . $identifier;
		$license_key       = 'edd-composer-integration-' . $identifier;
		$file_name         = 'edd-composer-integration-' . $identifier . '.zip';
		$file_path         = trailingslashit( edd_get_upload_dir() ) . $file_name;
		$file_url          = trailingslashit( edd_get_upload_url() ) . $file_name;
		$state             = array(
			'settings_existed'  => false !== $existing_settings,
			'previous_settings' => false !== $existing_settings ? $existing_settings : array(),
			'file_path'         => $file_path,
			'download_id'       => 0,
			'order_id'          => 0,
			'order_item_id'     => 0,
			'license_id'        => 0,
			'activation_id'     => 0,
		);

		update_option( self::STATE_OPTION, $state, false );

		try {
			$zip = new ZipArchive();

			if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Could not create the integration ZIP fixture.' );
			}

			$zip->addFromString(
				'edd-composer-integration/edd-composer-integration.php',
				"<?php\n/** Integration download fixture. */\n"
			);
			$zip->close();

			$download_id = wp_insert_post(
				array(
					'post_type'   => 'download',
					'post_status' => 'publish',
					'post_title'  => 'EDD Composer Successful Download Integration Fixture',
					'post_name'   => $package_slug,
				),
				true
			);

			if ( is_wp_error( $download_id ) ) {
				throw new RuntimeException( $download_id->get_error_message() );
			}

			$state['download_id'] = $download_id;
			self::save_state( $state );

			update_post_meta(
				$download_id,
				'edd_download_files',
				array(
					array(
						'name'    => 'EDD Composer Integration Package',
						'file'    => $file_url,
						'version' => self::VERSION,
					),
				)
			);
			update_post_meta( $download_id, '_edd_sl_enabled', 1 );
			update_option(
				Settings::OPTION_NAME,
				array(
					'schema_version' => Settings::SCHEMA_VERSION,
					'vendor'         => 'integration-test',
					'products'       => array(
						(string) $download_id => array(
							'enabled'      => true,
							'package_slug' => $package_slug,
							'type'         => 'wordpress-plugin',
							'description'  => 'Protected download integration fixture.',
							'require_php'  => '>=8.0',
						),
					),
				)
			);

			$order_id = edd_add_order(
				array(
					'status'      => 'complete',
					'type'        => 'sale',
					'email'       => 'composer-integration@example.org',
					'gateway'     => 'manual',
					'mode'        => 'test',
					'currency'    => 'USD',
					'payment_key' => 'edd-composer-integration-order-' . $identifier,
					'subtotal'    => 10,
					'total'       => 10,
				)
			);

			if ( false === $order_id ) {
				throw new RuntimeException( 'Could not create the integration EDD order.' );
			}

			$state['order_id'] = $order_id;
			self::save_state( $state );

			$order_item_id = edd_add_order_item(
				array(
					'order_id'     => $order_id,
					'product_id'   => $download_id,
					'product_name' => 'EDD Composer Integration Package',
					'status'       => 'complete',
					'quantity'     => 1,
					'amount'       => 10,
					'subtotal'     => 10,
					'total'        => 10,
				)
			);

			if ( false === $order_item_id ) {
				throw new RuntimeException( 'Could not create the integration EDD order item.' );
			}

			$state['order_item_id'] = $order_item_id;
			self::save_state( $state );

			$license_id = edd_software_licensing()->licenses_db->insert(
				array(
					'license_key'  => $license_key,
					'status'       => 'active',
					'download_id'  => $download_id,
					'payment_id'   => $order_id,
					'cart_index'   => 0,
					'date_created' => current_time( 'mysql' ),
					'expiration'   => 0,
					'parent'       => 0,
				),
				'license'
			);

			if ( false === $license_id ) {
				throw new RuntimeException( 'Could not create the integration EDD license.' );
			}

			$state['license_id'] = $license_id;
			self::save_state( $state );

			$activation_id = edd_software_licensing()->activations_db->insert(
				array(
					'site_name'  => edd_software_licensing()->clean_site_url( home_url() ),
					'license_id' => $license_id,
					'activated'  => 1,
					'is_local'   => 1,
				),
				'site_activation'
			);

			if ( false === $activation_id ) {
				throw new RuntimeException( 'Could not create the integration EDD activation.' );
			}

			$state['activation_id'] = $activation_id;
			self::save_state( $state );

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable CLI-only fixture data.
			echo wp_json_encode(
				array(
					'package_slug' => $package_slug,
					'version'      => self::VERSION,
					'license_key'  => $license_key,
					'site_url'     => home_url(),
					'file_size'    => filesize( $file_path ),
					'file_sha256'  => hash_file( 'sha256', $file_path ),
				)
			) . "\n";
		} catch ( Throwable $error ) {
			self::cleanup();
			throw $error;
		}
	}

	/**
	 * Removes all fixture resources and restores the original plugin settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function cleanup() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) ) {
			return;
		}

		$order_id = absint( $state['order_id'] ?? 0 );

		if ( $order_id && function_exists( 'edd_get_file_download_logs' ) ) {
			foreach ( edd_get_file_download_logs( array( 'order_id' => $order_id ) ) as $log ) {
				edd_delete_file_download_log( $log->id );
			}
		}

		if ( ! empty( $state['activation_id'] ) ) {
			edd_software_licensing()->activations_db->delete( absint( $state['activation_id'] ) );
		}

		if ( ! empty( $state['license_id'] ) ) {
			edd_software_licensing()->licenses_db->delete( absint( $state['license_id'] ) );
		}

		if ( ! empty( $state['order_item_id'] ) ) {
			edd_delete_order_item( absint( $state['order_item_id'] ) );
		}

		if ( $order_id ) {
			edd_delete_order( $order_id );
		}

		if ( ! empty( $state['download_id'] ) ) {
			wp_delete_post( absint( $state['download_id'] ), true );
		}

		if ( ! empty( $state['file_path'] ) && is_file( $state['file_path'] ) ) {
			wp_delete_file( $state['file_path'] );
		}

		if ( ! empty( $state['settings_existed'] ) ) {
			update_option( Settings::OPTION_NAME, $state['previous_settings'] );
		} else {
			delete_option( Settings::OPTION_NAME );
		}

		delete_transient( Package_Index::TRANSIENT_NAME );
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Persists fixture state after each created resource.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $state Fixture state.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}
}

// `$args` is supplied by `wp eval-file`.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$edd_composer_fixture_action = isset( $args[0] ) ? (string) $args[0] : '';
EDD_Composer_Successful_Download_Fixture::run( $edd_composer_fixture_action );
