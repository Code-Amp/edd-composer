<?php
/**
 * Protected Composer package-download preparation.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

use EDD_Composer\Licensing\Authenticator;
use EDD_Composer\Licensing\Entitlements;
use EDD_Composer\Licensing\Order_Resolver;
use EDD_Composer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Authorizes a package request and produces a safe EDD-signed redirect URL.
 *
 * @since 1.0.0
 */
final class Download {
	/**
	 * Settings service.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Versioned-file service.
	 *
	 * @since 1.0.0
	 * @var Versioned_Files
	 */
	private $versioned_files;

	/**
	 * Request authenticator.
	 *
	 * @since 1.0.0
	 * @var Authenticator
	 */
	private $authenticator;

	/**
	 * Product-entitlement resolver.
	 *
	 * @since 1.0.0
	 * @var Entitlements
	 */
	private $entitlements;

	/**
	 * Deliverable order resolver.
	 *
	 * @since 1.0.0
	 * @var Order_Resolver
	 */
	private $order_resolver;

	/**
	 * EDD signed-URL generator callback.
	 *
	 * @since 1.0.0
	 * @var callable
	 */
	private $url_signer;

	/**
	 * Public and signed-download URL policy.
	 *
	 * @since 1.0.0
	 * @var URL_Policy
	 */
	private $url_policy;

	/**
	 * Creates the protected-download service.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings        $settings        Settings service.
	 * @param Versioned_Files $versioned_files Versioned-file service.
	 * @param Authenticator   $authenticator   Request authenticator.
	 * @param Entitlements    $entitlements    Entitlement resolver.
	 * @param Order_Resolver  $order_resolver  Order resolver.
	 * @param callable|null   $url_signer      Optional signed-URL callback.
	 * @param URL_Policy|null $url_policy      Optional URL policy.
	 */
	public function __construct(
		Settings $settings,
		Versioned_Files $versioned_files,
		Authenticator $authenticator,
		Entitlements $entitlements,
		Order_Resolver $order_resolver,
		$url_signer = null,
		?URL_Policy $url_policy = null
	) {
		$this->settings        = $settings;
		$this->versioned_files = $versioned_files;
		$this->authenticator   = $authenticator;
		$this->entitlements    = $entitlements;
		$this->order_resolver  = $order_resolver;
		$this->url_signer      = is_callable( $url_signer )
			? $url_signer
			: static function ( $order_item, $email, $filekey, $download_id, $price_id ) {
				return edd_get_download_file_url( $order_item, $email, $filekey, $download_id, $price_id );
			};
		$this->url_policy      = $url_policy ? $url_policy : new URL_Policy();
	}

	/**
	 * Prepares one authorized package redirect without emitting a response.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $product_slug Requested configured package slug.
	 * @param string                    $version      Requested Composer version.
	 * @param array<string, mixed>|null $server       Optional request server variables.
	 * @return array{redirect_url: string, download_id: int, product_slug: string, version: string, license_id: int, order_id: int}|\WP_Error
	 */
	public function prepare( $product_slug, $version, $server = null ) {
		$authentication = $this->authenticator->authenticate( $server );

		if ( is_wp_error( $authentication ) ) {
			return $authentication;
		}

		$product_slug = is_string( $product_slug ) ? trim( $product_slug ) : '';

		if ( ! $this->settings->is_valid_package_slug( $product_slug ) ) {
			return $this->error(
				'edd_composer_invalid_product_slug',
				__( 'The requested package slug is invalid.', 'edd-composer' ),
				400
			);
		}

		$canonical_version = $this->versioned_files->canonicalize( $version );

		if ( null === $canonical_version ) {
			return $this->error(
				'edd_composer_invalid_version',
				__( 'The requested package version is invalid.', 'edd-composer' ),
				400
			);
		}

		$download_id = $this->resolve_download_id( $product_slug );

		if ( is_wp_error( $download_id ) ) {
			return $download_id;
		}

		$file = $this->versioned_files->find( $download_id, $canonical_version );

		if ( null === $file ) {
			return $this->error(
				'edd_composer_version_not_found',
				__( 'The requested package version is not available.', 'edd-composer' ),
				404
			);
		}

		$target_license = $this->entitlements->resolve(
			$authentication['license'],
			$download_id,
			$authentication['site_url']
		);

		if ( is_wp_error( $target_license ) ) {
			return $target_license;
		}

		$order_context = $this->order_resolver->resolve( $target_license, $download_id );

		if ( is_wp_error( $order_context ) ) {
			return $order_context;
		}

		$url_signer   = $this->url_signer;
		$redirect_url = $url_signer(
			$order_context['order_item'],
			(string) $order_context['order']->email,
			$file['filekey'],
			$download_id,
			$order_context['price_id']
		);

		$redirect_url = $this->url_policy->prepare_signed_download_url( $redirect_url );

		if ( is_wp_error( $redirect_url ) ) {
			return $redirect_url;
		}

		return array(
			'redirect_url' => $redirect_url,
			'download_id'  => $download_id,
			'product_slug' => $product_slug,
			'version'      => $canonical_version,
			'license_id'   => $this->get_object_id( $target_license ),
			'order_id'     => $this->get_object_id( $order_context['order'] ),
		);
	}

	/**
	 * Confirms a signed URL points to the configured WordPress origin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Candidate signed URL.
	 * @return bool
	 */
	public function is_same_origin_url( $url ) {
		return $this->url_policy->is_store_origin_url( $url );
	}

	/**
	 * Resolves an enabled, published Download from its configured package slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $product_slug Package slug.
	 * @return int|\WP_Error
	 */
	private function resolve_download_id( $product_slug ) {
		$settings = $this->settings->validate( $this->settings->get() );

		if ( is_wp_error( $settings ) ) {
			return $this->error(
				'edd_composer_invalid_repository_settings',
				__( 'The package repository settings are invalid.', 'edd-composer' ),
				500
			);
		}

		foreach ( $settings['products'] as $download_id => $product ) {
			if ( empty( $product['enabled'] ) || $product_slug !== $product['package_slug'] ) {
				continue;
			}

			$download_id = absint( $download_id );

			$licensed_product = class_exists( '\EDD\SoftwareLicensing\Downloads\LicensedProduct' )
				? new \EDD\SoftwareLicensing\Downloads\LicensedProduct( $download_id )
				: null;

			if (
				'download' === get_post_type( $download_id )
				&& 'publish' === get_post_status( $download_id )
				&& $licensed_product
				&& $licensed_product->licensing_enabled()
			) {
				return $download_id;
			}
		}

		return $this->error(
			'edd_composer_product_not_found',
			__( 'The requested package is not available.', 'edd-composer' ),
			404
		);
	}

	/**
	 * Gets a stable ID from an EDD row object.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row EDD row object.
	 * @return int
	 */
	private function get_object_id( $row ) {
		if ( isset( $row->ID ) ) {
			return absint( $row->ID );
		}

		return isset( $row->id ) ? absint( $row->id ) : 0;
	}

	/**
	 * Creates an HTTP-aware repository error.
	 *
	 * @since 1.0.0
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
