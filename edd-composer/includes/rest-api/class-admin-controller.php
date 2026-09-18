<?php
/**
 * Administration REST routes.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\REST_API;

use EDD_Composer\Products;
use EDD_Composer\Repository\Router;
use EDD_Composer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes settings and EDD product discovery to the admin application.
 *
 * @since 1.0.0
 */
final class Admin_Controller extends \WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $namespace = 'edd-composer/v1';

	/**
	 * Settings service.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Product catalogue service.
	 *
	 * @since 1.0.0
	 * @var Products
	 */
	private $products;

	/**
	 * Resolves the current management capability.
	 *
	 * @since 1.0.0
	 * @var callable
	 */
	private $capability;

	/**
	 * Creates the administration REST controller.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings   Settings service.
	 * @param Products $products   Product catalogue service.
	 * @param callable $capability Management capability resolver.
	 */
	public function __construct( Settings $settings, Products $products, callable $capability ) {
		$this->settings   = $settings;
		$this->products   = $products;
		$this->capability = $capability;
	}

	/**
	 * Registers the REST initialization hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers settings and product catalogue endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				'schema' => array( $this, 'get_settings_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/products',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_products' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				'schema' => array( $this, 'get_products_schema' ),
			)
		);
	}

	/**
	 * Checks the shared management capability.
	 *
	 * @since 1.0.0
	 *
	 * @return true|\WP_Error
	 */
	public function permissions_check() {
		$capability = call_user_func( $this->capability );

		if ( is_string( $capability ) && current_user_can( $capability ) ) {
			return true;
		}

		return new \WP_Error(
			'edd_composer_rest_forbidden',
			__( 'You do not have permission to manage EDD Composer Extension.', 'edd-composer' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns the current settings and repository summary.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$settings  = $this->settings->get();
		$catalogue = $this->products->get_catalogue( $settings );

		return rest_ensure_response(
			array_merge(
				$this->prepare_settings_response( $settings, $catalogue ),
				array( 'products' => $catalogue )
			)
		);
	}

	/**
	 * Returns the enriched EDD product catalogue.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function get_products() {
		$settings = $this->settings->get();

		return rest_ensure_response(
			array( 'products' => $this->products->get_catalogue( $settings ) )
		);
	}

	/**
	 * Validates and persists a complete settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$validated = $this->settings->validate( $request->get_json_params() );

		if ( is_wp_error( $validated ) ) {
			$validated->add_data( array( 'status' => 400 ) );
			return $validated;
		}

		$current   = $this->settings->get();
		$catalogue = $this->products->get_catalogue( $validated );

		foreach ( $catalogue as $product ) {
			$product_id      = (string) $product['id'];
			$was_enabled     = ! empty( $current['products'][ $product_id ]['enabled'] );
			$will_be_enabled = ! empty( $validated['products'][ $product_id ]['enabled'] );

			if ( ! $was_enabled && $will_be_enabled && ! $product['can_enable'] ) {
				return new \WP_Error(
					'edd_composer_product_cannot_be_enabled',
					sprintf(
						/* translators: %s: EDD Download title. */
						__( '“%s” cannot be enabled until its validation issues are resolved.', 'edd-composer' ),
						$product['title']
					),
					array(
						'status'   => 400,
						'product'  => $product['id'],
						'messages' => $product['file_validation_messages'],
					)
				);
			}
		}

		if ( ! $this->settings->save( $validated ) ) {
			return new \WP_Error(
				'edd_composer_settings_save_failed',
				__( 'The Composer package settings could not be saved.', 'edd-composer' ),
				array( 'status' => 500 )
			);
		}

		delete_transient( Settings::PACKAGE_INDEX_TRANSIENT );

		return rest_ensure_response(
			array_merge(
				$this->prepare_settings_response( $validated, $catalogue ),
				array( 'products' => $catalogue )
			)
		);
	}

	/**
	 * Gets the REST schema for the settings response.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_schema() {
		$products_schema = $this->get_products_schema();

		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'edd-composer-settings',
			'type'                 => 'object',
			'required'             => array( 'settings', 'repository', 'products' ),
			'additionalProperties' => false,
			'properties'           => array(
				'settings'   => array(
					'type'                 => 'object',
					'required'             => array( 'schema_version', 'repository_name', 'vendor', 'products' ),
					'additionalProperties' => false,
					'properties'           => array(
						'schema_version'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'repository_name' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'vendor'          => array( 'type' => 'string' ),
						'products'        => array(
							'type'                 => 'object',
							'additionalProperties' => $this->get_product_settings_schema(),
						),
					),
				),
				'repository' => array(
					'type'                 => 'object',
					'required'             => array( 'url', 'package_count', 'cache_state' ),
					'additionalProperties' => false,
					'properties'           => array(
						'url'           => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'package_count' => array( 'type' => 'integer' ),
						'cache_state'   => array( 'type' => 'string' ),
					),
				),
				'products'   => $products_schema['properties']['products'],
			),
		);
	}

	/**
	 * Gets the REST schema for the product catalogue response.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_products_schema() {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'edd-composer-products',
			'type'                 => 'object',
			'required'             => array( 'products' ),
			'additionalProperties' => false,
			'properties'           => array(
				'products' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'required'             => array(
							'id',
							'title',
							'status',
							'status_label',
							'edit_url',
							'default_package_slug',
							'default_description',
							'enabled',
							'package_slug',
							'package_name',
							'type',
							'description',
							'require_php',
							'versioned_file_count',
							'versions',
							'file_validation_messages',
							'can_enable',
						),
						'additionalProperties' => false,
						'properties'           => array(
							'id'                       => array( 'type' => 'integer' ),
							'title'                    => array( 'type' => 'string' ),
							'status'                   => array( 'type' => 'string' ),
							'status_label'             => array( 'type' => 'string' ),
							'edit_url'                 => array( 'type' => array( 'string', 'null' ) ),
							'default_package_slug'     => array( 'type' => 'string' ),
							'default_description'      => array( 'type' => 'string' ),
							'enabled'                  => array( 'type' => 'boolean' ),
							'package_slug'             => array( 'type' => 'string' ),
							'package_name'             => array( 'type' => 'string' ),
							'type'                     => array( 'type' => 'string' ),
							'description'              => array( 'type' => 'string' ),
							'require_php'              => array( 'type' => 'string' ),
							'versioned_file_count'     => array( 'type' => 'integer' ),
							'versions'                 => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'file_validation_messages' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'can_enable'               => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Gets the schema shared by each persisted product configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private function get_product_settings_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'enabled', 'package_slug', 'type', 'description', 'require_php' ),
			'additionalProperties' => false,
			'properties'           => array(
				'enabled'      => array( 'type' => 'boolean' ),
				'package_slug' => array( 'type' => 'string' ),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'wordpress-plugin' ),
				),
				'description'  => array( 'type' => 'string' ),
				'require_php'  => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Builds the settings response and its non-sensitive repository summary.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>                  $settings  Current settings.
	 * @param array<int, array<string, mixed>>|null $catalogue Optional prebuilt catalogue.
	 * @return array<string, mixed>
	 */
	private function prepare_settings_response( array $settings, ?array $catalogue = null ) {
		$catalogue     = null === $catalogue ? $this->products->get_catalogue( $settings ) : $catalogue;
		$package_count = 0;

		foreach ( $catalogue as $product ) {
			if ( $product['enabled'] && $product['can_enable'] ) {
				++$package_count;
			}
		}

		return array(
			'settings'   => $settings,
			'repository' => array(
				'url'           => Router::get_base_url(),
				'package_count' => $package_count,
				'cache_state'   => false === get_transient( Settings::PACKAGE_INDEX_TRANSIENT )
					? __( 'Not cached', 'edd-composer' )
					: __( 'Cached', 'edd-composer' ),
			),
		);
	}
}
