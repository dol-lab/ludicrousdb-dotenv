<?php
/**
 * Class Ludicrousdb_Dotenv
 *
 * @package dol-lab/ludicrousdb-dotenv
 */

/**
 * The main plugin-class
 */
class Ludicrousdb_Dotenv {

	/**
	 * Contains different kinds of datasets.
	 *
	 * @var array
	 */
	public $datasets = array( 'blog', 'global' );

	/**
	 * Undocumented variable
	 *
	 * @var array
	 */
	public $dataset_decimals = array();

	/**
	 * Undocumented variable
	 *
	 * @var array( 'dataset_name' => ['decimals' => 3, 'shard_count' => 256 ])
	 */
	public $dataset_info = array();

	/**
	 * Contains the different keys to configure ludicrious-db.
	 *
	 * @var array
	 */
	public $default_keys = array( 'host', 'user', 'password', 'name', 'read', 'write', 'dataset' ); // timeout, lag_threshold.

	/**
	 * The default config for ludicrious-db which looks like:
	 * array( 'host' => DB_HOST, 'user' => DB_USER, ... )
	 *
	 * @var array
	 */
	public $ludic_defaults = array();

	/**
	 * Debug Helper.
	 *
	 * @var boolean logs to error-log if true.
	 */
	public $debug = false;

	/**
	 * Undocumented function
	 *
	 * @todo Construct with $datasets. Allow own callbacks for different datasets?
	 *
	 * @param array $defaults same as in LudicrousDB->add_database().
	 */
	public function __construct( array $defaults ) {
		$this->ludic_defaults = $defaults;
		$this->loop_datasets( array( $this, 'init_all_datasets_callback' ) );
		$this->init_callbacks();
		$this->maybe_init_cli();
		$this->maybe_init_admin();
	}

	/**
	 * Initialize callbacks
	 *
	 * @return void
	 */
	private function init_callbacks() {
		require_once 'class-ludicrousdb-dotenv-callbacks.php';
		$callbacks = new Ludicrousdb_Dotenv_Callbacks( $this );
		$callbacks->add_blog_callback( 'blog' );
	}

	/**
	 * Initialize backend (currently only site info: wp-admin/network/site-info.php).
	 *
	 * @return void
	 */
	private function maybe_init_admin() {
		require_once 'class-ludicrousdb-dotenv-admin.php';
		$admin = new Ludicrousdb_Dotenv_Admin( $this );
	}

	/**
	 * Initialize WP_CLI. Run `wp ludicrousdb_dotenv` via SSH to see available commands.
	 *
	 * @return void
	 */
	private function maybe_init_cli() {
		global $wpdb;
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		require_once 'class-ludicrousdb-dotenv-cli.php';
		$cli = new Ludicrousdb_Dotenv_Cli( $this );
		WP_CLI::add_command( 'ludicrousdb_dotenv', $cli );
	}

	/**
	 * Add the connection parameters for all shards of a dataset (like blogs).
	 *
	 * Example:
	 * You specified DB_BLOG_COUNT = 100.
	 * This loops all 100 blogs and sets up the connection-parameter in LudicrousDB.
	 *
	 * @param string $dataset_name The name of the dataset like 'blog'.
	 * @return mixed.
	 */
	private function init_all_datasets_callback( string $dataset_name ) {
		return $this->loop_dataset_shards( $dataset_name, array( $this, 'init_dataset_callback' ) );
	}

	/**
	 * Add the connection parameters for a single database-shard.
	 *
	 * @param string $dataset_name The name of the dataset like 'blog'.
	 * @param array  $db_config Configuration array for database. Can contain keys of $this->default_keys.
	 * @return void
	 */
	private function init_dataset_callback( string $dataset_name, array $db_config, $context ) {
		/**
		 * @var LudicrousDB $wpdb an extension to the wpdb class.
		 */
		global $wpdb;
		$wpdb->add_database( $db_config );
		$this->log( "Add database $dataset_name. " . print_r( $db_config, true ) );
	}

	/**
	 * Get the number of shards for a dataset. This is read from the .env file.
	 * The key (which is read) looks like DB_{$dataset_name}_COUNT.
	 *
	 * @param string $dataset_name The name of the dataset like 'blog'.
	 * @return int The number of shards for the dataset.
	 */
	public function get_dataset_count( string $dataset_name ) {

		if ( ! isset( $this->dataset_info[ $dataset_name ] ) ) {
			$dataset_count                                      = intval( $this->get_env( "DB_{$dataset_name}_COUNT" ) );
			$this->dataset_info[ $dataset_name ]['decimals']    = strlen( $dataset_count );
			$this->dataset_info[ $dataset_name ]['shard_count'] = $dataset_count;
		}
		return $this->dataset_info[ $dataset_name ]['shard_count'];
	}

	/**
	 * Loops all datasets you specified. Triggers a callback function for every dataset.
	 *
	 * @param callable $callback {
	 *    @type string $dataset_name The name of the current dataset
	 *    @return mixed. Return whatever you like.
	 * }
	 * @return array An array of the things the callback returns.
	 */
	public function loop_datasets( callable $callback ) : array {
		$return_data = array();
		foreach ( $this->datasets as $dataset_name ) {
			$return_data[] = call_user_func( $callback, $dataset_name );
		}
		return $return_data;
	}

