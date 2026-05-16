<?php
/**
 * Core migration logic.
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TTM_Migrator
 */
class TTM_Migrator {

	/**
	 * Get taxonomies available for migration.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function get_available_taxonomies() {
		$taxonomies = array();

		$product_taxonomies = get_object_taxonomies( 'product', 'objects' );
		foreach ( $product_taxonomies as $taxonomy ) {
			if ( ! is_object( $taxonomy ) ) {
				continue;
			}

			$taxonomies[ $taxonomy->name ] = $taxonomy->labels->singular_name
				? $taxonomy->labels->singular_name . ' (' . $taxonomy->name . ')'
				: $taxonomy->name;
		}

		$public = get_taxonomies(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		foreach ( $public as $taxonomy ) {
			if ( ! is_object( $taxonomy ) ) {
				continue;
			}
			if ( ! in_array( 'product', (array) $taxonomy->object_type, true ) && empty( $taxonomy->object_type ) ) {
				continue;
			}
			if ( isset( $taxonomies[ $taxonomy->name ] ) ) {
				continue;
			}
			$taxonomies[ $taxonomy->name ] = $taxonomy->labels->singular_name
				? $taxonomy->labels->singular_name . ' (' . $taxonomy->name . ')'
				: $taxonomy->name;
		}

		ksort( $taxonomies );

		return $taxonomies;
	}

	/**
	 * Validate taxonomy slug exists.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function is_valid_taxonomy( $taxonomy ) {
		return taxonomy_exists( $taxonomy );
	}

	/**
	 * Parse migration options from request/array.
	 *
	 * @param array $input Raw options.
	 * @return array
	 */
	public static function parse_options( array $input ) {
		return array(
			'create_missing_terms'     => ! empty( $input['create_missing_terms'] ),
			'transfer_relations'       => ! empty( $input['transfer_relations'] ),
			'remove_old_relations'     => ! empty( $input['remove_old_relations'] ),
			'delete_empty_source_terms'=> ! empty( $input['delete_empty_source_terms'] ),
			'preserve_slug'            => ! isset( $input['preserve_slug'] ) || ! empty( $input['preserve_slug'] ),
			'enable_logging'           => ! isset( $input['enable_logging'] ) || ! empty( $input['enable_logging'] ),
			'confirm_delete_terms'     => ! empty( $input['confirm_delete_terms'] ),
			'cleanup_wc_attributes_after' => ! empty( $input['cleanup_wc_attributes_after'] ),
			'batch_size'               => self::sanitize_batch_size( $input['batch_size'] ?? TTM_BATCH_DEFAULT ),
		);
	}

	/**
	 * @param mixed $size Batch size.
	 * @return int
	 */
	public static function sanitize_batch_size( $size ) {
		$allowed = array( 50, 100, 250, 500 );
		$size    = (int) $size;
		return in_array( $size, $allowed, true ) ? $size : TTM_BATCH_DEFAULT;
	}

