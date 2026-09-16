<?php
/**
 * EDD order-context resolution for signed package downloads.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the newest deliverable direct or expanded bundle order item.
 *
 * @since 1.0.0
 */
final class Order_Resolver {
	/**
	 * EDD order lookup callback.
	 *
	 * @since 1.0.0
	 * @var callable
	 */
	private $order_loader;

	/**
	 * EDD SL license lookup callback.
	 *
	 * @since 1.0.0
	 * @var callable
	 */
	private $license_loader;

	/**
	 * Creates the order resolver.
	 *
	 * @since 1.0.0
	 *
	 * @param callable|null $order_loader   Callback receiving an EDD order ID.
	 * @param callable|null $license_loader Callback receiving an EDD SL license ID.
	 */
	public function __construct( $order_loader = null, $license_loader = null ) {
		$this->order_loader   = is_callable( $order_loader )
			? $order_loader
			: static function ( $order_id ) {
				return edd_get_order( $order_id );
			};
		$this->license_loader = is_callable( $license_loader )
			? $license_loader
			: static function ( $license_id ) {
				return edd_software_licensing()->get_license( $license_id );
			};
	}

	/**
	 * Resolves the signed-download order and exact target product item.
	 *
	 * Software Licensing exposes original, renewal, and upgrade purchases through
	 * the license's public magic `payment_ids` property. Parent payment history is
	 * also considered because bundle renewals are recorded on the parent license.
	 * EDD's `get_items_with_bundles()` then supplies the supported expanded item
	 * with the target product and price IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param object $license     Target EDD Software Licensing license.
	 * @param int    $download_id Requested EDD Download ID.
	 * @return array{order: object, order_item: object, price_id: int|false}|\WP_Error
	 */
	public function resolve( $license, $download_id ) {
		$orders        = array();
		$order_loader  = $this->order_loader;
		$license_price = $license->price_id ?? false;

		foreach ( $this->get_payment_ids( $license ) as $payment_id ) {
			$order = $order_loader( $payment_id );

			if ( is_object( $order ) ) {
				$orders[] = $order;
			}
		}

		usort(
			$orders,
			function ( $first, $second ) {
				$timestamp_comparison = $this->get_order_timestamp( $second ) <=> $this->get_order_timestamp( $first );

				return 0 !== $timestamp_comparison
					? $timestamp_comparison
					: absint( $second->id ?? 0 ) <=> absint( $first->id ?? 0 );
			}
		);

		foreach ( $orders as $order ) {
			$email = isset( $order->email ) ? (string) $order->email : '';

			if ( ! is_email( $email ) || ! is_callable( array( $order, 'get_items_with_bundles' ) ) ) {
				continue;
			}

			$items = $order->get_items_with_bundles();

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}

				$item_price = $item->price_id ?? false;

				if (
					absint( $item->product_id ?? 0 ) !== absint( $download_id )
					|| ( is_numeric( $license_price ) && ( ! is_numeric( $item_price ) || absint( $item_price ) !== absint( $license_price ) ) )
					|| ! is_callable( array( $item, 'is_deliverable' ) )
					|| ! $item->is_deliverable()
				) {
					continue;
				}

				return array(
					'order'      => $order,
					'order_item' => $item,
					'price_id'   => is_numeric( $item_price ) ? absint( $item_price ) : false,
				);
			}
		}

		return new \WP_Error(
			'edd_composer_order_not_found',
			__( 'A deliverable order could not be resolved for this package license.', 'edd-composer' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Gets unique payment IDs from a target license and its parent chain.
	 *
	 * @since 1.0.0
	 *
	 * @param object $license Target license.
	 * @return array<int, int>
	 */
	public function get_payment_ids( $license ) {
		$payment_ids   = array();
		$seen_licenses = array();
		$current       = $license;
		$depth         = 0;

		while ( is_object( $current ) && $depth < 20 ) {
			$license_id = $this->get_license_id( $current );

			if ( $license_id && isset( $seen_licenses[ $license_id ] ) ) {
				break;
			}

			if ( $license_id ) {
				$seen_licenses[ $license_id ] = true;
			}

			$ids = $current->payment_ids ?? array();

			if ( ! is_array( $ids ) ) {
				$ids = array();
			}

			if ( isset( $current->payment_id ) ) {
				$ids[] = $current->payment_id;
			}

			foreach ( $ids as $payment_id ) {
				$payment_id = absint( $payment_id );

				if ( $payment_id ) {
					$payment_ids[ $payment_id ] = $payment_id;
				}
			}

			$parent_id = absint( $current->parent ?? 0 );

			if ( ! $parent_id ) {
				break;
			}

			$license_loader = $this->license_loader;
			$current        = $license_loader( $parent_id );
			++$depth;
		}

		rsort( $payment_ids, SORT_NUMERIC );

		return array_values( $payment_ids );
	}

	/**
	 * Gets a sortable order creation timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order EDD order.
	 * @return int
	 */
	private function get_order_timestamp( $order ) {
		$date = $order->date_created ?? '';

		if ( $date instanceof \DateTimeInterface ) {
			return $date->getTimestamp();
		}

		$timestamp = is_string( $date ) ? strtotime( $date ) : false;

		return false !== $timestamp ? $timestamp : absint( $order->id ?? 0 );
	}

	/**
	 * Gets a license object's stable ID.
	 *
	 * @since 1.0.0
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
}
