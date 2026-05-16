<?php
/**
 * Cleanup empty WooCommerce attribute terms and attributes.
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TTM_Attribute_Cleaner
 */
class TTM_Attribute_Cleaner {

	/** @var int */
	const PRODUCT_BATCH_SIZE = 100;

	/**
	 * Delete attribute terms that are not assigned to any products.
	 *
	 * @return array|WP_Error
	 */
	public function delete_empty_terms() {
		$attributes = $this->get_attributes();
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}

		$deleted_terms = 0;
		$errors        = array();

		foreach ( $attributes as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = $this->get_attribute_terms( $taxonomy );

			if ( is_wp_error( $terms ) ) {
				$errors[] = $terms->get_error_message();
				continue;
			}

			foreach ( $terms as $term ) {
				if ( (int) $term->count >= 1 ) {
					continue;
				}

				$result = wp_delete_term( $term->term_id, $taxonomy );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						'%s / %s: %s',
						$taxonomy,
						$term->name,
						$result->get_error_message()
					);
					continue;
				}

				if ( $result ) {
					++$deleted_terms;
				}
			}
		}

		return $this->result( $deleted_terms, 0, 0, $errors );
	}

	/**
	 * Delete attributes that have no terms or only empty (unused) terms.
	 *
	 * @return array|WP_Error
	 */
	public function delete_empty_attributes() {
		$attributes = $this->get_attributes();
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}

		$deleted_terms      = 0;
		$deleted_attributes = 0;
		$errors             = array();

		foreach ( $attributes as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = $this->get_attribute_terms( $taxonomy );

			if ( is_wp_error( $terms ) ) {
				$errors[] = $terms->get_error_message();
				continue;
			}

			$all_terms_empty = true;

			foreach ( $terms as $term ) {
				if ( (int) $term->count >= 1 ) {
					$all_terms_empty = false;
					break;
				}
			}

			if ( ! $all_terms_empty ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$result = wp_delete_term( $term->term_id, $taxonomy );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						'%s / %s: %s',
						$taxonomy,
						$term->name,
						$result->get_error_message()
					);
					continue;
				}

				if ( $result ) {
					++$deleted_terms;
				}
			}

			$deleted = wc_delete_attribute( (int) $attribute->attribute_id );
			if ( is_wp_error( $deleted ) ) {
				$errors[] = sprintf(
					'%s (ID %d): %s',
					$attribute->attribute_label,
					$attribute->attribute_id,
					$deleted->get_error_message()
				);
				continue;
			}

			if ( $deleted ) {
				++$deleted_attributes;
			}
		}

		return $this->result( $deleted_terms, $deleted_attributes, 0, $errors );
	}

	/**
	 * Remove empty attributes from a single product (tab «Атрибуты» in admin).
	 *
	 * @param int $product_id Product ID.
	 * @return bool Whether the product was updated.
	 */
	public function clean_product_attributes( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$attributes = $product->get_attributes();
		if ( empty( $attributes ) ) {
			return false;
		}

		$changed   = false;
		$new_attrs = array();

		foreach ( $attributes as $key => $attribute ) {
			if ( $this->is_product_attribute_empty( $product, $attribute ) ) {
				$changed = true;
				continue;
			}
			$new_attrs[ $key ] = $attribute;
		}

		if ( ! $changed ) {
			return false;
		}

		$product->set_attributes( $new_attrs );
		$product->save();

		return true;
	}

	/**
	 * Batch cleanup of product-level empty attributes.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Batch size.
	 * @return array
	 */
	public function clean_products_batch( $offset = 0, $limit = 0 ) {
		$limit = $limit > 0 ? $limit : self::PRODUCT_BATCH_SIZE;
		$offset = max( 0, (int) $offset );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$cleaned = 0;
		foreach ( $query->posts as $product_id ) {
			if ( $this->clean_product_attributes( (int) $product_id ) ) {
				++$cleaned;
			}
		}

		$processed = count( $query->posts );
		$total     = (int) $query->found_posts;
		$next      = $offset + $processed;

		return array(
			'cleaned_products' => $cleaned,
			'processed'        => $processed,
			'offset'           => $offset,
			'next_offset'      => $next,
			'total'            => $total,
			'has_more'         => $next < $total,
			'errors'           => array(),
		);
	}

	/**
	 * Count products for cleanup progress.
	 *
	 * @return int
	 */
	public function count_products() {
		$counts = wp_count_posts( 'product' );
		if ( ! $counts ) {
			return 0;
		}

		$total = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( 'auto-draft' === $status || 'trash' === $status ) {
				continue;
			}
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * Global cleanup: empty terms + empty global attributes.
	 *
	 * @return array|WP_Error
	 */
	public function run_global_cleanup() {
		$terms = $this->delete_empty_terms();
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$attributes = $this->delete_empty_attributes();
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}

		return $this->merge_results( $terms, $attributes );
	}

	/**
	 * Full cleanup: global + all products (may take time).
	 *
	 * @return array|WP_Error
	 */
	public function run_full_cleanup() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		$global = $this->run_global_cleanup();
		if ( is_wp_error( $global ) ) {
			return $global;
		}

		$offset  = 0;
		$cleaned = 0;

		do {
			$batch = $this->clean_products_batch( $offset, self::PRODUCT_BATCH_SIZE );
			$cleaned += (int) $batch['cleaned_products'];
			$offset   = (int) $batch['next_offset'];
			$has_more = ! empty( $batch['has_more'] );
		} while ( $has_more );

		$global['cleaned_products'] = $cleaned;

		return $global;
	}

	/**
	 * @param WC_Product            $product   Product.
	 * @param WC_Product_Attribute  $attribute Attribute.
	 * @return bool
	 */
	private function is_product_attribute_empty( $product, $attribute ) {
		if ( ! $attribute instanceof WC_Product_Attribute ) {
			return true;
		}

		if ( $attribute->is_taxonomy() ) {
			$term_ids = wc_get_product_terms(
				$product->get_id(),
				$attribute->get_name(),
				array( 'fields' => 'ids' )
			);

			if ( is_wp_error( $term_ids ) ) {
				return true;
			}

			return empty( $term_ids );
		}

		$options = $attribute->get_options();
		if ( empty( $options ) ) {
			return true;
		}

		foreach ( $options as $option ) {
			if ( is_string( $option ) && '' !== trim( $option ) ) {
				return false;
			}
			if ( ! is_string( $option ) && ! empty( $option ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param int   $deleted_terms      Deleted terms.
	 * @param int   $deleted_attributes Deleted attributes.
	 * @param int   $cleaned_products   Cleaned products.
	 * @param array $errors             Errors.
	 * @return array
	 */
	private function result( $deleted_terms, $deleted_attributes, $cleaned_products, array $errors ) {
		return array(
			'deleted_terms'      => (int) $deleted_terms,
			'deleted_attributes' => (int) $deleted_attributes,
			'cleaned_products'   => (int) $cleaned_products,
			'errors'             => $errors,
		);
	}

	/**
	 * @param array $a First result.
	 * @param array $b Second result.
	 * @return array
	 */
	private function merge_results( array $a, array $b ) {
		return array(
			'deleted_terms'      => (int) ( $a['deleted_terms'] ?? 0 ) + (int) ( $b['deleted_terms'] ?? 0 ),
			'deleted_attributes' => (int) ( $a['deleted_attributes'] ?? 0 ) + (int) ( $b['deleted_attributes'] ?? 0 ),
			'cleaned_products'   => (int) ( $a['cleaned_products'] ?? 0 ) + (int) ( $b['cleaned_products'] ?? 0 ),
			'errors'             => array_merge( $a['errors'] ?? array(), $b['errors'] ?? array() ),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private function get_attributes() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return new WP_Error(
				'ttm_no_wc',
				__( 'WooCommerce недоступен.', 'taxonomy-term-migrator' )
			);
		}

		$attributes = wc_get_attribute_taxonomies();
		if ( empty( $attributes ) ) {
			return array();
		}

		return $attributes;
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @return WP_Term[]|WP_Error
	 */
	private function get_attribute_terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return is_array( $terms ) ? $terms : array();
	}
}
