<?php
/**
 * Tests for protected-download HTTP Basic authentication.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Licensing\Authenticator;

/**
 * Verifies credential parsing, normalization, and license status checks.
 *
 * @since 1.0.0
 */
final class AuthenticatorTest extends WP_UnitTestCase {
	/**
	 * Confirms standard PHP Basic variables are accepted and normalized.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_authenticates_php_basic_credentials() {
		$license = (object) array(
			'ID'     => 42,
			'status' => 'active',
		);
		$auth    = new Authenticator(
			static function ( $key ) use ( $license ) {
				return 'license-key' === $key ? $license : false;
			},
			static function ( $url ) {
				return strtolower( preg_replace( '#^https?://#', '', $url ) );
			}
		);

		$result = $auth->authenticate(
			array(
				'PHP_AUTH_USER' => 'license-key',
				'PHP_AUTH_PW'   => 'https://EXAMPLE.org/site/',
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $license, $result['license'] );
		$this->assertSame( 'example.org/site/', $result['site_url'] );
	}

	/**
	 * Confirms the fallback header preserves colons inside the site URL.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_parses_strict_authorization_header() {
		$auth        = new Authenticator( static fn() => false, static fn( $url ) => $url );
		$credentials = $auth->get_credentials(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic test fixture.
				'REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( 'abc123:http://localhost:8889/path' ),
			)
		);

		$this->assertSame( 'abc123', $credentials['license_key'] );
		$this->assertSame( 'http://localhost:8889/path', $credentials['site_url'] );
	}

	/**
	 * Confirms Bearer, malformed Base64, and missing passwords are rejected.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_unsupported_or_incomplete_credentials() {
		$auth = new Authenticator();

		$bearer    = $auth->get_credentials( array( 'HTTP_AUTHORIZATION' => 'Bearer secret' ) );
		$malformed = $auth->get_credentials( array( 'HTTP_AUTHORIZATION' => 'Basic !!!' ) );
		$no_site   = $auth->get_credentials( array( 'PHP_AUTH_USER' => 'license-key' ) );

		$this->assertSame( 401, $this->get_status( $bearer ) );
		$this->assertSame( 401, $this->get_status( $malformed ) );
		$this->assertSame( 401, $this->get_status( $no_site ) );
	}

	/**
	 * Confirms unsupported URL schemes and embedded credentials are rejected.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_unsafe_site_urls() {
		$auth = new Authenticator();

		$scheme = $auth->get_credentials(
			array(
				'PHP_AUTH_USER' => 'license-key',
				'PHP_AUTH_PW'   => 'javascript:alert(1)',
			)
		);
		$user   = $auth->get_credentials(
			array(
				'PHP_AUTH_USER' => 'license-key',
				'PHP_AUTH_PW'   => 'https://user:password@example.org',
			)
		);

		$this->assertSame( 401, $this->get_status( $scheme ) );
		$this->assertSame( 401, $this->get_status( $user ) );
	}

	/**
	 * Confirms unknown keys and blocked statuses receive distinct HTTP outcomes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_validates_primary_license_status() {
		$unknown = new Authenticator( static fn() => false, static fn( $url ) => $url );
		$expired = new Authenticator(
			static fn() => (object) array(
				'ID'     => 99,
				'status' => 'expired',
			),
			static fn( $url ) => $url
		);
		$server  = array(
			'PHP_AUTH_USER' => 'license-key',
			'PHP_AUTH_PW'   => 'https://example.org',
		);

		$this->assertSame( 401, $this->get_status( $unknown->authenticate( $server ) ) );
		$this->assertSame( 403, $this->get_status( $expired->authenticate( $server ) ) );
	}

	/**
	 * Gets an HTTP status from a WordPress error.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $error Candidate WordPress error.
	 * @return int
	 */
	private function get_status( $error ) {
		$this->assertWPError( $error );
		$data = $error->get_error_data();

		return isset( $data['status'] ) ? absint( $data['status'] ) : 0;
	}
}
