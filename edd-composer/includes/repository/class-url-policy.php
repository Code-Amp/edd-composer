<?php
/**
 * Public repository and protected-download URL policy.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates public URLs and narrowly supports explicitly trusted proxy origins.
 *
 * @since 0.1.0
 */
final class URL_Policy {
	/**
	 * Test override for the local HTTP allowance.
	 *
	 * @since 0.1.0
	 * @var bool|null
	 */
	private $allow_insecure_http;

	/**
	 * Creates the URL policy.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $allow_insecure_http Optional test override.
	 */
	public function __construct( $allow_insecure_http = null ) {
		$this->allow_insecure_http = is_bool( $allow_insecure_http ) ? $allow_insecure_http : null;
	}

	/**
	 * Gets the validated public repository base URL.
	 *
	 * Invalid filtered values fall back to the safe default. The runtime gate
	 * remains closed until the invalid configuration is corrected.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_repository_base_url() {
		$default   = home_url( '/composer' );
		$candidate = $this->get_filtered_repository_base_url( $default );

		if ( is_wp_error( $this->validate_repository_base_url( $candidate ) ) ) {
			return untrailingslashit( $default );
		}

		return untrailingslashit( esc_url_raw( $candidate ) );
	}

	/**
	 * Gets the configured download proxy origin, when valid.
	 *
	 * @since 0.1.0
	 *
	 * @return string Empty when direct signed redirects are configured.
	 */
	public function get_download_proxy_origin() {
		$candidate = $this->get_filtered_download_proxy_origin();

		if ( '' === $candidate || is_wp_error( $this->validate_download_proxy_origin( $candidate ) ) ) {
			return '';
		}

		return untrailingslashit( esc_url_raw( $candidate ) );
	}

