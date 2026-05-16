<?php
/**
 * Plugin Name: Taxonomy Term Migrator for WooCommerce
 * Description: Безопасный перенос терминов и связей товаров между таксономиями WooCommerce.
 * Version: 1.0.0
 * Author: Taxonomy Term Migrator
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: taxonomy-term-migrator
 * Domain Path: /languages
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'TTM_VERSION', '1.0.0' );
define( 'TTM_PLUGIN_FILE', __FILE__ );
define( 'TTM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TTM_OPTION_STATE', 'ttm_migration_state' );
define( 'TTM_OPTION_SETTINGS', 'ttm_settings' );
define( 'TTM_BATCH_DEFAULT', 100 );

require_once TTM_PLUGIN_DIR . 'includes/class-logger.php';
require_once TTM_PLUGIN_DIR . 'includes/class-migrator.php';
require_once TTM_PLUGIN_DIR . 'includes/class-attribute-cleaner.php';
require_once TTM_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap plugin.
 */
function ttm_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Taxonomy Term Migrator требует активный WooCommerce.', 'taxonomy-term-migrator' );
				echo '</p></div>';
			}
		);
		return;
	}

	TTM_Admin_Page::instance();
}
add_action( 'plugins_loaded', 'ttm_init' );
