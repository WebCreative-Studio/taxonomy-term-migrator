<?php
/**
 * Lightweight updater for the WCS private update server.
 *
 * @package Taxonomy_Term_Migrator
 */

defined( 'ABSPATH' ) || exit;

class TTM_Updater {
	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Remote metadata endpoint.
	 *
	 * @var string
	 */
	private $metadata_url;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file  Main plugin file.
	 * @param string $version      Installed plugin version.
	 * @param string $metadata_url Remote metadata endpoint.
	 */
	public function __construct( $plugin_file, $version, $metadata_url ) {
		$this->basename     = plugin_basename( $plugin_file );
		$this->version      = $version;
		$this->metadata_url = $metadata_url;
	}

	/**
	 * Register WordPress update hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Inject update information into WordPress' update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$metadata = $this->get_remote_metadata();

		if ( ! $metadata || empty( $metadata['version'] ) || empty( $metadata['download_url'] ) ) {
			return $transient;
		}

		if ( ! version_compare( $metadata['version'], $this->version, '>' ) ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'id'           => $this->basename,
			'slug'         => isset( $metadata['slug'] ) ? $metadata['slug'] : dirname( $this->basename ),
			'plugin'       => $this->basename,
			'new_version'  => $metadata['version'],
			'url'          => isset( $metadata['homepage'] ) ? $metadata['homepage'] : '',
			'package'      => $metadata['download_url'],
			'requires'     => isset( $metadata['requires'] ) ? $metadata['requires'] : '',
			'tested'       => isset( $metadata['tested'] ) ? $metadata['tested'] : '',
			'requires_php' => isset( $metadata['requires_php'] ) ? $metadata['requires_php'] : '',
		);

		return $transient;
	}

	/**
	 * Return plugin details for the WordPress update modal.
	 *
	 * @param false|object|array $result Previous result.
	 * @param string             $action API action.
	 * @param object             $args   API args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$metadata = $this->get_remote_metadata();

		if ( ! $metadata || empty( $metadata['slug'] ) || $metadata['slug'] !== $args->slug ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $metadata['name'] ) ? $metadata['name'] : 'Taxonomy Term Migrator for WooCommerce',
			'slug'          => $metadata['slug'],
			'version'       => $metadata['version'],
			'author'        => isset( $metadata['author'] ) ? $metadata['author'] : 'WebCreative Studio',
			'homepage'      => isset( $metadata['homepage'] ) ? $metadata['homepage'] : '',
			'requires'      => isset( $metadata['requires'] ) ? $metadata['requires'] : '',
			'tested'        => isset( $metadata['tested'] ) ? $metadata['tested'] : '',
			'requires_php'  => isset( $metadata['requires_php'] ) ? $metadata['requires_php'] : '',
			'download_link' => $metadata['download_url'],
			'sections'      => array(
				'description' => isset( $metadata['description'] ) ? $metadata['description'] : '',
				'changelog'   => isset( $metadata['changelog'] ) ? $metadata['changelog'] : '',
			),
		);
	}

	/**
	 * Fetch and cache metadata from the private update server.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_remote_metadata() {
		$cache_key = 'ttm_update_metadata';
		$cached    = get_site_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->metadata_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$metadata = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $metadata ) ) {
			return null;
		}

		set_site_transient( $cache_key, $metadata, 6 * HOUR_IN_SECONDS );

		return $metadata;
	}
}
