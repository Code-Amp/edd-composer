<?php
/**
 * Composer repository routing.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and dispatches the public Composer repository endpoints.
 *
 * @since 1.0.0
 */
final class Router {
	/**
	 * Rewrite schema version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const REWRITE_VERSION = '1';

	/**
	 * Stored rewrite schema option.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const REWRITE_VERSION_OPTION = 'edd_composer_rewrite_version';

	/**
	 * Repository-action query variable.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ACTION_QUERY_VAR = 'edd_composer_action';

	/**
	 * Download product query variable.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PRODUCT_QUERY_VAR = 'edd_composer_product';

	/**
	 * Download version query variable.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const VERSION_QUERY_VAR = 'edd_composer_version';

	/**
	 * Package-index service.
	 *
	 * @since 1.0.0
	 * @var Package_Index
	 */
	private $package_index;

	/**
	 * Response service.
	 *
	 * @since 1.0.0
	 * @var Responses
	 */
	private $responses;

	/**
	 * Protected-download service.
	 *
	 * @since 1.0.0
	 * @var Download
	 */
	private $download;

	/**
	 * Creates the repository router.
	 *
	 * @since 1.0.0
	 *
	 * @param Package_Index $package_index Package-index service.
	 * @param Responses     $responses     Response service.
	 * @param Download      $download      Protected-download service.
	 */
	public function __construct( Package_Index $package_index, Responses $responses, Download $download ) {
		$this->package_index = $package_index;
		$this->responses     = $responses;
		$this->download      = $download;
	}

	/**
	 * Registers public routing hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_action( 'template_redirect', array( $this, 'dispatch' ) );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Registers the base, package-index, and protected-download routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		add_rewrite_rule(
			'^composer/?$',
			'index.php?' . self::ACTION_QUERY_VAR . '=info',
			'top'
		);
		add_rewrite_rule(
			'^composer/packages\.json$',
			'index.php?' . self::ACTION_QUERY_VAR . '=packages',
			'top'
		);
		add_rewrite_rule(
			'^composer/download/([^/]+)/([^/]+)/?$',
			'index.php?' . self::ACTION_QUERY_VAR . '=download&' . self::PRODUCT_QUERY_VAR . '=$matches[1]&' . self::VERSION_QUERY_VAR . '=$matches[2]',
			'top'
		);

		add_rewrite_tag( '%' . self::ACTION_QUERY_VAR . '%', '([^&]+)' );
		add_rewrite_tag( '%' . self::PRODUCT_QUERY_VAR . '%', '([^&]+)' );
		add_rewrite_tag( '%' . self::VERSION_QUERY_VAR . '%', '([^&]+)' );
	}

	/**
	 * Gets the filterable repository base URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_base_url() {
		return ( new URL_Policy() )->get_repository_base_url();
	}

	/**
	 * Gets the public packages document URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_packages_url() {
		return self::get_base_url() . '/packages.json';
	}

	/**
	 * Gets the protected download URL prefix for a package slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $package_slug Package slug.
	 * @return string
	 */
	public static function get_download_base_url( $package_slug ) {
		return self::get_base_url() . '/download/' . rawurlencode( $package_slug );
	}

	/**
	 * Flushes rewrite rules once when their schema changes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( self::REWRITE_VERSION_OPTION ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false );
	}

	/**
	 * Dispatches a parsed Composer repository request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function dispatch() {
		$action = sanitize_key( (string) get_query_var( self::ACTION_QUERY_VAR ) );

		if ( '' === $action ) {
			return;
		}

		switch ( $action ) {
			case 'info':
				$this->responses->serve_info( $this->package_index->get_cache_ttl() );
				break;

			case 'packages':
				$this->responses->serve_packages(
					$this->package_index->get_payload(),
					$this->package_index->get_cache_ttl()
				);
				break;

			case 'download':
				$result = $this->download->prepare(
					(string) get_query_var( self::PRODUCT_QUERY_VAR ),
					(string) get_query_var( self::VERSION_QUERY_VAR )
				);

				if ( is_wp_error( $result ) ) {
					$this->responses->serve_wp_error( $result );
				}

				/**
				 * Fires after a Composer download is authorized and signed.
				 *
				 * Arguments intentionally contain only internal IDs and public package
				 * metadata. License keys, customer data, and activated site URLs are
				 * never exposed to the hook.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $download_id EDD Download ID.
				 * @param string $version Canonical Composer version.
				 * @param string $product_slug Public configured package slug.
				 * @param int    $license_id Target EDD SL license ID.
				 * @param int    $order_id EDD order ID used to sign the URL.
				 */
				do_action(
					'edd_composer_download',
					$result['download_id'],
					$result['version'],
					$result['product_slug'],
					$result['license_id'],
					$result['order_id']
				);

				$this->responses->serve_redirect( $result['redirect_url'] );
				break;

			default:
				$this->responses->serve_error(
					404,
					'edd_composer_not_found',
					__( 'Repository endpoint not found.', 'edd-composer' )
				);
		}
	}

	/**
	 * Prevents WordPress from canonicalizing repository endpoint URLs.
	 *
	 * @since 1.0.0
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( '' !== sanitize_key( (string) get_query_var( self::ACTION_QUERY_VAR ) ) ) {
			return false;
		}

		$requested_path = untrailingslashit( (string) wp_parse_url( $requested_url, PHP_URL_PATH ) );
		$base_paths     = array_unique(
			array(
				untrailingslashit( (string) wp_parse_url( home_url( '/composer' ), PHP_URL_PATH ) ),
				untrailingslashit( (string) wp_parse_url( self::get_base_url(), PHP_URL_PATH ) ),
			)
		);

		foreach ( array_filter( $base_paths ) as $base_path ) {
			if ( $requested_path === $base_path || str_starts_with( $requested_path, $base_path . '/' ) ) {
				return false;
			}
		}

		return $redirect_url;
	}
}
