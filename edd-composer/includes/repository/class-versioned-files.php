<?php
/**
 * EDD download-file version diagnostics.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes versioned EDD download files.
 *
 * @since 1.0.0
 */
final class Versioned_Files {
	/**
	 * Strict SemVer 2.0.0 pattern with one optional lowercase v prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VERSION_PATTERN = '/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';

	/**
	 * Returns the canonical Composer version, or null when invalid.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $version Candidate version.
	 * @return string|null
	 */
	public function canonicalize( $version ) {
		if ( ! is_string( $version ) ) {
			return null;
		}

		$version = trim( $version );

		if ( 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			return null;
		}

		return 0 === strpos( $version, 'v' ) ? substr( $version, 1 ) : $version;
	}

	/**
	 * Analyzes the Composer-published files attached to a Download.
	 *
	 * @since 1.0.0
	 *
	 * @param int $download_id EDD Download ID.
	 * @return array{valid: bool, versions: array<int, string>, count: int, messages: array<int, string>}
	 */
	public function analyze( $download_id ) {
		$files = function_exists( 'edd_get_download_files' )
			? edd_get_download_files( absint( $download_id ) )
			: false;
		$files = is_array( $files ) ? $files : array();

		$versions        = array();
		$messages        = array();
		$variable_prices = function_exists( 'edd_has_variable_prices' )
			&& edd_has_variable_prices( absint( $download_id ) );

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['version'] ) ) {
				continue;
			}

			$original  = trim( (string) $file['version'] );
			$canonical = $this->canonicalize( $original );

			if ( null === $canonical ) {
				$messages[] = sprintf(
					/* translators: %s: Invalid file version. */
					__( 'File version “%s” is not valid SemVer.', 'edd-composer' ),
					$original
				);
				continue;
			}

			if ( isset( $versions[ $canonical ] ) ) {
				$messages[] = sprintf(
					/* translators: %s: Duplicate canonical file version. */
					__( 'File version “%s” is duplicated.', 'edd-composer' ),
					$canonical
				);
				continue;
			}

			if ( $variable_prices && ( ! isset( $file['condition'] ) || 'all' !== (string) $file['condition'] ) ) {
				$messages[] = sprintf(
					/* translators: %s: Version assigned to only one price variation. */
					__( 'File version “%s” must be assigned to all price variations.', 'edd-composer' ),
					$canonical
				);
				continue;
			}

			$versions[ $canonical ] = true;
		}

		if ( empty( $versions ) ) {
			$messages[] = __( 'Add at least one valid versioned download file.', 'edd-composer' );
		}

		$versions = array_keys( $versions );
		usort(
			$versions,
			static function ( $first, $second ) {
				return version_compare( $second, $first );
			}
		);

		return array(
			'valid'    => empty( $messages ),
			'versions' => $versions,
			'count'    => count( $versions ),
			'messages' => array_values( array_unique( $messages ) ),
		);
	}
}
