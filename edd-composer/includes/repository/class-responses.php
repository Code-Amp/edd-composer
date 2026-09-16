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
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );

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
