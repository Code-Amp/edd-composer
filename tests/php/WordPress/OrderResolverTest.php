<?php
/**
 * Tests for EDD deliverable order resolution.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Licensing\Order_Resolver;

/**
 * Verifies newest-order selection and exact product/price matching.
 *
 * @since 0.1.0
 */
final class OrderResolverTest extends WP_UnitTestCase {
	/**
	 * Confirms the newest deliverable matching item is selected.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_resolves_newest_deliverable_item() {
		$license  = (object) array(
			'ID'          => 1,
			'parent'      => 0,
			'payment_id'  => 10,
			'payment_ids' => array( 10, 20 ),
			'price_id'    => 7,
		);
		$orders   = array(
			10 => $this->order(
				10,
				'2026-01-01 10:00:00',
				array( $this->item( 500, 1 ) )
			),
			20 => $this->order(
				20,
				'2026-02-01 10:00:00',
				array( $this->item( 500, 7 ) )
			),
		);
		$resolver = new Order_Resolver( static fn( $order_id ) => $orders[ $order_id ] ?? false );

		$result = $resolver->resolve( $license, 500 );

		$this->assertSame( 20, $result['order']->id );
		$this->assertSame( 7, $result['price_id'] );
	}

	/**
	 * Confirms another variation of the same product cannot satisfy a license.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_requires_the_license_price_for_variable_products() {
		$license  = (object) array(
			'ID'          => 1,
			'parent'      => 0,
			'payment_ids' => array( 10, 20 ),
			'price_id'    => 7,
		);
		$orders   = array(
			10 => $this->order(
				10,
				'2026-01-01 10:00:00',
				array( $this->item( 500, 7 ) )
			),
			20 => $this->order(
				20,
				'2026-02-01 10:00:00',
				array( $this->item( 500, 9 ) )
			),
		);
		$resolver = new Order_Resolver( static fn( $order_id ) => $orders[ $order_id ] ?? false );

		$result = $resolver->resolve( $license, 500 );

		$this->assertSame( 10, $result['order']->id );
		$this->assertSame( 7, $result['price_id'] );
	}

	/**
	 * Confirms a non-deliverable newer item falls back to a valid older order.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_skips_non_deliverable_order_items() {
		$license  = (object) array(
			'ID'          => 1,
			'parent'      => 0,
			'payment_ids' => array( 10, 20 ),
		);
		$orders   = array(
			10 => $this->order(
				10,
				'2026-01-01 10:00:00',
				array( $this->item( 500, false ) )
			),
			20 => $this->order(
				20,
				'2026-02-01 10:00:00',
				array( $this->item( 500, false, false ) )
			),
		);
		$resolver = new Order_Resolver( static fn( $order_id ) => $orders[ $order_id ] ?? false );

		$result = $resolver->resolve( $license, 500 );

		$this->assertSame( 10, $result['order']->id );
		$this->assertFalse( $result['price_id'] );
	}

	/**
	 * Confirms bundle children include parent renewal order history.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_resolves_expanded_bundle_item_from_parent_payment() {
		$child    = (object) array(
			'ID'          => 2,
			'parent'      => 1,
			'payment_ids' => array( 100 ),
		);
		$parent   = (object) array(
			'ID'          => 1,
			'parent'      => 0,
			'payment_ids' => array( 100, 200 ),
		);
		$order    = $this->order(
			200,
			'2026-03-01 10:00:00',
			array( $this->item( 500, 12 ) )
		);
		$resolver = new Order_Resolver(
			static fn( $order_id ) => 200 === $order_id ? $order : false,
			static fn( $license_id ) => 1 === $license_id ? $parent : false
		);

		$result = $resolver->resolve( $child, 500 );

		$this->assertSame( 200, $result['order']->id );
		$this->assertSame( 12, $result['price_id'] );
		$this->assertSame( array( 200, 100 ), $resolver->get_payment_ids( $child ) );
	}

	/**
	 * Confirms an order must have a valid customer email and target item.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_missing_deliverable_order_context() {
		$license  = (object) array(
			'ID'          => 1,
			'parent'      => 0,
			'payment_ids' => array( 10 ),
		);
		$order    = $this->order(
			10,
			'2026-01-01 10:00:00',
			array( $this->item( 999, false ) ),
			''
		);
		$resolver = new Order_Resolver( static fn() => $order );

		$result = $resolver->resolve( $license, 500 );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_order_not_found', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * Creates a minimal expanded order item.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $product_id Product ID.
	 * @param int|false $price_id   Price ID.
	 * @param bool      $deliverable Whether the item can be delivered.
	 * @return object
	 */
	private function item( $product_id, $price_id, $deliverable = true ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$item = new class( $product_id, $price_id, $deliverable ) {
			public $product_id;
			public $price_id;
			private $deliverable;

			public function __construct( $product_id, $price_id, $deliverable ) {
				$this->product_id  = $product_id;
				$this->price_id    = $price_id;
				$this->deliverable = $deliverable;
			}

			public function is_deliverable() {
				return $this->deliverable;
			}
		};
		// phpcs:enable

		return $item;
	}

	/**
	 * Creates a minimal EDD order exposing expanded bundle items.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $id    Order ID.
	 * @param string             $date  Creation date.
	 * @param array<int, object> $items Expanded order items.
	 * @param string             $email Order email.
	 * @return object
	 */
	private function order( $id, $date, array $items, $email = 'customer@example.org' ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$order = new class( $id, $date, $items, $email ) {
			public $id;
			public $email;
			public $date_created;
			private $items;

			public function __construct( $id, $date, array $items, $email ) {
				$this->id           = $id;
				$this->date_created = $date;
				$this->items        = $items;
				$this->email        = $email;
			}

			public function get_items_with_bundles() {
				return $this->items;
			}
		};
		// phpcs:enable

		return $order;
	}
}
