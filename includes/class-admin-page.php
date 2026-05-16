<?php
/**
 * Admin UI and AJAX handlers.
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TTM_Admin_Page
 */
class TTM_Admin_Page {

	/** @var self|null */
	private static $instance = null;

	/** @var TTM_Migrator */
	private $migrator;

	/** @var TTM_Attribute_Cleaner */
	private $attribute_cleaner;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->migrator           = new TTM_Migrator();
		$this->attribute_cleaner  = new TTM_Attribute_Cleaner();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TTM_PLUGIN_FILE ), array( $this, 'add_plugin_action_links' ) );

		add_action( 'wp_ajax_ttm_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_ttm_start_migration', array( $this, 'ajax_start_migration' ) );
		add_action( 'wp_ajax_ttm_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_ttm_stop_migration', array( $this, 'ajax_stop_migration' ) );
		add_action( 'wp_ajax_ttm_get_state', array( $this, 'ajax_get_state' ) );
		add_action( 'wp_ajax_ttm_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_ttm_delete_empty_attribute_terms', array( $this, 'ajax_delete_empty_attribute_terms' ) );
		add_action( 'wp_ajax_ttm_delete_empty_attributes', array( $this, 'ajax_delete_empty_attributes' ) );
		add_action( 'wp_ajax_ttm_cleanup_product_attributes', array( $this, 'ajax_cleanup_product_attributes' ) );
		add_action( 'wp_ajax_ttm_run_full_cleanup', array( $this, 'ajax_run_full_cleanup' ) );
		add_action( 'wp_ajax_ttm_cleanup_products_batch', array( $this, 'ajax_cleanup_products_batch' ) );
	}

	/**
	 * Default form settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'source_taxonomy'           => '',
			'target_taxonomy'           => '',
			'batch_size'                => TTM_BATCH_DEFAULT,
			'create_missing_terms'      => true,
			'transfer_relations'        => true,
			'remove_old_relations'      => false,
			'delete_empty_source_terms' => false,
			'confirm_delete_terms'      => false,
			'cleanup_wc_attributes_after' => false,
			'preserve_slug'             => true,
			'enable_logging'            => true,
		);
	}

	/**
	 * Load saved settings from the database.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( TTM_OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::get_default_settings() );
		$settings['batch_size'] = TTM_Migrator::sanitize_batch_size( $settings['batch_size'] );

		return $settings;
	}

	/**
	 * Persist form settings.
	 *
	 * @param array $input Raw input.
	 * @return array|WP_Error
	 */
	public static function save_settings( array $input ) {
		$source = isset( $input['source_taxonomy'] ) ? sanitize_text_field( $input['source_taxonomy'] ) : '';
		$target = isset( $input['target_taxonomy'] ) ? sanitize_text_field( $input['target_taxonomy'] ) : '';

		if ( $source && $target && $source === $target ) {
			return new WP_Error( 'ttm_same', __( 'Донорская и целевая таксономии не могут совпадать.', 'taxonomy-term-migrator' ) );
		}

		if ( $source && ! TTM_Migrator::is_valid_taxonomy( $source ) ) {
			return new WP_Error( 'ttm_invalid', __( 'Некорректная донорская таксономия.', 'taxonomy-term-migrator' ) );
		}

		if ( $target && ! TTM_Migrator::is_valid_taxonomy( $target ) ) {
			return new WP_Error( 'ttm_invalid', __( 'Некорректная целевая таксономия.', 'taxonomy-term-migrator' ) );
		}

		$settings = array(
			'source_taxonomy'           => $source,
			'target_taxonomy'           => $target,
			'batch_size'                => TTM_Migrator::sanitize_batch_size( $input['batch_size'] ?? TTM_BATCH_DEFAULT ),
			'create_missing_terms'      => ! empty( $input['create_missing_terms'] ),
			'transfer_relations'        => ! empty( $input['transfer_relations'] ),
			'remove_old_relations'      => ! empty( $input['remove_old_relations'] ),
			'delete_empty_source_terms' => ! empty( $input['delete_empty_source_terms'] ),
			'confirm_delete_terms'      => ! empty( $input['confirm_delete_terms'] ),
			'cleanup_wc_attributes_after' => ! empty( $input['cleanup_wc_attributes_after'] ),
			'preserve_slug'             => ! isset( $input['preserve_slug'] ) || ! empty( $input['preserve_slug'] ),
			'enable_logging'            => ! isset( $input['enable_logging'] ) || ! empty( $input['enable_logging'] ),
		);

		update_option( TTM_OPTION_SETTINGS, $settings, false );

		return $settings;
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	public static function get_settings_url() {
		return admin_url( 'admin.php?page=taxonomy-term-migrator' );
	}

	/**
	 * Add «Настройки» link on the Plugins list screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::get_settings_url() ),
			esc_html__( 'Настройки', 'taxonomy-term-migrator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register submenu under WooCommerce.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Перенос таксономий', 'taxonomy-term-migrator' ),
			__( 'Перенос таксономий', 'taxonomy-term-migrator' ),
			'manage_woocommerce',
			'taxonomy-term-migrator',
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_taxonomy-term-migrator' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ttm-admin',
			TTM_PLUGIN_URL . 'assets/admin.css',
			array(),
			TTM_VERSION
		);

		wp_enqueue_script(
			'ttm-admin',
			TTM_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			TTM_VERSION,
			true
		);

		wp_localize_script(
			'ttm-admin',
			'ttmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ttm_migration' ),
				'i18n'    => array(
					'selectTaxonomies'  => __( 'Выберите донорскую и целевую таксономии.', 'taxonomy-term-migrator' ),
					'sameTaxonomy'      => __( 'Донорская и целевая таксономии не могут совпадать.', 'taxonomy-term-migrator' ),
					'confirmDelete'     => __( 'Вы уверены, что хотите удалить пустые термины в донорской таксономии после переноса? Это действие необратимо.', 'taxonomy-term-migrator' ),
					'confirmStart'      => __( 'Запустить перенос? Убедитесь, что вы просмотрели предпросмотр.', 'taxonomy-term-migrator' ),
					'migrationRunning'  => __( 'Перенос выполняется…', 'taxonomy-term-migrator' ),
					'completed'         => __( 'Перенос завершён.', 'taxonomy-term-migrator' ),
					'stopped'           => __( 'Перенос остановлен.', 'taxonomy-term-migrator' ),
					'error'             => __( 'Ошибка', 'taxonomy-term-migrator' ),
					'processed'         => __( 'Обработано товаров', 'taxonomy-term-migrator' ),
					'createdTerms'      => __( 'Создано терминов', 'taxonomy-term-migrator' ),
					'usedExisting'      => __( 'Использовано существующих терминов', 'taxonomy-term-migrator' ),
					'linkedProducts'    => __( 'Добавлено связей товар → термин', 'taxonomy-term-migrator' ),
					'removedRelations'  => __( 'Удалено старых связей', 'taxonomy-term-migrator' ),
					'errorsCount'       => __( 'Ошибок', 'taxonomy-term-migrator' ),
					'logFile'           => __( 'Лог', 'taxonomy-term-migrator' ),
					'saved'             => __( 'Настройки сохранены.', 'taxonomy-term-migrator' ),
					'saveSettings'      => __( 'Сохранить настройки', 'taxonomy-term-migrator' ),
					'confirmDeleteEmptyTerms' => __( 'Удалить все значения атрибутов WooCommerce, не привязанные ни к одному товару? Это действие необратимо.', 'taxonomy-term-migrator' ),
					'confirmDeleteEmptyAttributes' => __( 'Удалить атрибуты WooCommerce без значений или у которых все значения пустые? Атрибуты и их таксономии будут удалены безвозвратно.', 'taxonomy-term-migrator' ),
					'cleanupTermsDone'  => __( 'Удалено пустых значений атрибутов', 'taxonomy-term-migrator' ),
					'cleanupAttributesDone' => __( 'Удалено атрибутов', 'taxonomy-term-migrator' ),
					'cleanupTermsAlso'  => __( 'Также удалено пустых значений перед удалением атрибутов', 'taxonomy-term-migrator' ),
					'migrationBlocksCleanup' => __( 'Дождитесь завершения или остановите перенос таксономий.', 'taxonomy-term-migrator' ),
					'confirmCleanupProducts' => __( 'Удалить с товаров атрибуты без значений (например, пустой Color)? Это действие необратимо.', 'taxonomy-term-migrator' ),
					'confirmFullCleanup' => __( 'Выполнить полную очистку: пустые значения, пустые глобальные атрибуты и пустые атрибуты на всех товарах?', 'taxonomy-term-migrator' ),
					'cleanupProductsRunning' => __( 'Очистка пустых атрибутов на товарах…', 'taxonomy-term-migrator' ),
					'cleanupDeletedTerms' => __( 'Удалено пустых значений атрибутов', 'taxonomy-term-migrator' ),
					'cleanupDeletedAttributes' => __( 'Удалено глобальных атрибутов', 'taxonomy-term-migrator' ),
					'cleanupCleanedProducts' => __( 'Очищено товаров от пустых атрибутов', 'taxonomy-term-migrator' ),
					'cleanupRunning'    => __( 'Выполняется…', 'taxonomy-term-migrator' ),
					'cleanupCompleted'  => __( 'Очистка успешно завершена.', 'taxonomy-term-migrator' ),
					'cleanupPleaseWait' => __( 'Подождите, идёт обработка…', 'taxonomy-term-migrator' ),
					'cleanupStepTerms'  => __( 'Шаг 1 из 3: удаление пустых значений атрибутов', 'taxonomy-term-migrator' ),
					'cleanupStepAttributes' => __( 'Шаг 2 из 3: удаление пустых глобальных атрибутов', 'taxonomy-term-migrator' ),
					'cleanupStepProducts' => __( 'Шаг 3 из 3: очистка товаров', 'taxonomy-term-migrator' ),
					'cleanupNothingFound' => __( 'Ничего не найдено для удаления.', 'taxonomy-term-migrator' ),
					'cleanupErrors'     => __( 'Ошибок', 'taxonomy-term-migrator' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'taxonomy-term-migrator' ) );
		}

		$taxonomies = TTM_Migrator::get_available_taxonomies();
		$settings   = self::get_settings();
		$state      = $this->migrator->get_state();
		$running    = $this->migrator->is_migration_running();

		include TTM_PLUGIN_DIR . 'includes/views/admin-page.php';
	}

	/**
	 * Verify AJAX request.
	 *
	 * @return bool
	 */
	private function verify_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'taxonomy-term-migrator' ) ), 403 );
		}

		check_ajax_referer( 'ttm_migration', 'nonce' );

		return true;
	}

	/**
	 * Get options from POST.
	 *
	 * @return array
	 */
	private function get_options_from_request() {
		return TTM_Migrator::parse_options(
			array(
				'create_missing_terms'      => isset( $_POST['create_missing_terms'] ),
				'transfer_relations'        => isset( $_POST['transfer_relations'] ),
				'remove_old_relations'      => isset( $_POST['remove_old_relations'] ),
				'delete_empty_source_terms' => isset( $_POST['delete_empty_source_terms'] ),
				'preserve_slug'             => isset( $_POST['preserve_slug'] ),
				'enable_logging'            => isset( $_POST['enable_logging'] ),
				'confirm_delete_terms'      => isset( $_POST['confirm_delete_terms'] ),
				'cleanup_wc_attributes_after' => isset( $_POST['cleanup_wc_attributes_after'] ),
				'batch_size'                => isset( $_POST['batch_size'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_size'] ) ) : TTM_BATCH_DEFAULT,
			)
		);
	}

	/**
	 * AJAX: preview migration.
	 */
	public function ajax_preview() {
		$this->verify_request();

		$source = isset( $_POST['source_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['source_taxonomy'] ) ) : '';
		$target = isset( $_POST['target_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['target_taxonomy'] ) ) : '';

		$result = $this->migrator->preview( $source, $target );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: start migration.
	 */
	public function ajax_start_migration() {
		$this->verify_request();

		$source  = isset( $_POST['source_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['source_taxonomy'] ) ) : '';
		$target  = isset( $_POST['target_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['target_taxonomy'] ) ) : '';
		$options = $this->get_options_from_request();

		$result = $this->migrator->start_migration( $source, $target, $options );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process batch.
	 */
	public function ajax_process_batch() {
		$this->verify_request();

		$result = $this->migrator->process_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: stop migration.
	 */
	public function ajax_stop_migration() {
		$this->verify_request();

		$result = $this->migrator->stop_migration();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: get current state.
	 */
	public function ajax_get_state() {
		$this->verify_request();

		wp_send_json_success(
			array(
				'state'   => $this->migrator->get_state(),
				'running' => $this->migrator->is_migration_running(),
			)
		);
	}

	/**
	 * AJAX: save form settings.
	 */
	public function ajax_save_settings() {
		$this->verify_request();

		$result = self::save_settings( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Настройки сохранены.', 'taxonomy-term-migrator' ),
				'settings' => $result,
			)
		);
	}

	/**
	 * Block cleanup while migration is running.
	 *
	 * @return bool
	 */
	private function verify_cleanup_allowed() {
		$this->verify_request();

		if ( $this->migrator->is_migration_running() ) {
			wp_send_json_error(
				array( 'message' => __( 'Дождитесь завершения или остановите перенос таксономий.', 'taxonomy-term-migrator' ) )
			);
		}

		return true;
	}

	/**
	 * Format cleanup result message.
	 *
	 * @param array  $result Result from cleaner.
	 * @param string $mode   cleanup mode key.
	 * @return string
	 */
	private function format_cleanup_message( array $result, $mode ) {
		$parts = array();

		if ( ! empty( $result['deleted_terms'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of terms */
				__( 'Удалено пустых значений атрибутов: %d.', 'taxonomy-term-migrator' ),
				(int) $result['deleted_terms']
			);
		}

		if ( ! empty( $result['deleted_attributes'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of attributes */
				__( 'Удалено глобальных атрибутов: %d.', 'taxonomy-term-migrator' ),
				(int) $result['deleted_attributes']
			);
		}

		if ( ! empty( $result['cleaned_products'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of products */
				__( 'Очищено товаров от пустых атрибутов: %d.', 'taxonomy-term-migrator' ),
				(int) $result['cleaned_products']
			);
		}

		if ( 'terms' === $mode && empty( $parts ) ) {
			$parts[] = __( 'Пустых значений атрибутов не найдено.', 'taxonomy-term-migrator' );
		}

		if ( 'attributes' === $mode && empty( $parts ) ) {
			$parts[] = __( 'Пустых глобальных атрибутов не найдено.', 'taxonomy-term-migrator' );
		}

		if ( 'products' === $mode && empty( $parts ) ) {
			$parts[] = __( 'Товаров с пустыми атрибутами не найдено.', 'taxonomy-term-migrator' );
		}

		if ( 'full' === $mode && empty( $parts ) ) {
			$parts[] = __( 'Ничего не найдено для удаления.', 'taxonomy-term-migrator' );
		}

		$error_count = count( $result['errors'] ?? array() );
		if ( $error_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: error count */
				__( 'Ошибок: %d.', 'taxonomy-term-migrator' ),
				$error_count
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * AJAX: delete empty attribute term values.
	 */
	public function ajax_delete_empty_attribute_terms() {
		$this->verify_cleanup_allowed();

		$result = $this->attribute_cleaner->delete_empty_terms();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => $this->format_cleanup_message( $result, 'terms' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * AJAX: delete attributes with no or only empty values.
	 */
	public function ajax_delete_empty_attributes() {
		$this->verify_cleanup_allowed();

		$result = $this->attribute_cleaner->delete_empty_attributes();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => $this->format_cleanup_message( $result, 'attributes' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * AJAX: cleanup empty attributes on all products (batched).
	 */
	public function ajax_cleanup_product_attributes() {
		$this->verify_cleanup_allowed();

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$batch  = $this->attribute_cleaner->clean_products_batch( $offset );
		$batch['done'] = empty( $batch['has_more'] );

		wp_send_json_success( array( 'batch' => $batch ) );
	}

	/**
	 * AJAX: full cleanup in one request (global + all products).
	 */
	public function ajax_run_full_cleanup() {
		$this->verify_cleanup_allowed();

		$result = $this->attribute_cleaner->run_full_cleanup();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => $this->format_cleanup_message( $result, 'full' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * AJAX: continue product cleanup after migration.
	 */
	public function ajax_cleanup_products_batch() {
		$this->verify_request();

		$result = $this->migrator->process_cleanup_products_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}