	/**
	 * Loops all the shards for a specific dataset and check if there are specific settings.
	 * Otherwise settings are inherited from the previous one.
	 *
	 * @param string   $dataset_name The name of a dataset like 'blog' or 'global'.
	 * @param callable $callback {
	 *     @type string $dataset_name The name of the dataset.
	 *     @type array $db_config The configuration object for the dataset.
	 *     @return mixed. Return whatever you like.
	 * }
	 * @param array    $context Pass context to the callback if needed.
	 * @return array An array of the things the callback function returns.
	 */
	public function loop_dataset_shards( string $dataset_name, callable $callback, array $context = array() ) {
		/**
		 * @var LudicrousDB $wpdb an extension to the wpdb class.
		 */
		global $wpdb;

		$db_config = $this->ludic_defaults; // reset the defaults for each dataset.
		$dataset_count = $this->get_dataset_count( $dataset_name );
		$return_data = array();

		for ( $i = 0; $i < $dataset_count; $i++ ) {
			$current_id = "{$dataset_name}_{$i}"; // BLOG_1.
			foreach ( $this->default_keys as $key ) {
				$current_key = $current_id . '_' . $key; // BLOG_1_HOST.
				$current_sel = 'DB_' . $current_key;
				$value       = $this->get_env( $current_sel ); // DB_BLOG_1_HOST.

				if ( null === $value ) { // env() returns null if nothing is found.
					if ( 'name' === $key || 'dataset' === $key ) {
						$value = $this->get_db_name( $dataset_name, $i ); // yourdb_blog_1.
					} elseif ( isset( $db_config[ $key ] ) ) { // we use the previous value.
						$value = $db_config[ $key ];
					} else {
						die( "Something is wrong with your configuration in db-config.php. The key [$current_sel] is not set." );
					}
				}
				$db_config[ $key ] = $value;
			}
			$return_data[] = call_user_func_array( $callback, array( $dataset_name, $db_config, $context ) );
		}

		return $return_data;
	}

	/**
	 * Get the name of the database (not table) for a shard.
	 * A database can contain multiple blogs with multiple tables.
	 *
	 * @param string $dataset_name The name of the dataset.
	 * @param int    $shard_or_dataset_id The id of the dataset or shard.
	 * @return string like "yourdb_blog_1_HOST".
	 */
	public function get_db_name( $dataset_name, $shard_or_dataset_id ) {
		$shard_id      = $this->dataset_to_shard_id( $dataset_name, $shard_or_dataset_id );
		$decimals      = $this->dataset_info[ $dataset_name ]['decimals'];
		$leading_zeros = sprintf( "%0{$decimals}d", $shard_id ); // creates 001 from 1; and 099 from 99.
		return DB_NAME . "_{$dataset_name}_shard_{$leading_zeros}";
	}

	/**
	 * We assume that table names are structured in the way prefix_{$id}_something
	 *
	 * @param string $table_name The name of the table, like 'wp_22_posts'.
	 * @param string $prefix The name of the prefix, like 'wp'.
	 * @return int | false The id of the table, false if not properly formatted or without numeric id.
	 */
	public function table_name_to_id( string $table_name, string $prefix ) {
		$unprefixed = $this->remove_wp_table_prefix( $table_name, $prefix );
		if ( ! $unprefixed ) {
			return false; // doesn't have the prefix so it's not a table we search for.
		}

		$table_parts = explode( '_', $unprefixed );
		$id     = $table_parts[0];
		if ( ! is_numeric( $id ) ) {
			return false; // doesn't have a blog_id so it's not a blog.
		}
		return intval( $id );

	}

	/**
	 * A proxy for the env function that checks everything in uppercase.
	 *
	 * @see https://github.com/oscarotero/env
	 *
	 * @param string $name The name of the setting.
	 * @return mixed
	 */
	public function get_env( $name ) {
		return env( strtoupper( $name ) );
	}

	/**
	 * Removes the $wpdb->prefix from a tablename (also handles prefixes with underscores)
	 *
	 * @param string $table_name Name of the table.
	 * @param string $prefix WordPress table prefix.
	 * @return string|false false if the string doesn't have the table-prefix.
	 */
	private function remove_wp_table_prefix( $table_name, $prefix ) {
		$prefix_len = strlen( $prefix );
		if ( substr( $table_name, 0, $prefix_len ) === $prefix ) {
			return substr( $table_name, $prefix_len );
		}
		return false;
	}

	/**
	 * Convert the id of a dataset (like blog_id) to the id of a shard.
	 *
	 * Example: You have 3 shards and 4 blogs.
	 * blog 1 lands in shard 1, 2 in 2, 3 in 3, 4 again in 1.
	 *
	 * @param string $dataset_name The name of the dataset, like 'blog'.
	 * @param int    $dataset_id This can also be the shard id.
	 * @return int
	 */
	private function dataset_to_shard_id( $dataset_name, $dataset_id ) {
		$dataset_shard_count = $this->dataset_info[ $dataset_name ]['shard_count'];
		if ( ! $dataset_shard_count // we don't have a shard_count in the current dataset -> we don't shard.
			|| $dataset_id < $dataset_shard_count ) { // in this case: $dataset_id == $dataset_shard_id.
			return $dataset_id;
		}
		return ( $dataset_id % $dataset_shard_count );
	}



	/**
	 * Logs to error_log if $debug is set to true.
	 *
	 * @param strin $msg The message to log.
	 * @return void
	 */
	public function log( $msg ) {
		if ( $this->debug ) {
			error_log( $msg );
		}
	}

}
