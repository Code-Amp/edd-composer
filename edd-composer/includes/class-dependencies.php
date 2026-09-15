<?php
/**
 * Runtime dependency checks.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the shared minimum-version gate.
 *
 * @since 1.0.0
 */
final class Dependencies {
	/**
	 * Minimum supported WordPress version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MINIMUM_WORDPRESS_VERSION = '6.9';

	/**
	 * Minimum supported PHP version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MINIMUM_PHP_VERSION = '8.0';

	/**
	 * Minimum supported Easy Digital Downloads version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MINIMUM_EDD_VERSION = '3.7.0';

	/**
	 * Minimum supported Software Licensing version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MINIMUM_SOFTWARE_LICENSING_VERSION = '3.9.7';

	/**
	 * Detected versions, keyed by requirement identifier.
	 *
	 * A null value represents a missing dependency.
	 *
	 * @since 1.0.0
	 * @var array<string, string|null>
	 */
	private $versions;

	/**
	 * Creates a dependency evaluator from explicit versions.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string|null> $versions Detected dependency versions.
	 */
	public function __construct( array $versions ) {
		$this->versions = array_merge(
			array(
				'wordpress'          => null,
				'php'                => null,
				'edd'                => null,
				'software_licensing' => null,
			),
			$versions
		);
	}

	/**
	 * Creates an evaluator using the current WordPress runtime.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function from_environment() {
		global $wp_version;

		return new self(
			array(
				'wordpress'          => is_string( $wp_version ) ? $wp_version : null,
				'php'                => PHP_VERSION,
				'edd'                => defined( 'EDD_VERSION' ) ? EDD_VERSION : null,
				'software_licensing' => self::detect_software_licensing_version(),
			)
		);
	}

	/**
	 * Gets the dependency requirements and their current status.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string|bool|null>> Requirements keyed by identifier.
	 */
	public function get_requirements() {
		$minimum_versions = $this->get_minimum_versions();
		$requirements     = array(
			'wordpress'          => array(
				'label'   => __( 'WordPress', 'edd-composer' ),
				'minimum' => $minimum_versions['wordpress'],
			),
			'php'                => array(
				'label'   => __( 'PHP', 'edd-composer' ),
				'minimum' => $minimum_versions['php'],
			),
			'edd'                => array(
				'label'   => __( 'Easy Digital Downloads', 'edd-composer' ),
				'minimum' => $minimum_versions['edd'],
			),
			'software_licensing' => array(
				'label'   => __( 'EDD Software Licensing', 'edd-composer' ),
				'minimum' => $minimum_versions['software_licensing'],
			),
		);

		foreach ( $requirements as $key => &$requirement ) {
			$current                = $this->versions[ $key ];
			$requirement['current'] = $current;
			$requirement['met']     = is_string( $current )
				&& version_compare( $current, $requirement['minimum'], '>=' );
		}
		unset( $requirement );

		return $requirements;
	}

	/**
	 * Determines whether every runtime requirement is met.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function are_met() {
		foreach ( $this->get_minimum_versions() as $key => $minimum_version ) {
			$current = $this->versions[ $key ];

			if ( ! is_string( $current ) || ! version_compare( $current, $minimum_version, '>=' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gets minimum versions without invoking translation functions.
	 *
	 * The dependency gate runs during plugins_loaded, before just-in-time text
	 * domain loading is safe. UI labels are added later by get_requirements().
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function get_minimum_versions() {
		return array(
			'wordpress'          => self::MINIMUM_WORDPRESS_VERSION,
			'php'                => self::MINIMUM_PHP_VERSION,
			'edd'                => self::MINIMUM_EDD_VERSION,
			'software_licensing' => self::MINIMUM_SOFTWARE_LICENSING_VERSION,
		);
	}

	/**
	 * Determines whether Easy Digital Downloads is present.
	 *
	 * This check is intentionally independent of its supported version so the
	 * requirements page can remain below the Downloads menu when EDD is old.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_edd() {
		return is_string( $this->versions['edd'] );
	}

	/**
	 * Detects the active EDD Software Licensing version.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	private static function detect_software_licensing_version() {
		if ( ! function_exists( 'edd_software_licensing' ) ) {
			return null;
		}

		foreach ( array( 'EDD_SL_VERSION', 'EDD_SOFTWARE_LICENSING_VERSION' ) as $constant_name ) {
			if ( defined( $constant_name ) && is_string( constant( $constant_name ) ) ) {
				return constant( $constant_name );
			}
		}

		$version = self::detect_software_licensing_plugin_header();

		/**
		 * Filters the detected EDD Software Licensing version.
		 *
		 * This is primarily a compatibility escape hatch for extension builds
		 * that expose the public API but omit the usual version metadata.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null $version Detected version, or null when unavailable.
		 */
		$version = apply_filters( 'edd_composer_software_licensing_version', $version );

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Reads the extension version from the plugin that defines its public API.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	private static function detect_software_licensing_plugin_header() {
		try {
			$reflection = new \ReflectionFunction( 'edd_software_licensing' );
			$source     = $reflection->getFileName();
		} catch ( \ReflectionException $exception ) {
			return null;
		}

		if ( ! is_string( $source ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return null;
		}

		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$source     = wp_normalize_path( $source );

		if ( 0 !== strpos( $source, trailingslashit( $plugin_dir ) ) ) {
			return null;
		}

		$relative_path = ltrim( substr( $source, strlen( $plugin_dir ) ), '/' );
		$path_parts    = explode( '/', $relative_path );
		$directory     = count( $path_parts ) > 1 ? $path_parts[0] : '.';

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = '.' === $directory ? get_plugins() : get_plugins( '/' . $directory );

		foreach ( $plugins as $plugin ) {
			$name    = isset( $plugin['Name'] ) ? $plugin['Name'] : '';
			$version = isset( $plugin['Version'] ) ? $plugin['Version'] : '';

			if ( false !== stripos( $name, 'Software Licensing' ) && is_string( $version ) && '' !== $version ) {
				return $version;
			}
		}

		return null;
	}
}
