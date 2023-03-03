<?php
/*
Plugin Name: Woocommerce Bulk Edit WP-CLI command
Plugin URI: https://github.com/jmslbam/jm-woocommerce-bulk-edit
Description: Let's you loop products and change stuff like attributes
Version: 0.1.0
Requires at least: 6.0
Requires PHP: 7.4
Author: Jaime Martinez
Author URI: https://jaimemartinez.nl?woocommerce-bulk-edit
Text Domain: jm-woocommerce-bulk-edit
Domain Path: /languages
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.txt


*/

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( defined( 'WP_CLI' ) && \WP_CLI ) {

	WP_CLI::add_hook( 'after_wp_load', function(){
		WP_CLI::add_command( 'jm woocommerce bulk-edit', '\JM\Woocommerce\BulkEdit\CLI\Command' );
	});
}
