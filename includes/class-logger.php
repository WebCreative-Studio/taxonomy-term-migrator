<?php
/**
 * Migration file logger.
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TTM_Logger
 */
class TTM_Logger {

	/** @var string|null */
	private $log_file;

	/** @var bool */
	private $enabled;

	/**
	 * @param bool $enabled Whether logging is enabled.
	 */
	public function __construct( $enabled = true ) {
		$this->enabled = (bool) $enabled;
	}

	/**
	 * Create log file and write header.
	 *
	 * @param string $source_taxonomy Source taxonomy slug.
	 * @param string $target_taxonomy Target taxonomy slug.
	 * @return string|false Log file path or false.
	 */
	public function start( $source_taxonomy, $target_taxonomy ) {
		if ( ! $this->enabled ) {
			return false;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'taxonomy-term-migrator/logs';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$filename = 'migration-' . gmdate( 'Y-m-d-H-i-s' ) . '.log';
		$this->log_file = $dir . '/' . $filename;

		$header = sprintf(
			"=== Taxonomy Term Migrator ===\nStarted: %s\nSource: %s\nTarget: %s\n\n",
			gmdate( 'Y-m-d H:i:s' ),
			$source_taxonomy,
			$target_taxonomy
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $header, FILE_APPEND | LOCK_EX );

		$this->protect_log_dir( dirname( $this->log_file ) );

		return $this->log_file;
	}

	/**
	 * @param string $message Log line.
	 */
	public function log( $message ) {
		if ( ! $this->enabled || empty( $this->log_file ) ) {
			return;
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Write footer and return path.
	 *
	 * @param array $summary Summary stats.
	 * @return string|false
	 */
	public function finish( array $summary ) {
		if ( ! $this->enabled || empty( $this->log_file ) ) {
			return false;
		}

		$footer = sprintf(
			"\n=== Completed ===\nFinished: %s\nCreated terms: %d\nUsed existing terms: %d\nLinked products: %d\nRemoved old relations: %d\nErrors: %d\n",
			gmdate( 'Y-m-d H:i:s' ),
			(int) ( $summary['created_terms'] ?? 0 ),
			(int) ( $summary['used_existing_terms'] ?? 0 ),
			(int) ( $summary['linked_products'] ?? 0 ),
			(int) ( $summary['removed_relations'] ?? 0 ),
			count( $summary['errors'] ?? array() )
		);

		if ( ! empty( $summary['errors'] ) ) {
			$footer .= "\nError details:\n";
			foreach ( $summary['errors'] as $error ) {
				$footer .= ' - ' . $error . "\n";
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $footer, FILE_APPEND | LOCK_EX );

		return $this->log_file;
	}

	/**
	 * @return string|null
	 */
	public function get_log_file() {
		return $this->log_file;
	}

	/**
	 * Get public URL/path for display.
	 *
	 * @return string
	 */
	public function get_display_path() {
		if ( empty( $this->log_file ) ) {
			return '';
		}

		$upload = wp_upload_dir();
		if ( false !== strpos( $this->log_file, $upload['basedir'] ) ) {
			return str_replace( $upload['basedir'], '/wp-content/uploads', $this->log_file );
		}

		return $this->log_file;
	}

	/**
	 * Add index.php and .htaccess to log directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function protect_log_dir( $dir ) {
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}
}
