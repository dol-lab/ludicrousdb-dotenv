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
class Ludicrousdb_Dotenv_Admin {
		/**
		 * @var Ludicrousdb_Dotenv
		 */
	public $ludic_dot;

	public function __construct( Ludicrousdb_Dotenv $ludic_dot ) {
		$this->ludic_dot = $ludic_dot;
		add_action( 'admin_footer', array( $this, 'ludicrous_blog_data' ) );
	}

	/**
	 * Show an info about the blog-shard.
	 * Sadly there are no hooks to customize, so we add it to the footer and move it to the content via JS.
	 *
	 * @return void
	 */
	public function ludicrous_blog_data() {
		global $pagenow;
		global $id; // the id of the blog ( $_REQUEST['id'] ) set in site-info.php.
		global $wpdb;
		if ( 'site-info.php' !== $pagenow || ! is_super_admin() || ! $id ) {
			return;
		}
		$db_name = $this->ludic_dot->get_db_name( 'blog', $id ); // for blogs: db_name = shard_name = dataset_name.
		if ( ! isset( $wpdb->ludicrous_servers[ $db_name ] ) ) {
			return;
		}
		$nested_table = $this->recursive_print( $wpdb->ludicrous_servers[ $db_name ] );
		echo "
			<div id='ludicrous_info'>
				<h3>LudicriousDB shard location(s) for blog $id.</h3>
				<div class='ludicrous_blog_data'>
					$nested_table
				</div>
			</div>
			<script>
				jQuery(function($){jQuery('#ludicrous_info').insertAfter(jQuery('.form-table'));});
			</script>
			<style>
				.ludicrous_blog_data * { display: flex; flex-direction:column; padding: 2px 8px; }
				.ludicrous_blog_data .horiz { align-items: center; flex-direction: row; border-left: 1px solid #ddd;}
				.horiz:last-of-type { margin-bottom: 20px; }
			</style>
		";
	}

	public function recursive_print( $value, $key = '', $markup = '' ) {

		if ( empty( $value ) || 'password' === $key || 'user' === $key ) {
			return $markup;
		}
		if ( is_int( $key ) || empty( $key ) ) { // skip array counter.
			return implode( '', $this->loop_array( $value, array( $this, 'recursive_print' ) ) );
		}

		$markup .= "<div class='horiz'><div>$key</div><div>";
		if ( is_array( $value ) ) {
			$markup .= implode( '', $this->loop_array( $value, array( $this, 'recursive_print' ) ) );
		} else {
			$markup .= "$value";
		}
		return $markup . "</div></div class='horiz'>";
	}

	private function loop_array( $arr, $cb ) {
		$return = array();
		foreach ( $arr as $key => $value ) {
			$return[] = $cb( $value, $key );
		}
		return $return;
	}


}