	/**
	 * Reports whether public URL configuration is safe to expose.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_ready() {
		return ! is_wp_error( $this->get_configuration_error() );
	}

	/**
	 * Returns the first actionable public URL configuration error.
	 *
	 * @since 0.1.0
	 *
	 * @return true|\WP_Error
	 */
	public function get_configuration_error() {
		$base_url = $this->get_filtered_repository_base_url( home_url( '/composer' ) );
		$result   = $this->validate_repository_base_url( $base_url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$proxy_origin = $this->get_filtered_download_proxy_origin();

		if ( '' !== $proxy_origin ) {
			return $this->validate_download_proxy_origin( $proxy_origin );
		}

		if ( ! $this->allows_insecure_http() && 'https' !== strtolower( (string) wp_parse_url( site_url(), PHP_URL_SCHEME ) ) ) {
			return new \WP_Error(
				'edd_composer_insecure_download_origin',
				__( 'The WordPress site URL must use HTTPS for protected downloads, or an HTTPS download proxy origin must be configured.', 'edd-composer' )
			);
		}

		return true;
	}

	/**
	 * Gets a requirements-table row for the URL policy.
	 *
	 * @since 0.1.0
	 *
	 * @return array{label: string, minimum: string, current: string, met: bool}
	 */
	public function get_requirement() {
		$result = $this->get_configuration_error();

		return array(
			'label'   => __( 'Public repository transport', 'edd-composer' ),
			'minimum' => __( 'HTTPS and an allowed origin', 'edd-composer' ),
			'current' => is_wp_error( $result ) ? $result->get_error_message() : $this->get_repository_base_url(),
			'met'     => ! is_wp_error( $result ),
		);
	}

	/**
	 * Validates and optionally rewrites an EDD-signed URL to a trusted proxy.
	 *
	 * Only the scheme, host, and port are replaced. The EDD handler path and
	 * signature query string remain byte-for-byte unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url EDD-signed URL.
	 * @return string|\WP_Error
	 */
	public function prepare_signed_download_url( $url ) {
		if ( ! is_string( $url ) || ! $this->is_store_origin_url( $url ) ) {
			return $this->download_url_error();
		}

		$proxy_origin = $this->get_download_proxy_origin();

		if ( '' === $proxy_origin ) {
			if ( ! $this->allows_insecure_http() && 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
				return $this->download_url_error();
			}

			return $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $this->download_url_error();
		}

		$path      = isset( $parts['path'] ) ? $parts['path'] : '';
		$query     = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$rewritten = $proxy_origin . $path . $query;

		return esc_url_raw( $rewritten );
	}

	/**
	 * Confirms a URL belongs to the configured WordPress home or site origin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public function is_store_origin_url( $url ) {
		$candidate = $this->get_origin( $url );

		if ( null === $candidate ) {
			return false;
		}

		foreach ( array_unique( array( home_url(), site_url() ) ) as $allowed_url ) {
			if ( $candidate === $this->get_origin( $allowed_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Temporarily permits the configured proxy host for wp_safe_redirect().
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $hosts Existing allowed redirect hosts.
	 * @return array<int, string>
	 */
	public function allow_download_proxy_host( array $hosts ) {
		$proxy_origin = $this->get_download_proxy_origin();
		$host         = '' !== $proxy_origin ? wp_parse_url( $proxy_origin, PHP_URL_HOST ) : '';

		if ( is_string( $host ) && '' !== $host ) {
			$hosts[] = strtolower( $host );
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Reads the repository URL filter without normalizing an invalid value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $fallback Safe fallback URL.
	 * @return mixed
	 */
	private function get_filtered_repository_base_url( $fallback ) {
		/**
		 * Filters the public Composer repository base URL.
		 *
		 * External origins must also be added through the
		 * `edd_composer_allowed_repository_origins` filter.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Default repository URL.
		 */
		return apply_filters( 'edd_composer_repository_base_url', $fallback );
	}

	/**
	 * Reads the optional download proxy filter.
	 *
	 * @since 0.1.0
	 *
	 * @return mixed
	 */
	private function get_filtered_download_proxy_origin() {
		/**
		 * Filters the public proxy origin used for final EDD-signed redirects.
		 *
		 * Return an origin only, such as `https://downloads.example.com`. The
		 * origin must also be explicitly allowlisted.
		 *
		 * @since 0.1.0
		 *
		 * @param string $origin Empty for direct EDD-signed redirects.
		 */
		return apply_filters( 'edd_composer_download_proxy_origin', '' );
	}

	/**
	 * Validates the public repository URL.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $url Candidate URL.
	 * @return true|\WP_Error
	 */
	private function validate_repository_base_url( $url ) {
		if ( ! $this->is_valid_http_url( $url, false ) ) {
			return new \WP_Error(
				'edd_composer_invalid_repository_url',
				__( 'The public Composer repository URL is invalid.', 'edd-composer' )
			);
		}

		if ( ! $this->is_allowed_origin( $url ) ) {
			return new \WP_Error(
				'edd_composer_repository_origin_not_allowed',
				__( 'The public Composer repository origin has not been explicitly allowed.', 'edd-composer' )
			);
		}

		if ( ! $this->allows_insecure_http() && 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new \WP_Error(
				'edd_composer_insecure_repository_url',
				__( 'The public Composer repository URL must use HTTPS.', 'edd-composer' )
			);
		}

		return true;
	}

	/**
	 * Validates the optional signed-download proxy origin.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $origin Candidate origin.
	 * @return true|\WP_Error
	 */
	private function validate_download_proxy_origin( $origin ) {
		if ( ! $this->is_valid_http_url( $origin, true ) ) {
			return new \WP_Error(
				'edd_composer_invalid_download_proxy_origin',
				__( 'The download proxy must be a valid origin without a path, query string, or fragment.', 'edd-composer' )
			);
		}

		if ( ! $this->is_allowed_origin( $origin ) ) {
			return new \WP_Error(
				'edd_composer_download_proxy_origin_not_allowed',
				__( 'The download proxy origin has not been explicitly allowed.', 'edd-composer' )
			);
		}

		if ( ! $this->allows_insecure_http() && 'https' !== strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) ) ) {
			return new \WP_Error(
				'edd_composer_insecure_download_proxy_origin',
				__( 'The public download proxy origin must use HTTPS.', 'edd-composer' )
			);
		}

		return true;
	}

	/**
	 * Checks a URL's syntax and optional origin-only constraint.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $url         Candidate URL.
	 * @param bool  $origin_only Whether the path must be empty or `/`.
	 * @return bool
	 */
	private function is_valid_http_url( $url, $origin_only ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;

		if (
			! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
		) {
			return false;
		}

		return ! $origin_only || empty( $parts['path'] ) || '/' === $parts['path'];
	}

	/**
	 * Checks an origin against the explicit public repository allowlist.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_allowed_origin( $url ) {
		$allowed = array( home_url(), site_url() );

		/**
		 * Filters origins trusted for generated repository and redirect URLs.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string> $origins WordPress home and site origins.
		 */
		$allowed = apply_filters( 'edd_composer_allowed_repository_origins', $allowed );
		$target  = $this->get_origin( $url );

		if ( ! is_array( $allowed ) || null === $target ) {
			return false;
		}

		foreach ( $allowed as $allowed_url ) {
			if ( $target === $this->get_origin( $allowed_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a URL to a scheme, host, and effective port tuple.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $url URL to inspect.
	 * @return string|null
	 */
	private function get_origin( $url ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;

		if (
			! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 );

		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}

	/**
	 * Reports whether an explicit local-development HTTP allowance is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function allows_insecure_http() {
		if ( null !== $this->allow_insecure_http ) {
			return $this->allow_insecure_http;
		}

		return defined( 'EDD_COMPOSER_ALLOW_INSECURE_HTTP' )
			&& true === EDD_COMPOSER_ALLOW_INSECURE_HTTP
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Creates the generic signed-download failure used at the public boundary.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Error
	 */
	private function download_url_error() {
		return new \WP_Error(
			'edd_composer_download_url_failed',
			__( 'A secure package download URL could not be generated.', 'edd-composer' ),
			array( 'status' => 500 )
		);
	}
}