	/**
	 * Build preview data.
	 *
	 * @param string $source Source taxonomy.
	 * @param string $target Target taxonomy.
	 * @param array  $options Options.
	 * @return array|WP_Error
	 */
	public function preview( $source, $target, array $options = array() ) {
		$validation = $this->validate_taxonomies( $source, $target );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$terms     = get_terms(
			array(
				'taxonomy'   => $source,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$rows              = array();
		$will_create       = 0;
		$already_exists    = 0;
		$slug_conflicts    = 0;

		foreach ( $terms as $term ) {
			$match = $this->match_term( $term, $target, false );
			$status = 'will_create';
			$target_name = $term->name;

			if ( $match['term_id'] ) {
				$status = 'exists';
				$target_term = get_term( $match['term_id'], $target );
				$target_name = $target_term && ! is_wp_error( $target_term ) ? $target_term->name : $term->name;
				++$already_exists;
			} else {
				++$will_create;
			}

			if ( $match['slug_conflict'] ) {
				++$slug_conflicts;
			}

			$rows[] = array(
				'source_name' => $term->name,
				'slug'        => $term->slug,
				'target_name' => $target_name,
				'status'      => $status,
				'status_label'=> 'exists' === $status
					? __( 'уже существует', 'taxonomy-term-migrator' )
					: __( 'будет создан', 'taxonomy-term-migrator' ),
			);
		}

		$product_count = $this->count_products_with_terms( $source );

		return array(
			'source_taxonomy'       => $source,
			'target_taxonomy'       => $target,
			'term_count'            => count( $terms ),
			'product_count'         => $product_count,
			'will_create_terms'     => $will_create,
			'existing_terms'        => $already_exists,
			'slug_conflicts'        => $slug_conflicts,
			'rows'                  => $rows,
		);
	}

	/**
	 * Start migration — initialize state.
	 *
	 * @param string $source Source taxonomy.
	 * @param string $target Target taxonomy.
	 * @param array  $options Options.
	 * @return array|WP_Error
	 */
	public function start_migration( $source, $target, array $options ) {
		if ( $this->is_migration_running() ) {
			return new WP_Error( 'ttm_running', __( 'Перенос уже выполняется. Дождитесь завершения или остановите процесс.', 'taxonomy-term-migrator' ) );
		}

		$validation = $this->validate_taxonomies( $source, $target );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! empty( $options['delete_empty_source_terms'] ) && empty( $options['confirm_delete_terms'] ) ) {
			return new WP_Error(
				'ttm_confirm',
				__( 'Для удаления пустых терминов в донорской таксономии требуется подтверждение.', 'taxonomy-term-migrator' )
			);
		}

		$logger = new TTM_Logger( ! empty( $options['enable_logging'] ) );
		$log_path = $logger->start( $source, $target );

		$term_map = $this->build_term_map( $source, $target, $options, $logger );

		if ( is_wp_error( $term_map ) ) {
			return $term_map;
		}

		$product_ids = $this->get_product_ids_with_terms( $source );
		$total       = count( $product_ids );

		$state = array(
			'status'                 => 'running',
			'source_taxonomy'        => $source,
			'target_taxonomy'        => $target,
			'options'                => $options,
			'term_map'               => $term_map['map'],
			'created_terms'          => $term_map['created'],
			'used_existing_terms'    => $term_map['existing'],
			'product_ids'            => $product_ids,
			'processed'              => 0,
			'total'                  => $total,
			'linked_products'        => 0,
			'removed_relations'      => 0,
			'deleted_terms'          => 0,
			'errors'                 => array(),
			'log_file'               => $log_path,
			'enable_logging'         => ! empty( $options['enable_logging'] ),
			'started_at'             => current_time( 'mysql' ),
			'updated_at'             => current_time( 'mysql' ),
		);

		update_option( TTM_OPTION_STATE, $state, false );

		if ( $log_path ) {
			$logger->log( sprintf( 'Term map ready: %d mappings, %d created, %d existing.', count( $term_map['map'] ), $term_map['created'], $term_map['existing'] ) );
			$logger->log( sprintf( 'Products to process: %d', $total ) );
		}

		$log_display = '';
		if ( $log_path ) {
			$upload      = wp_upload_dir();
			$log_display = str_replace( $upload['basedir'], '/wp-content/uploads', $log_path );
		}

		return array(
			'state'    => $this->public_state( $state ),
			'log_file' => $log_display,
		);
	}

	/**
	 * Process one batch of products.
	 *
	 * @return array|WP_Error
	 */
	public function process_batch() {
		$state = get_option( TTM_OPTION_STATE, array() );

		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return new WP_Error( 'ttm_not_running', __( 'Активный перенос не найден.', 'taxonomy-term-migrator' ) );
		}

		if ( 'stopped' === ( $state['status'] ?? '' ) ) {
			return new WP_Error( 'ttm_stopped', __( 'Перенос остановлен.', 'taxonomy-term-migrator' ) );
		}

		$options    = $state['options'];
		$batch_size = (int) ( $options['batch_size'] ?? TTM_BATCH_DEFAULT );
		$processed  = (int) $state['processed'];
		$ids        = array_slice( $state['product_ids'], $processed, $batch_size );

		$source = $state['source_taxonomy'];
		$target = $state['target_taxonomy'];
		$map    = $state['term_map'];

		foreach ( $ids as $product_id ) {
			$result = $this->migrate_product( $product_id, $source, $target, $map, $options );

			if ( is_wp_error( $result ) ) {
				$state['errors'][] = sprintf(
					'Product #%d: %s',
					$product_id,
					$result->get_error_message()
				);
			} else {
				if ( ! empty( $result['linked'] ) ) {
					++$state['linked_products'];
				}
				if ( ! empty( $result['removed'] ) ) {
					$state['removed_relations'] += (int) $result['removed'];
				}
			}

			++$state['processed'];
		}

		$state['updated_at'] = current_time( 'mysql' );

		if ( $state['processed'] >= $state['total'] ) {
			$state['status'] = 'completed';

			if ( ! empty( $options['delete_empty_source_terms'] ) && ! empty( $options['confirm_delete_terms'] ) ) {
				$deleted = $this->delete_empty_source_terms( $source );
				$state['deleted_terms'] = $deleted;
			}

			if ( ! empty( $options['cleanup_wc_attributes_after'] ) ) {
				$cleaner = new TTM_Attribute_Cleaner();
				$global  = $cleaner->run_global_cleanup();
				if ( is_wp_error( $global ) ) {
					$state['errors'][] = $global->get_error_message();
				} else {
					$state['attribute_cleanup'] = $global;
					$state['cleanup_product_offset'] = 0;
					$state['cleanup_product_total']  = $cleaner->count_products();
				}
			}

			$this->finalize_log( $state );
		}

		update_option( TTM_OPTION_STATE, $state, false );

		$completed = 'completed' === ( $state['status'] ?? '' );
		$summary   = $completed ? $this->build_summary( $state ) : null;

		if ( $completed && isset( $state['cleanup_product_offset'], $state['cleanup_product_total'] ) && (int) $state['cleanup_product_total'] > 0 ) {
			$summary['cleanup_products_pending'] = (int) $state['cleanup_product_offset'] < (int) $state['cleanup_product_total'];
			$summary['cleanup_product_offset']   = (int) $state['cleanup_product_offset'];
			$summary['cleanup_product_total']    = (int) $state['cleanup_product_total'];
		}

		$response = array(
			'state'      => $this->public_state( $state ),
			'completed'  => $completed,
			'log_file'   => $this->get_log_display_path( $state ),
			'summary'    => $summary,
		);

		return $response;
	}

