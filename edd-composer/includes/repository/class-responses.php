<?php
/**
 * Composer endpoint responses.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

use EDD_Composer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Produces consistent JSON and cache headers for repository endpoints.
 *
 * @since 0.1.0
 */
final class Responses {
	/**
	 * Settings service.
	 *
	 * @since 0.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Public and signed-download URL policy.
	 *
	 * @since 0.1.0
	 * @var URL_Policy
	 */
	private $url_policy;

	/**
	 * Creates the response service.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings        $settings   Settings service.
	 * @param URL_Policy|null $url_policy Optional URL policy.
	 */
	public function __construct( Settings $settings, ?URL_Policy $url_policy = null ) {
		$this->settings   = $settings;
		$this->url_policy = $url_policy ? $url_policy : new URL_Policy();
	}

	/**
	 * Returns public repository information.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_info_data() {
		/**
		 * Filters the public Composer repository name.
		 *
		 * @since 0.1.0
		 *
		 * @param string $name Repository name.
		 */
		$settings = $this->settings->get();
		$default  = $settings['repository_name'];
		$name     = apply_filters( 'edd_composer_repository_name', $default );
		$name     = is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : $default;

		return array(
			'name'     => $name,
			'host'     => (string) wp_parse_url( Router::get_base_url(), PHP_URL_HOST ),
			'packages' => Router::get_packages_url(),
		);
	}

	/**
	 * Serves repository information as cacheable JSON.
	 *
	 * @since 0.1.0
	 *
	 * @param int $ttl Public cache lifetime in seconds.
	 * @return void
	 */
	public function serve_info( $ttl ) {
		$this->send_json( $this->get_info_data(), 200, 'public, max-age=' . absint( $ttl ) );
	}

	/**
	 * Serves the Composer packages document as cacheable JSON.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload Composer packages document.
	 * @param int                  $ttl     Public cache lifetime in seconds.
	 * @return void
	 */
	public function serve_packages( array $payload, $ttl ) {
		$this->send_json( $payload, 200, 'public, max-age=' . absint( $ttl ) );
	}

	/**
	 * Serves a non-cacheable JSON error.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable error message.
	 * @return void
	 */
	public function serve_error( $status, $code, $message ) {
		$this->send_private_headers();

		if ( 401 === absint( $status ) ) {
			header( 'WWW-Authenticate: Basic realm="EDD Composer Repository", charset="UTF-8"', true );
		}

		$this->send_json(
			array(
				'code'    => sanitize_key( $code ),
				'message' => $message,
			),
			absint( $status ),
			'private, no-store, no-cache, must-revalidate, max-age=0'
		);
	}

	/**
	 * Serves an HTTP-aware WordPress error.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Error $error Repository error.
	 * @return void
	 */
	public function serve_wp_error( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 500;

		$this->serve_error(
			$status >= 400 && $status <= 599 ? $status : 500,
			$error->get_error_code(),
			$error->get_error_message()
		);
	}

	/**
	 * Redirects to an EDD-signed URL with protected-response headers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Validated EDD download-handler URL.
	 * @return void
	 */
	public function serve_redirect( $url ) {
		$this->send_private_headers();
		add_filter( 'allowed_redirect_hosts', array( $this->url_policy, 'allow_download_proxy_host' ) );
		$redirected = wp_safe_redirect( $url, 302, 'EDD Composer Extension' );
		remove_filter( 'allowed_redirect_hosts', array( $this->url_policy, 'allow_download_proxy_host' ) );

		if ( ! $redirected ) {
			$this->serve_error(
				500,
				'edd_composer_redirect_failed',
				__( 'The secure package redirect could not be sent.', 'edd-composer' )
			);
		}

		exit;
	}

	/**
	 * Applies the cache policy shared by protected redirects and errors.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function send_private_headers() {
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	}

	/**
	 * Emits and terminates a JSON response.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data          Response document.
	 * @param int                  $status        HTTP status.
	 * @param string               $cache_control Cache-Control value.
	 * @return void
	 */
	private function send_json( array $data, $status, $cache_control ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: ' . $cache_control, true );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON endpoint output is encoded by WordPress.
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
