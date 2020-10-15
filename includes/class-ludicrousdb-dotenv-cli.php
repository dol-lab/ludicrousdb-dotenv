<?php
/**
 * Move tables between shards.
 *
 * https://github.com/wp-cli/wp-cli/issues/4529
 * https://github.com/wp-cli/wp-cli/issues/4670
 *
 *
 * This is work in progress
 *
 * @todo: check if adding CLI-Things here actually works
 *
 * run via. wp ludicrousdb_dotenv
 */

class Ludicrousdb_Dotenv_Cli extends WP_CLI_Command {
	/**
	 * @var Ludicrousdb_Dotenv
	 */
	public $ludic_dot;

	/**
	 *
	 * @var LudicrousDB|QM_DB
	 */
	public $wpdb;

	public function __construct( Ludicrousdb_Dotenv $ludic_dot ) {
		global $wpdb;
		$this->ludic_dot = $ludic_dot;
		$this->wpdb = $wpdb;
	}


	// https://stackoverflow.com/questions/13053254/moving-data-from-one-table-of-databse-to-another-table-with-shell
	public function copy_tables_to_shards( $args, $assoc_args ) {

		WP_CLI::confirm(
			"This is experimental and works just once, moving blog tables to their shards. \n" .
			"This overwrites tables if the already exist. \n" .
			'Make sure mysqldump and mysql are installed. Are you sure?'
		);

		global $wpdb;

		$my_args = $this->unprefix_array_keys( 'db_', $assoc_args ); // we can't use --user, thats why we --db_user=

		// array( 'all-tables' => true )
		$table_names = \WP_CLI\Utils\wp_get_table_names( array(), array( 'all-tables-with-prefix' => $wpdb->base_prefix ) );
		$srv_orig = $this->get_writeable_servers( 'global' );

		if ( is_wp_error( $srv_orig ) ) {
			WP_CLI::error( $srv_orig->get_error_message() );
		}
		$srv_orig = wp_parse_args( $my_args, $srv_orig );

		$errors = array();
		$counter = 0;

		foreach ( $table_names as $table_name ) {
			$id = $this->ludic_dot->table_name_to_id( $table_name, $wpdb->base_prefix );

			if ( false === $id ) {
				continue;
			}

			$db_name_dest = $this->ludic_dot->get_db_name( 'blog', $id );
			$srv_dest = $this->get_writeable_servers( $db_name_dest );
			$srv_dest = wp_parse_args( $my_args, $srv_dest );

			if ( is_wp_error( $srv_dest ) ) {
				WP_CLI::error( $srv_dest->get_error_message(), false );
				continue;
			}

			/**
			 * This is a complicated method, because it needs to be able to copy tables across different hosts.
			 */
			$cmd =
				"mysqldump -h {$srv_orig['host']} -u {$srv_orig['user']} -p{$srv_orig['password']} {$srv_orig['name']} $table_name | " .
				"mysql -h {$srv_dest['host']} -u {$srv_dest['user']} -p{$srv_dest['password']} $db_name_dest";

			/**
			 * To cath errors from both pipes, we us a subshell.
			 */
			$output = shell_exec( "#!/usr/bin/bash\n sh -c '" . $cmd . "' 2>&1" );
			if ( ! empty( trim( $output ) ) ) {
				$errors[] = array(
					'table' => $table_name,
					'cmd' => $cmd,
				);
				WP_CLI::error( "Copy failed for table $table_name (database: {$srv_orig['name']} host:{$srv_orig['host']})", false );
				continue;
			} else {
				$counter ++;
				WP_CLI::success(
					"Successfully copied table $table_name " .
					"from database {$srv_orig['name']}@{$srv_orig['host']} " .
					"to database $db_name_dest@{$srv_dest['host']}"
				);
			}
		}

		if ( $counter ) {
			WP_CLI::success( "Successfully copied $counter tables." );
		}
		if ( ! empty( $errors ) ) {
			$this->permission_notice();
			WP_CLI::error( 'You had errors with the following commands: ' . print_r( $errors, true ), true );
		}
	}