	/**
	 * Continue batched cleanup of empty product attributes after migration.
	 *
	 * @return array|WP_Error
	 */
	public function process_cleanup_products_batch() {
		$state = get_option( TTM_OPTION_STATE, array() );

		if ( empty( $state ) || 'completed' !== ( $state['status'] ?? '' ) ) {
			return new WP_Error( 'ttm_no_cleanup', __( 'Нет завершённого переноса с очисткой товаров.', 'taxonomy-term-migrator' ) );
		}

		if ( ! isset( $state['cleanup_product_offset'], $state['cleanup_product_total'] ) ) {
			return new WP_Error( 'ttm_no_cleanup', __( 'Очистка товаров не требуется.', 'taxonomy-term-migrator' ) );
		}

		$offset = (int) $state['cleanup_product_offset'];
		if ( $offset >= (int) $state['cleanup_product_total'] ) {
			return array(
				'done'    => true,
				'summary' => $this->build_summary( $state ),
			);
		}

		$cleaner = new TTM_Attribute_Cleaner();
		$batch   = $cleaner->clean_products_batch( $offset, TTM_Attribute_Cleaner::PRODUCT_BATCH_SIZE );

		if ( ! isset( $state['attribute_cleanup'] ) || ! is_array( $state['attribute_cleanup'] ) ) {
			$state['attribute_cleanup'] = array(
				'deleted_terms'      => 0,
				'deleted_attributes' => 0,
				'cleaned_products'   => 0,
				'errors'             => array(),
			);
		}

		$state['attribute_cleanup']['cleaned_products'] += (int) $batch['cleaned_products'];
		$state['cleanup_product_offset'] = (int) $batch['next_offset'];
		$state['updated_at']             = current_time( 'mysql' );

		$done = empty( $batch['has_more'] );

		if ( $done ) {
			unset( $state['cleanup_product_offset'], $state['cleanup_product_total'] );
		}

		update_option( TTM_OPTION_STATE, $state, false );

		$summary = $this->build_summary( $state );

		$summary['cleanup_products_pending'] = ! $done;
		$summary['cleanup_product_offset']   = (int) ( $state['cleanup_product_offset'] ?? $batch['next_offset'] );
		$summary['cleanup_product_total']    = (int) ( $state['cleanup_product_total'] ?? $batch['total'] );

		return array(
			'done'           => $done,
			'batch'          => $batch,
			'summary'        => $summary,
			'cleaned_in_batch' => (int) $batch['cleaned_products'],
		);
	}

