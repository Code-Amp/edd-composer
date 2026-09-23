<?php
/**
 * EDD product catalogue.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

use EDD_Composer\Repository\Versioned_Files;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers EDD Downloads and enriches them for the admin application.
 *
 * @since 0.1.0
 */
final class Products {
	/**
	 * Versioned-file diagnostics.
	 *
	 * @since 0.1.0
	 * @var Versioned_Files
	 */
	private $versioned_files;

	/**
	 * Creates the product catalogue service.
	 *
	 * @since 0.1.0
	 *
	 * @param Versioned_Files $versioned_files Versioned-file diagnostics.
	 */
	public function __construct( Versioned_Files $versioned_files ) {
		$this->versioned_files = $versioned_files;
	}

	/**
	 * Gets every editable EDD Download with Composer configuration data.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Current plugin settings.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_catalogue( array $settings ) {
		$posts     = get_posts(
			array(
				'post_type'              => 'download',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$catalogue = array();

		foreach ( $posts as $post ) {
			$catalogue[] = $this->prepare_product( $post, $settings );
		}

		return $catalogue;
	}

	/**
	 * Gets one product's default and saved configuration.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post             $post     Download post.
	 * @param array<string, mixed> $settings Current plugin settings.
	 * @return array<string, mixed>
	 */
	private function prepare_product( \WP_Post $post, array $settings ) {
		$product_id  = (string) $post->ID;
		$description = '' !== trim( (string) $post->post_excerpt )
			? wp_strip_all_tags( $post->post_excerpt )
			: get_the_title( $post );
		$defaults    = array(
			'enabled'      => false,
			'package_slug' => '' !== $post->post_name ? $post->post_name : sanitize_title( get_the_title( $post ) ),
			'type'         => 'wordpress-plugin',
			'description'  => $description,
			'require_php'  => '>=7.4',
		);
		$saved       = isset( $settings['products'][ $product_id ] ) && is_array( $settings['products'][ $product_id ] )
			? $settings['products'][ $product_id ]
			: array();
		$config      = array_merge( $defaults, $saved );
		$files       = $this->versioned_files->analyze( $post->ID );
		$messages    = $files['messages'];

		if ( 'publish' !== $post->post_status ) {
			$messages[] = __( 'The Download must be published.', 'edd-composer' );
		}

		$licensed_product = class_exists( '\EDD\SoftwareLicensing\Downloads\LicensedProduct' )
			? new \EDD\SoftwareLicensing\Downloads\LicensedProduct( $post->ID )
			: null;

		if ( ! $licensed_product || ! $licensed_product->licensing_enabled() ) {
			$messages[] = __( 'Enable Software Licensing for this Download.', 'edd-composer' );
		}

		return array(
			'id'                       => $post->ID,
			'title'                    => get_the_title( $post ),
			'status'                   => $post->post_status,
			'status_label'             => $this->get_status_label( $post->post_status ),
			'edit_url'                 => get_edit_post_link( $post->ID, 'raw' ),
			'default_package_slug'     => $defaults['package_slug'],
			'default_description'      => $defaults['description'],
			'enabled'                  => (bool) $config['enabled'],
			'package_slug'             => (string) $config['package_slug'],
			'package_name'             => $settings['vendor'] . '/' . $config['package_slug'],
			'type'                     => (string) $config['type'],
			'description'              => (string) $config['description'],
			'require_php'              => (string) $config['require_php'],
			'versioned_file_count'     => $files['count'],
			'versions'                 => $files['versions'],
			'file_validation_messages' => array_values( array_unique( $messages ) ),
			'can_enable'               => empty( $messages ),
		);
	}

	/**
	 * Gets a human-readable post status label.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Post status key.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$object = get_post_status_object( $status );

		return $object && isset( $object->label ) ? $object->label : $status;
	}
}
