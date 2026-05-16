<?php
/**
 * Plugin Name: Taxonomy Term Migrator for WooCommerce
 * Plugin URI: https://web-creative.studio/
 * Description: Безопасный перенос терминов и связей товаров между таксономиями WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: WebCreative Studio
 * Author URI: https://web-creative.studio/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxonomy-term-migrator
 * Domain Path: /languages
 * Update URI: https://web-creative.studio/wcs-plugins-update/taxonomy-term-migrator/metadata.json
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'TTM_VERSION', '1.0.0' );
define( 'TTM_PLUGIN_FILE', __FILE__ );
define( 'TTM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TTM_UPDATE_URL', 'https://web-creative.studio/wcs-plugins-update/taxonomy-term-migrator/metadata.json' );
define( 'TTM_OPTION_STATE', 'ttm_migration_state' );
define( 'TTM_OPTION_SETTINGS', 'ttm_settings' );
define( 'TTM_BATCH_DEFAULT', 100 );

require_once TTM_PLUGIN_DIR . 'includes/class-ttm-updater.php';
require_once TTM_PLUGIN_DIR . 'includes/class-logger.php';
require_once TTM_PLUGIN_DIR . 'includes/class-migrator.php';
require_once TTM_PLUGIN_DIR . 'includes/class-attribute-cleaner.php';
require_once TTM_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap plugin.
 */
function ttm_init() {
	$updater = new TTM_Updater( TTM_PLUGIN_FILE, TTM_VERSION, TTM_UPDATE_URL );
	$updater->init();

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