	private function get_writeable_servers( $database_name ) {
		if (
			! isset( $this->wpdb->ludicrous_servers[ $database_name ] )
			|| ! isset( $this->wpdb->ludicrous_servers[ $database_name ]['write'] ) ) {
				return new WP_Error( 'broke', "No (writeable) server found for database $database_name" );
		}

		$server = $this->wpdb->ludicrous_servers[ $database_name ]['write'];
		$it = new RecursiveIteratorIterator( new RecursiveArrayIterator( $server ) );
		return iterator_to_array( $it ); // might be multiple?
	}

	public function show() {
		global $wpdb;
		$servers = print_r( $wpdb->ludicrous_servers, true );
		WP_CLI::log( $servers );
	}

	public function permission_notice() {
		WP_CLI::log(
			'If you get permission problems, use a privileged user via --db_user --db_password.' .
			'(Default for trellis dev is --db_user=root --db_password=devpw)'
		);
	}

	public function create_databases( $args, $assoc_args ) {
		$queries = $this->ludic_dot->loop_dataset_shards( 'blog', array( $this, 'create_shard_database_callback' ) );

		$my_args = $this->unprefix_array_keys( 'db_', $assoc_args ); // we can't use --user, thats why we --db_user=
		$errors = array();

		foreach ( $queries as $q ) {
			$q['conf'] = wp_parse_args( $my_args, $q['conf'] );
			// WP_CLI::log( print_r( $q['conf'], true ) );
			$mysql_base = "mysql --user={$q['conf']['user']} --password='{$q['conf']['password']}' --execute=";
			$sh_echo_output = ' 2>&1 | cat'; // echo both stout (regular log) and stderr (errors).

			/**
			 * Create the db-tables
			 */
			$sh = $mysql_base . "'{$q['sql_create']}'" . $sh_echo_output;
			$output = shell_exec( $sh ); // output is empty on success.
			// WP_CLI::log( $sh . "\n" . $output );

			if ( trim( $output ) ) {
				$errors[] = array(
					'sql' => $q['sql_create'],
					'sh' => $sh,
					'error' => $output,
				);
			} else {
				WP_CLI::success( 'Successfully created database: ' . $q['sql_create'] );
			}

			/**
			 * Give the current user access to the tables (this is not --db_user).
			 */
			$sh = $mysql_base . "'{$q['sql_privilege']}'" . $sh_echo_output;
			WP_CLI::log( $sh );
			$output = shell_exec( $sh ); // output is empty on success.
			if ( trim( $output ) ) {
				$errors[] = array(
					'sql' => $q['sql_permissions'],
					'sh' => $sh,
					'error' => $output,
				);
			} else {
				WP_CLI::success( 'Successfully granted privileges.' . $q['sql_create'] );
			}
		}

		if ( ! empty( $errors ) ) {
			WP_CLI::error( 'You had errors with some queries.' . print_r( $errors, true ), true );
			$this->permission_notice();
		}

		WP_CLI::log( $output );
		// $output = shell_exec( "wp db --dbuser=root --dbpass=devpw query \"$create_db_query\" --allow-root" );
	}

	private function unprefix_array_keys( string $prefix, array $array ) {
		return array_combine(
			array_map(
				function( $key ) use ( $prefix ) {
					return str_replace( $prefix, '', $key );
				},
				array_keys( $array )
			),
			$array
		);
	}

	public function create_shard_database_callback( $dataset_name, $db_config, $context ) {
		global $wpdb;
		return array(
			'conf' => $db_config,
			'sql_create' => "CREATE DATABASE {$db_config['name']} DEFAULT CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate};",
			'sql_privilege' => "GRANT ALL ON {$db_config['name']}.* TO {$db_config['user']}@{$db_config['host']}; FLUSH PRIVILEGES;",
		);
	}

	private function export_all_shards() {

	}

	/**
	 * https://github.com/wp-cli/search-replace-command
	 *
	 * @return void
	 */
	private function import_all_shards() {

	}
}
