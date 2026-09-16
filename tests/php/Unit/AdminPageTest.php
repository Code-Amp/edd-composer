<?php
/**
 * Tests for the requirements-only administration experience.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Admin\Admin_Page;
use EDD_Composer\Dependencies;

/**
 * Verifies administration access decisions.
 *
 * @since 1.0.0
 */
final class AdminPageTest extends WP_UnitTestCase {
	/**
	 * Admin page instances that registered hooks during a test.
	 *
	 * @since 1.0.0
	 * @var array<int, Admin_Page>
	 */
	private $hooked_pages = array();

	/**
	 * Restores hooks and registered assets changed by each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->hooked_pages as $page ) {
			remove_action( 'admin_menu', array( $page, 'register_menu' ) );
			remove_action( 'admin_notices', array( $page, 'render_dependency_notice' ) );
			remove_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
		}

		wp_dequeue_script( Admin_Page::SCRIPT_HANDLE );
		wp_deregister_script( Admin_Page::SCRIPT_HANDLE );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Confirms EDD stores use its settings-management capability.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_edd_capability_is_used_when_edd_is_present() {
		$page = new Admin_Page( $this->dependencies( '3.7.0' ) );

		$this->assertSame( 'manage_shop_settings', $page->get_management_capability() );
	}

	/**
	 * Confirms the requirements fallback remains accessible without EDD.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_wordpress_capability_is_used_when_edd_is_missing() {
		$page = new Admin_Page( $this->dependencies( null ) );

		$this->assertSame( 'manage_options', $page->get_management_capability() );
	}

	/**
	 * Confirms stores can customize the management capability.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_edd_capability_can_be_filtered() {
		$page = new Admin_Page( $this->dependencies( '3.7.0' ) );

		add_filter( 'edd_composer_management_capability', array( $this, 'filter_management_capability' ) );

		$this->assertSame( 'edit_shop_payments', $page->get_management_capability() );

		remove_filter( 'edd_composer_management_capability', array( $this, 'filter_management_capability' ) );
	}

	/**
	 * Confirms the capability filter does not change the no-EDD fallback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_capability_filter_is_not_applied_when_edd_is_missing() {
		$page = new Admin_Page( $this->dependencies( null ) );

		add_filter( 'edd_composer_management_capability', array( $this, 'filter_management_capability' ) );

		$this->assertSame( 'manage_options', $page->get_management_capability() );

		remove_filter( 'edd_composer_management_capability', array( $this, 'filter_management_capability' ) );
	}

	/**
	 * Confirms the full dependency gate registers assets but no warning notice.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_ready_dependencies_register_menu_and_asset_hooks() {
		$page = $this->register_page_hooks( $this->ready_dependencies() );

		$this->assertSame( 10, has_action( 'admin_menu', array( $page, 'register_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $page, 'render_dependency_notice' ) ) );
	}

	/**
	 * Confirms failed dependencies register the notice but never asset loading.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_failed_dependencies_register_menu_and_notice_hooks() {
		$page = $this->register_page_hooks( $this->dependencies( '3.7.0' ) );

		$this->assertSame( 10, has_action( 'admin_menu', array( $page, 'register_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $page, 'render_dependency_notice' ) ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) ) );
	}

	/**
	 * Confirms the page is placed below Downloads when EDD is installed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_menu_is_registered_below_downloads_when_edd_is_present() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( '3.6.9' ) );

		$page->register_menu();

		$this->assertSubmenuRegistered( 'edit.php?post_type=download', 'manage_shop_settings' );
	}

	/**
	 * Confirms the requirements page falls back below Settings without EDD.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_menu_is_registered_below_settings_when_edd_is_missing() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( null ) );

		$page->register_menu();

		$this->assertSubmenuRegistered( 'options-general.php', 'manage_options' );
	}

	/**
	 * Confirms ready stores receive only the React application mount point.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_ready_page_renders_application_mount_point() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->ready_dependencies() );

		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<div id="edd-composer-admin"></div>', $output );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertStringNotContainsString( 'Action required', $output );
	}

	/**
	 * Confirms failed stores receive statuses and no application mount point.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_failed_page_renders_requirements_only() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( '3.7.0' ) );

		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table class="widefat striped">', $output );
		$this->assertStringContainsString( 'EDD Software Licensing', $output );
		$this->assertStringContainsString( 'Not detected', $output );
		$this->assertStringContainsString( 'Action required', $output );
		$this->assertStringNotContainsString( 'id="edd-composer-admin"', $output );
	}

	/**
	 * Confirms unauthorized users cannot render the management screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_page_rejects_unauthorized_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$page = new Admin_Page( $this->ready_dependencies() );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to manage EDD Composer Extension.' );

		$page->render_page();
	}

	/**
	 * Confirms authorized managers see a notice linked to the Downloads page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_dependency_notice_links_to_downloads_when_edd_is_present() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( '3.6.9' ) );

		ob_start();
		$page->render_dependency_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( 'edit.php?post_type=download&#038;page=edd-composer', $output );
		$this->assertStringContainsString( 'Review requirements', $output );
	}

	/**
	 * Confirms authorized administrators see the Settings fallback notice link.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_dependency_notice_links_to_settings_when_edd_is_missing() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( null ) );

		ob_start();
		$page->render_dependency_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options-general.php?page=edd-composer', $output );
	}

	/**
	 * Confirms unauthorized users never receive the dependency notice.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_dependency_notice_is_suppressed_for_unauthorized_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$page = new Admin_Page( $this->dependencies( '3.6.9' ) );

		ob_start();
		$page->render_dependency_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Confirms generated asset metadata is used on the plugin screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_assets_are_enqueued_on_plugin_screen() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->ready_dependencies() );
		$page->register_menu();
		$hook = get_plugin_page_hookname( Admin_Page::PAGE_SLUG, 'edit.php?post_type=download' );

		$page->enqueue_assets( $hook );

		$asset  = require EDD_COMPOSER_PATH . 'assets/index.asset.php';
		$script = wp_scripts()->registered[ Admin_Page::SCRIPT_HANDLE ];

		$this->assertTrue( wp_script_is( Admin_Page::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertSame( EDD_COMPOSER_URL . 'assets/index.js', $script->src );
		foreach ( $asset['dependencies'] as $dependency ) {
			$this->assertContains( $dependency, $script->deps );
		}
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( $asset['version'], $script->ver );
	}

	/**
	 * Confirms the application bundle is absent from unrelated admin screens.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_assets_are_not_enqueued_on_other_admin_screens() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->ready_dependencies() );
		$page->register_menu();

		$page->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_script_is( Admin_Page::SCRIPT_HANDLE, 'registered' ) );
		$this->assertFalse( wp_script_is( Admin_Page::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Confirms a failed gate cannot enqueue the application when called directly.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_assets_are_not_enqueued_when_dependencies_fail() {
		$this->set_manager_user();
		$page = new Admin_Page( $this->dependencies( '3.7.0' ) );
		$page->register_menu();
		$hook = get_plugin_page_hookname( Admin_Page::PAGE_SLUG, 'edit.php?post_type=download' );

		$page->enqueue_assets( $hook );

		$this->assertFalse( wp_script_is( Admin_Page::SCRIPT_HANDLE, 'registered' ) );
	}

	/**
	 * Supplies a custom management capability.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function filter_management_capability() {
		return 'edit_shop_payments';
	}

	/**
	 * Registers a page instance's WordPress hooks for an assertion.
	 *
	 * @since 1.0.0
	 *
	 * @param Dependencies $dependencies Runtime dependency evaluator.
	 * @return Admin_Page
	 */
	private function register_page_hooks( Dependencies $dependencies ) {
		$page                 = new Admin_Page( $dependencies );
		$this->hooked_pages[] = $page;
		$page->register_hooks();

		return $page;
	}