	/**
	 * Stop running migration.
	 *
	 * @return array|WP_Error
	 */
	public function stop_migration() {
		$state = get_option( TTM_OPTION_STATE, array() );

		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return new WP_Error( 'ttm_not_running', __( 'Нет активного переноса для остановки.', 'taxonomy-term-migrator' ) );
		}

		$state['status']     = 'stopped';
		$state['updated_at'] = current_time( 'mysql' );
		update_option( TTM_OPTION_STATE, $state, false );

		$this->finalize_log( $state, true );

		return array(
			'state'   => $this->public_state( $state ),
			'message' => __( 'Перенос остановлен.', 'taxonomy-term-migrator' ),
		);
	}

	/**
	 * Get current migration state for UI.
	 *
	 * @return array|null
	 */
	public function get_state() {
		$state = get_option( TTM_OPTION_STATE, array() );
		if ( empty( $state ) ) {
			return null;
		}
		return $this->public_state( $state );
	}

	/**
	 * @return bool
	 */
	public function is_migration_running() {
		$state = get_option( TTM_OPTION_STATE, array() );
		return ! empty( $state ) && 'running' === ( $state['status'] ?? '' );
	}

	/**
	 * Clear completed/stopped state.
	 */
	public function clear_state() {
		delete_option( TTM_OPTION_STATE );
	}

	/**
	 * @param string $source Source.
	 * @param string $target Target.
	 * @return true|WP_Error
	 */
	private function validate_taxonomies( $source, $target ) {
		if ( empty( $source ) || empty( $target ) ) {
			return new WP_Error( 'ttm_empty', __( 'Выберите донорскую и целевую таксономии.', 'taxonomy-term-migrator' ) );
		}

		if ( $source === $target ) {
			return new WP_Error( 'ttm_same', __( 'Донорская и целевая таксономии не могут совпадать.', 'taxonomy-term-migrator' ) );
		}

		if ( ! self::is_valid_taxonomy( $source ) || ! self::is_valid_taxonomy( $target ) ) {
			return new WP_Error( 'ttm_invalid', __( 'Указана несуществующая таксономия.', 'taxonomy-term-migrator' ) );
		}

		return true;
	}

	/**
	 * Match source term to target term (preview mode skips creation).
	 *
	 * @param WP_Term $source_term Source term.
	 * @param string  $target_taxonomy Target taxonomy.
	 * @param bool    $create_missing Create if missing.
	 * @param array   $options Options.
	 * @param TTM_Logger|null $logger Logger.
	 * @return array{term_id: int, slug_conflict: bool, created: bool}
	 */
	private function match_term( $source_term, $target_taxonomy, $create_missing = false, array $options = array(), $logger = null ) {
		$slug_conflict = false;

		$by_slug = get_term_by( 'slug', $source_term->slug, $target_taxonomy );
		if ( $by_slug && ! is_wp_error( $by_slug ) ) {
			return array(
				'term_id'       => (int) $by_slug->term_id,
				'slug_conflict' => false,
				'created'       => false,
			);
		}

		$by_name = get_term_by( 'name', $source_term->name, $target_taxonomy );
		if ( $by_name && ! is_wp_error( $by_name ) ) {
			return array(
				'term_id'       => (int) $by_name->term_id,
				'slug_conflict' => false,
				'created'       => false,
			);
		}

		if ( ! $create_missing || empty( $options['create_missing_terms'] ) ) {
			$existing_slug = get_term_by( 'slug', $source_term->slug, $target_taxonomy );
			if ( $existing_slug ) {
				$slug_conflict = true;
			} else {
				$slug_taken = term_exists( $source_term->slug, $target_taxonomy );
				if ( $slug_taken && ( is_array( $slug_taken ) ? (int) $slug_taken['term_id'] : (int) $slug_taken ) ) {
					$slug_conflict = true;
				}
			}

			return array(
				'term_id'       => 0,
				'slug_conflict' => $slug_conflict,
				'created'       => false,
			);
		}

		$args = array(
			'description' => $source_term->description,
		);

		if ( ! empty( $options['preserve_slug'] ) ) {
			$args['slug'] = $source_term->slug;
			$slug_check   = term_exists( $source_term->slug, $target_taxonomy );
			if ( $slug_check ) {
				$slug_conflict = true;
			}
		}

		$parent_id = 0;
		if ( is_taxonomy_hierarchical( $target_taxonomy ) && $source_term->parent ) {
			$parent_id = $this->resolve_parent( $source_term->parent, $target_taxonomy, $options, $logger );
		}

		if ( $parent_id ) {
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $source_term->name, $target_taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			if ( $logger ) {
				$logger->log( 'Failed to create term "' . $source_term->name . '": ' . $result->get_error_message() );
			}
			return array(
				'term_id'       => 0,
				'slug_conflict' => $slug_conflict,
				'created'       => false,
			);
		}

		$new_term = get_term( $result['term_id'], $target_taxonomy );
		if ( $new_term && $new_term->slug !== $source_term->slug && ! empty( $options['preserve_slug'] ) ) {
			$slug_conflict = true;
			if ( $logger ) {
				$logger->log(
					sprintf(
						'Slug changed for "%s": requested "%s", got "%s".',
						$source_term->name,
						$source_term->slug,
						$new_term->slug
					)
				);
			}
		}

		if ( $logger ) {
			$logger->log( sprintf( 'Created term: %s (ID %d)', $source_term->name, $result['term_id'] ) );
		}

		return array(
			'term_id'       => (int) $result['term_id'],
			'slug_conflict' => $slug_conflict,
			'created'       => true,
		);
	}

	/**
	 * @param int    $source_parent_id Source parent term ID.
	 * @param string $target_taxonomy Target taxonomy.
	 * @param array  $options Options.
	 * @param TTM_Logger|null $logger Logger.
	 * @return int
	 */
	private function resolve_parent( $source_parent_id, $target_taxonomy, array $options, $logger = null ) {
		$parent = get_term( $source_parent_id );
		if ( ! $parent || is_wp_error( $parent ) ) {
			return 0;
		}

		$match = $this->match_term( $parent, $target_taxonomy, true, $options, $logger );
		return (int) $match['term_id'];
	}

	/**
	 * Build full term map before product processing.
	 *
	 * @param string $source Source taxonomy.
	 * @param string $target Target taxonomy.
	 * @param array  $options Options.
	 * @param TTM_Logger $logger Logger.
	 * @return array|WP_Error
	 */
	private function build_term_map( $source, $target, array $options, TTM_Logger $logger ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $source,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$map      = array();
		$created  = 0;
		$existing = 0;

		foreach ( $terms as $term ) {
			$match = $this->match_term( $term, $target, true, $options, $logger );
			if ( $match['term_id'] ) {
				$map[ $term->term_id ] = $match['term_id'];
				if ( $match['created'] ) {
					++$created;
				} else {
					++$existing;
				}
			}
		}

		return array(
			'map'      => $map,
			'created'  => $created,
			'existing' => $existing,
		);
	}

	/**
	 * @param int    $product_id Product ID.
	 * @param string $source Source taxonomy.
	 * @param string $target Target taxonomy.
	 * @param array  $term_map Term ID map.
	 * @param array  $options Options.
	 * @return array|WP_Error
	 */
	private function migrate_product( $product_id, $source, $target, array $term_map, array $options ) {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'ttm_not_product', __( 'Не товар.', 'taxonomy-term-migrator' ) );
		}

		$source_terms = wp_get_object_terms( $product_id, $source, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
			return array( 'linked' => false, 'removed' => 0 );
		}

		if ( empty( $options['transfer_relations'] ) ) {
			return array( 'linked' => false, 'removed' => 0 );
		}

		$target_ids = array();
		foreach ( $source_terms as $source_term_id ) {
			if ( isset( $term_map[ $source_term_id ] ) ) {
				$target_ids[] = (int) $term_map[ $source_term_id ];
			}
		}

		$target_ids = array_unique( array_filter( $target_ids ) );
		if ( empty( $target_ids ) ) {
			return array( 'linked' => false, 'removed' => 0 );
		}

		$linked  = false;
		$removed = 0;

		$result = wp_set_object_terms( $product_id, $target_ids, $target, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$linked = true;

		if ( ! empty( $options['remove_old_relations'] ) && $linked ) {
			$remove_result = wp_remove_object_terms( $product_id, $source_terms, $source );
			if ( is_wp_error( $remove_result ) ) {
				return $remove_result;
			}
			$removed = count( $source_terms );
		}

		return array(
			'linked'  => $linked,
			'removed' => $removed,
		);
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function count_products_with_terms( $taxonomy ) {
		return count( $this->get_product_ids_with_terms( $taxonomy ) );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function get_product_ids_with_terms( $taxonomy ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
						'operator' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * @param string $source Source taxonomy.
	 * @return int
	 */
	private function delete_empty_source_terms( $source ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $source,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $terms as $term ) {
			if ( (int) $term->count > 0 ) {
				continue;
			}

			$result = wp_delete_term( $term->term_id, $source );
			if ( ! is_wp_error( $result ) && $result ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * @param array $state State.
	 * @param bool  $stopped Was stopped.
	 */
	private function finalize_log( array $state, $stopped = false ) {
		if ( empty( $state['enable_logging'] ) || empty( $state['log_file'] ) ) {
			return;
		}

		$logger = new TTM_Logger( true );
		// Use direct file append for finish since we have path in state.
		$summary = $this->build_summary( $state );
		if ( $stopped ) {
			$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] Migration stopped by user.' . "\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $state['log_file'], $line, FILE_APPEND | LOCK_EX );
		}

		$footer = sprintf(
			"\n=== %s ===\nFinished: %s\nCreated terms: %d\nUsed existing terms: %d\nLinked products: %d\nRemoved old relations: %d\nErrors: %d\n",
			$stopped ? 'Stopped' : 'Completed',
			gmdate( 'Y-m-d H:i:s' ),
			(int) ( $state['created_terms'] ?? 0 ),
			(int) ( $state['used_existing_terms'] ?? 0 ),
			(int) ( $state['linked_products'] ?? 0 ),
			(int) ( $state['removed_relations'] ?? 0 ),
			count( $state['errors'] ?? array() )
		);

		if ( ! empty( $state['errors'] ) ) {
			$footer .= "\nError details:\n";
			foreach ( $state['errors'] as $error ) {
				$footer .= ' - ' . $error . "\n";
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $state['log_file'], $footer, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param array $state State.
	 * @return array
	 */
	private function build_summary( array $state ) {
		$summary = array(
			'processed'           => (int) ( $state['processed'] ?? 0 ),
			'created_terms'       => (int) ( $state['created_terms'] ?? 0 ),
			'used_existing_terms' => (int) ( $state['used_existing_terms'] ?? 0 ),
			'linked_products'     => (int) ( $state['linked_products'] ?? 0 ),
			'removed_relations'   => (int) ( $state['removed_relations'] ?? 0 ),
			'deleted_terms'       => (int) ( $state['deleted_terms'] ?? 0 ),
			'errors_count'        => count( $state['errors'] ?? array() ),
			'log_file'            => $this->get_log_display_path( $state ),
		);

		if ( ! empty( $state['attribute_cleanup'] ) && is_array( $state['attribute_cleanup'] ) ) {
			$cleanup = $state['attribute_cleanup'];
			$summary['cleanup_deleted_terms']      = (int) ( $cleanup['deleted_terms'] ?? 0 );
			$summary['cleanup_deleted_attributes'] = (int) ( $cleanup['deleted_attributes'] ?? 0 );
			$summary['cleanup_cleaned_products']   = (int) ( $cleanup['cleaned_products'] ?? 0 );
		}

		return $summary;
	}

	/**
	 * @param array $state State.
	 * @return array
	 */
	private function public_state( array $state ) {
		return array(
			'status'              => $state['status'] ?? '',
			'source_taxonomy'     => $state['source_taxonomy'] ?? '',
			'target_taxonomy'     => $state['target_taxonomy'] ?? '',
			'processed'           => (int) ( $state['processed'] ?? 0 ),
			'total'               => (int) ( $state['total'] ?? 0 ),
			'created_terms'       => (int) ( $state['created_terms'] ?? 0 ),
			'linked_products'     => (int) ( $state['linked_products'] ?? 0 ),
			'removed_relations'   => (int) ( $state['removed_relations'] ?? 0 ),
			'errors_count'        => count( $state['errors'] ?? array() ),
			'started_at'          => $state['started_at'] ?? '',
			'updated_at'          => $state['updated_at'] ?? '',
		);
	}

	/**
	 * @param array $state State.
	 * @return string
	 */
	private function get_log_display_path( array $state ) {
		if ( empty( $state['log_file'] ) ) {
			return '';
		}

		$upload = wp_upload_dir();
		if ( false !== strpos( $state['log_file'], $upload['basedir'] ) ) {
			return str_replace( $upload['basedir'], '/wp-content/uploads', $state['log_file'] );
		}

		return $state['log_file'];
	}
}
