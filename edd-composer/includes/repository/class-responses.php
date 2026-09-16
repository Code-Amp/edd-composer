<?php
/**
 * Composer endpoint responses.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Produces consistent JSON and cache headers for repository endpoints.
 *
 * @since 1.0.0
 */
final class Responses {
	/**
	 * Returns public repository information.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function get_info_data() {
		/**
		 * Filters the public Composer repository name.
		 *
		 * @since 1.0.0
		 *
		 * @param string $name Repository name.
		 */
		$name = apply_filters( 'edd_composer_repository_name', __( 'EDD Composer Repository', 'edd-composer' ) );

		return array(
			'name'     => is_string( $name ) ? $name : __( 'EDD Composer Repository', 'edd-composer' ),
			'host'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'packages' => Router::get_packages_url(),
		);
	}

	/**
	 * Serves repository information as cacheable JSON.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param string $url Same-origin EDD download-handler URL.
	 * @return void
	 */
	public function serve_redirect( $url ) {
		$this->send_private_headers();

		if ( ! wp_safe_redirect( $url, 302, 'EDD Composer Extension' ) ) {
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