	/**
	 * Creates a user who can manage both menu variants.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function set_manager_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_shop_settings' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Confirms the expected submenu entry exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $parent_slug Parent menu slug.
	 * @param string $capability  Expected management capability.
	 * @return void
	 */
	private function assertSubmenuRegistered( $parent_slug, $capability ) {
		global $submenu;

		$this->assertArrayHasKey( $parent_slug, $submenu );

		foreach ( $submenu[ $parent_slug ] as $item ) {
			if ( Admin_Page::PAGE_SLUG === $item[2] ) {
				$this->assertSame( 'Composer', $item[0] );
				$this->assertSame( $capability, $item[1] );
				return;
			}
		}

		$this->fail( 'The EDD Composer submenu entry was not registered.' );
	}

	/**
	 * Creates a fully-supported dependency set.
	 *
	 * @since 1.0.0
	 * @return Dependencies
	 */
	private function ready_dependencies() {
		return new Dependencies(
			array(
				'wordpress'          => '6.9',
				'php'                => '8.0.0',
				'edd'                => '3.7.0',
				'software_licensing' => '3.9.7',
			)
		);
	}

	/**
	 * Creates an otherwise-supported dependency set.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $edd_version Detected EDD version.
	 * @return Dependencies
	 */
	private function dependencies( $edd_version ) {
		return new Dependencies(
			array(
				'wordpress'          => '6.9',
				'php'                => '8.0.0',
				'edd'                => $edd_version,
				'software_licensing' => null,
			)
		);
	}
}
