<?php
/**
 * Tests for public repository and signed-download URL policy.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Repository\URL_Policy;

/**
 * Verifies HTTPS enforcement, origin allowlisting, and proxy rewriting.
 *
 * @since 0.1.0
 */
final class UrlPolicyTest extends WP_UnitTestCase {
	/**
	 * Restores URL filters after each test.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'edd_composer_repository_base_url' );
		remove_all_filters( 'edd_composer_download_proxy_origin' );
		remove_all_filters( 'edd_composer_allowed_repository_origins' );
		parent::tear_down();
	}

	/**
	 * Confirms HTTP is rejected unless the explicit development override is used.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_requires_https_without_development_override() {
		$policy = new URL_Policy( false );

		$this->assertFalse( $policy->is_ready() );
		$this->assertSame( 'edd_composer_insecure_repository_url', $policy->get_configuration_error()->get_error_code() );
		$this->assertTrue( ( new URL_Policy( true ) )->is_ready() );
	}

	/**
	 * Confirms a filtered external repository remains blocked without allowlisting.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_external_repository_origin_without_allowlist() {
		add_filter( 'edd_composer_repository_base_url', static fn() => 'https://repository.example.com/private' );
		$policy = new URL_Policy( false );

		$this->assertFalse( $policy->is_ready() );
		$this->assertSame( 'edd_composer_repository_origin_not_allowed', $policy->get_configuration_error()->get_error_code() );
		$this->assertSame( home_url( '/composer' ), $policy->get_repository_base_url() );
	}

	/**
	 * Confirms an explicit HTTPS proxy can advertise a different public path.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_accepts_allowlisted_external_repository_and_proxy() {
		$this->configure_proxy_filters();
		$policy = new URL_Policy( false );

		$this->assertTrue( $policy->is_ready() );
		$this->assertSame( 'https://repository.example.com/private/packages', $policy->get_repository_base_url() );
		$this->assertSame( 'https://downloads.example.com', $policy->get_download_proxy_origin() );
	}

	/**
	 * Confirms rewriting changes only the signed URL origin.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rewrites_store_signed_url_without_changing_path_or_query() {
		$this->configure_proxy_filters();
		$policy = new URL_Policy( false );
		$signed = home_url( '/index.php?eddfile=7%3A12%3A0&ttl=99&token=a%2Bb%3D' );

		$this->assertSame(
			'https://downloads.example.com/index.php?eddfile=7%3A12%3A0&ttl=99&token=a%2Bb%3D',
			$policy->prepare_signed_download_url( $signed )
		);
		$this->assertContains( 'downloads.example.com', $policy->allow_download_proxy_host( array( 'existing.example.com' ) ) );
	}

	/**
	 * Confirms a signer cannot introduce an arbitrary source URL before rewriting.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_external_signed_url_even_with_proxy_configured() {
		$this->configure_proxy_filters();
		$result = ( new URL_Policy( false ) )->prepare_signed_download_url( 'https://attacker.example.net/file.zip?token=secret' );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_download_url_failed', $result->get_error_code() );
	}

	/**
	 * Registers a complete public proxy configuration.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function configure_proxy_filters() {
		add_filter( 'edd_composer_repository_base_url', static fn() => 'https://repository.example.com/private/packages' );
		add_filter( 'edd_composer_download_proxy_origin', static fn() => 'https://downloads.example.com' );
		add_filter(
			'edd_composer_allowed_repository_origins',
			static function ( $origins ) {
				$origins[] = 'https://repository.example.com';
				$origins[] = 'https://downloads.example.com';
				return $origins;
			}
		);
	}
}
