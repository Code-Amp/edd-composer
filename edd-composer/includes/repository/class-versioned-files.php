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
		$inspection = $this->inspect( $download_id );
		$versions   = array_keys( $inspection['files'] );

		usort(
			$versions,
			static function ( $first, $second ) {
				return version_compare( $second, $first );
			}
		);

		return array(
			'valid'    => empty( $inspection['messages'] ),
			'versions' => $versions,
			'count'    => count( $versions ),
			'messages' => array_values( array_unique( $inspection['messages'] ) ),
		);
	}

	/**
	 * Resolves one exact canonical version to its protected EDD file row.
	 *
	 * Invalid products, duplicate versions, and price-specific files are never
	 * returned, keeping download resolution consistent with package discovery.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $download_id Download ID.
	 * @param string $version     Canonical Composer version.
	 * @return array{filekey: int|string, file: array<string, mixed>}|null
	 */
	public function find( $download_id, $version ) {
		$canonical = $this->canonicalize( $version );

		if ( null === $canonical ) {
			return null;
		}

		$inspection = $this->inspect( $download_id );

		if ( ! empty( $inspection['messages'] ) || ! isset( $inspection['files'][ $canonical ] ) ) {
			return null;
		}

		return $inspection['files'][ $canonical ];
	}

	/**
	 * Inspects raw EDD file rows once for both publication and download lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param int $download_id Download ID.
	 * @return array{files: array<string, array{filekey: int|string, file: array<string, mixed>}>, messages: array<int, string>}
	 */
	private function inspect( $download_id ) {
		$files = function_exists( 'edd_get_download_files' )
			? edd_get_download_files( absint( $download_id ) )
			: false;
		$files = is_array( $files ) ? $files : array();

		$versioned_files = array();
		$seen_versions   = array();
		$messages        = array();
		$variable_prices = function_exists( 'edd_has_variable_prices' )
			&& edd_has_variable_prices( absint( $download_id ) );

		foreach ( $files as $filekey => $file ) {
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

			if ( isset( $seen_versions[ $canonical ] ) ) {
				$messages[] = sprintf(
					/* translators: %s: Duplicate canonical file version. */
					__( 'File version “%s” is duplicated.', 'edd-composer' ),
					$canonical
				);
				continue;
			}

			$seen_versions[ $canonical ] = true;

			if ( empty( $file['file'] ) ) {
				$messages[] = sprintf(
					/* translators: %s: Canonical file version. */
					__( 'File version “%s” does not have a download file.', 'edd-composer' ),
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

			$versioned_files[ $canonical ] = array(
				'filekey' => $filekey,
				'file'    => $file,
			);
		}

		if ( empty( $versioned_files ) ) {
			$messages[] = __( 'Add at least one valid versioned download file.', 'edd-composer' );
		}

		return array(
			'files'    => $versioned_files,
			'messages' => $messages,
		);
	}
}
