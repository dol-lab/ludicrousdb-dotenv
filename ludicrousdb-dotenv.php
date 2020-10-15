<?php
/**
 * LudicrousDB-Dotenv
 *
 * @package dol-lab/ludicrousdb-dotenv
 *
 * @wordpress-plugin
 * Plugin Name: LudicrousDB-Dotenv
 * Plugin URI:  https://github.com/dol-lab/ludicrousdb-dotenv
 * Author:      Vitus Schuhwerk
 * Author URI:  https://github.com/dol-lab/ludicrousdb-dotenv/graphs/contributors
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ludic-dotenv
 * Version:     0.1
 * Description: Configure LudicrousDB via. Dotenv. This is not your default plug&play WordpPress plugin. You need to change some files.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * This is not a default WordPress plugin. It does not do anything on its own.
 *
 * It just loads a class (Ludicrousdb_Dotenv) which can be used in a file (db-config.php) which is used
 * by the plugin LudicrousDB (this file also has to be added manually).
 *
 * @see https://github.com/stuttter/ludicrousdb
 */

if ( ! function_exists( 'env' ) ) {
	$autoload = __DIR__ . '/vendor/autoload.php';
	if ( ! file_exists( $autoload ) ) {
		wp_die( "The `env` - function does not exist. Please run `composer install` in the plugin's directory (ludicrousdb-dotenv)." );
	}
	require_once $autoload;
}

require_once 'includes/class-ludicrousdb-dotenv.php';
