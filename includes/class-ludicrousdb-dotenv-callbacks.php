<?php
/**
 * Class Ludicrousdb_Dotenv
 *
 * @package dol-lab/ludicrousdb-dotenv
 */

/**
 * The main plugin-class.
 *
 * Run with 'wp ludicrousdb_dotenv'
 */
class Ludicrousdb_Dotenv_Callbacks {
	/**
	 * @var Ludicrousdb_Dotenv
	 */
	public $ludic_dot;

	public function __construct( Ludicrousdb_Dotenv $ludic_dot ) {
		$this->ludic_dot = $ludic_dot;

	}

	/**
	 * Add a callback for blogs based on the amount of shards (DB_BLOG_COUNT).
	 * The rule is ( blog_id modulo number_of_blog_shards ).
	 *
	 * @todo how to we handle the first blog (which does not have a blog-id)?
	 * @todo improve error handling.
	 *
	 * @param string $dataset_name The name of the dataset.
	 * @return string|null The name of the database which is used for a blog or null (e.c. if it wasn't a blog query).
	 */
	public function add_blog_callback( string $dataset_name ) {

		$dataset_count = $this->ludic_dot->get_dataset_count( $dataset_name );

		if ( ! $dataset_count ) {
			return; // there aren't multipe shards defined for a dataset, so we don't think about it.
		}

		/**
		 * This wpdb-class is extended with LudicrousDB.
		 *
		 * @var LudicrousDB $wpdb an extension to the wpdb class.
		 */
		global $wpdb;

		$wpdb->add_callback(
			function( $query, $wpdb ) use ( $dataset_name, $dataset_count ) {
				$blog_id = $this->ludic_dot->table_name_to_id( $wpdb->table, $wpdb->base_prefix );
				if ( false === $blog_id ) {
					return;
				}
				$shard_id = $blog_id % $dataset_count;
				$db_name  = $this->ludic_dot->get_db_name( $dataset_name, $shard_id );
				$this->ludic_dot->log( 'blog_callback: ' . $wpdb->table . "($blog_id % $dataset_count = $shard_id) => $db_name " );
				return $db_name;

			}
		);
	}

}
