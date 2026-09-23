<?php
/**
 * HTTP Basic authentication for protected Composer downloads.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Parses Composer credentials and validates the supplied EDD SL license.
 *
 * @since 0.1.0
 */
final class Authenticator {
	/**
	 * License lookup callback.
	 *
	 * @since 0.1.0
	 * @var callable
	 */
	private $license_loader;

	/**
	 * EDD Software Licensing site-normalization callback.
	 *
	 * @since 0.1.0
	 * @var callable
	 */
	private $site_cleaner;

	/**
	 * Creates the authenticator.
	 *
	 * Optional callbacks keep the credential policy independently testable while
	 * production defaults use the supported Software Licensing APIs.
	 *
	 * @since 0.1.0
	 *
	 * @param callable|null $license_loader Callback receiving a license key.
	 * @param callable|null $site_cleaner   Callback receiving a site URL.
	 */
	public function __construct( $license_loader = null, $site_cleaner = null ) {
		$this->license_loader = is_callable( $license_loader )
			? $license_loader
			: static function ( $license_key ) {
				return edd_software_licensing()->get_license( $license_key, true );
			};
		$this->site_cleaner   = is_callable( $site_cleaner )
			? $site_cleaner
			: static function ( $site_url ) {
				return edd_software_licensing()->clean_site_url( $site_url );
			};
	}

	/**
	 * Authenticates an HTTP request.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $server Server variables, or null for the current request.
	 * @return array{license: object, site_url: string}|\WP_Error
	 */
	public function authenticate( $server = null ) {
		$credentials = $this->get_credentials( is_array( $server ) ? $server : $_SERVER );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$license_loader = $this->license_loader;
		$license        = $license_loader( $credentials['license_key'] );

		if ( ! is_object( $license ) || ! $this->get_license_id( $license ) ) {
			return $this->error(
				'edd_composer_invalid_license',
				__( 'The supplied license credentials are invalid.', 'edd-composer' ),
				401
			);
		}

		if ( ! $this->has_download_status( $license ) ) {
			return $this->error(
				'edd_composer_license_unavailable',
				__( 'The supplied license is not eligible for downloads.', 'edd-composer' ),
				403
			);
		}

		return array(
			'license'  => $license,
			'site_url' => $credentials['site_url'],
		);
	}

	/**
	 * Extracts strict HTTP Basic credentials from server variables.
	 *
	 * The license key is the username and the activated site URL is the password.
	 * Bearer and nonstandard authentication headers are unsupported in v1.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $server Server variables.
	 * @return array{license_key: string, site_url: string}|\WP_Error
	 */
	public function get_credentials( array $server ) {
		$license_key = '';
		$site_url    = '';

		if ( isset( $server['PHP_AUTH_USER'] ) && is_scalar( $server['PHP_AUTH_USER'] ) ) {
			$license_key = (string) $server['PHP_AUTH_USER'];
			$site_url    = isset( $server['PHP_AUTH_PW'] ) && is_scalar( $server['PHP_AUTH_PW'] )
				? (string) $server['PHP_AUTH_PW']
				: '';
		} else {
			$header = $this->get_authorization_header( $server );

			if ( '' === $header || 1 !== preg_match( '/^Basic[ \t]+([^ \t]+)$/i', $header, $matches ) ) {
				return $this->missing_credentials_error();
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required by the HTTP Basic specification.
			$decoded = base64_decode( $matches[1], true );

			if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
				return $this->missing_credentials_error();
			}

			list( $license_key, $site_url ) = explode( ':', $decoded, 2 );
		}

		$license_key = trim( wp_unslash( $license_key ) );
		$site_url    = trim( wp_unslash( $site_url ) );

		if ( '' === $license_key || strlen( $license_key ) > 255 || $this->has_control_characters( $license_key ) ) {
			return $this->missing_credentials_error();
		}

		$site_url = $this->normalize_site_url( $site_url );

		if ( is_wp_error( $site_url ) ) {
			return $site_url;
		}

		return array(
			'license_key' => sanitize_text_field( $license_key ),
			'site_url'    => $site_url,
		);
	}

	/**
	 * Checks whether a license status may proceed to site-activation checks.
	 *
	 * @since 0.1.0
	 *
	 * @param object $license EDD Software Licensing license.
	 * @return bool
	 */
	public function has_download_status( $license ) {
		$status = isset( $license->status ) ? (string) $license->status : '';

		return in_array( $status, array( 'active', 'inactive' ), true );
	}

	/**
	 * Reads a standard Authorization header without mutating its encoded value.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $server Server variables.
	 * @return string
	 */
	private function get_authorization_header( array $server ) {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $server[ $key ] ) && is_scalar( $server[ $key ] ) ) {
				return trim( wp_unslash( (string) $server[ $key ] ) );
			}
		}

		return '';
	}

	/**
	 * Validates and normalizes the required activated site URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $site_url Supplied site URL.
	 * @return string|\WP_Error
	 */
	private function normalize_site_url( $site_url ) {
		if ( '' === $site_url || strlen( $site_url ) > 2048 || $this->has_control_characters( $site_url ) ) {
			return $this->invalid_site_error();
		}

		$validation_url = str_contains( $site_url, '://' ) ? $site_url : 'https://' . $site_url;
		$parts          = wp_parse_url( $validation_url );

		if (
			! is_array( $parts )
			|| empty( $parts['host'] )
			|| empty( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return $this->invalid_site_error();
		}

		$site_cleaner = $this->site_cleaner;
		$cleaned      = trim( (string) $site_cleaner( $site_url ) );

		return '' !== $cleaned ? $cleaned : $this->invalid_site_error();
	}

	/**
	 * Gets a license object's stable ID across legacy and current properties.
	 *
	 * @since 0.1.0
	 *
	 * @param object $license License object.
	 * @return int
	 */
	private function get_license_id( $license ) {
		if ( isset( $license->ID ) ) {
			return absint( $license->ID );
		}

		return isset( $license->id ) ? absint( $license->id ) : 0;
	}

	/**
	 * Detects unsafe control characters in credentials.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Credential value.
	 * @return bool
	 */
	private function has_control_characters( $value ) {
		return 1 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/**
	 * Creates the generic missing-credentials response.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Error
	 */
	private function missing_credentials_error() {
		return $this->error(
			'edd_composer_missing_credentials',
			__( 'HTTP Basic credentials are required for package downloads.', 'edd-composer' ),
			401
		);
	}

	/**
	 * Creates the required-site error.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Error
	 */
	private function invalid_site_error() {
		return $this->error(
			'edd_composer_invalid_site_url',
			__( 'A valid activated site URL is required as the HTTP Basic password.', 'edd-composer' ),
			401
		);
	}

	/**
	 * Creates an HTTP-aware authentication error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
