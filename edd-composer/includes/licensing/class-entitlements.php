<?php
/**
 * EDD Software Licensing product-entitlement resolution.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves direct, parent, child, and sibling bundle license access.
 *
 * @since 0.1.0
 */
final class Entitlements {
	/**
	 * License lookup callback.
	 *
	 * @since 0.1.0
	 * @var callable
	 */
	private $license_loader;

	/**
	 * Creates the entitlement resolver.
	 *
	 * @since 0.1.0
	 *
	 * @param callable|null $license_loader Callback receiving a license ID.
	 */
	public function __construct( $license_loader = null ) {
		$this->license_loader = is_callable( $license_loader )
			? $license_loader
			: static function ( $license_id ) {
				return edd_software_licensing()->get_license( $license_id );
			};
	}

	/**
	 * Resolves and validates the license that grants a target Download.
	 *
	 * @since 0.1.0
	 *
	 * @param object $credential_license Authenticated license.
	 * @param int    $download_id        Requested EDD Download ID.
	 * @param string $site_url           EDD-normalized activated site URL.
	 * @return object|\WP_Error Target license on success.
	 */
	public function resolve( $credential_license, $download_id, $site_url ) {
		$matching_licenses = array();

		foreach ( $this->get_accessible_licenses( $credential_license ) as $license ) {
			if ( absint( $license->download_id ?? 0 ) === absint( $download_id ) ) {
				$matching_licenses[] = $license;
			}
		}

		if ( empty( $matching_licenses ) ) {
			return $this->error(
				'edd_composer_product_not_entitled',
				__( 'The supplied license does not include this package.', 'edd-composer' )
			);
		}

		$has_download_status = false;

		foreach ( $matching_licenses as $target_license ) {
			$status = isset( $target_license->status ) ? (string) $target_license->status : '';

			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				continue;
			}

			$has_download_status = true;

			if (
				'' !== $site_url
				&& is_callable( array( $target_license, 'is_site_active' ) )
				&& $target_license->is_site_active( $site_url )
			) {
				return $target_license;
			}
		}

		if ( ! $has_download_status ) {
			return $this->error(
				'edd_composer_target_license_unavailable',
				__( 'The license for this package is not eligible for downloads.', 'edd-composer' )
			);
		}

		return $this->error(
			'edd_composer_site_not_activated',
			__( 'The package license is not activated for the supplied site.', 'edd-composer' )
		);
	}

	/**
	 * Returns licenses reachable through the credential's bundle family.
	 *
	 * @since 0.1.0
	 *
	 * @param object $credential_license Authenticated license.
	 * @return array<int, object>
	 */
	public function get_accessible_licenses( $credential_license ) {
		$licenses  = array( $credential_license );
		$parent_id = absint( $credential_license->parent ?? 0 );

		if ( $parent_id ) {
			$license_loader = $this->license_loader;
			$parent         = $license_loader( $parent_id );

			if ( is_object( $parent ) && $this->get_license_id( $parent ) ) {
				$licenses[] = $parent;
				$licenses   = array_merge( $licenses, $this->get_children( $parent ) );
			}
		} else {
			$licenses = array_merge( $licenses, $this->get_children( $credential_license ) );
		}

		$unique = array();

		foreach ( $licenses as $license ) {
			if ( ! is_object( $license ) ) {
				continue;
			}

			$license_id = $this->get_license_id( $license );

			if ( $license_id ) {
				$unique[ $license_id ] = $license;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Gets a parent license's child licenses defensively.
	 *
	 * @since 0.1.0
	 *
	 * @param object $license License object.
	 * @return array<int, object>
	 */
	private function get_children( $license ) {
		if ( ! is_callable( array( $license, 'get_child_licenses' ) ) ) {
			return array();
		}

		$children = $license->get_child_licenses();

		return is_array( $children ) ? array_values( array_filter( $children, 'is_object' ) ) : array();
	}

	/**
	 * Gets a license object's stable ID.
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
	 * Creates a forbidden entitlement error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		return new \WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
