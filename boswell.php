<?php
/**
 * Plugin Name: Boswell
 * Plugin URI:  https://github.com/hametuha/boswell
 * Description: Enrich your WordPress blog contents with AI-powered assistant.
 * Version:     0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Fumiki Takahashi
 * Author URI:  https://takahashifumiki.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: boswell
 *
 * @package Boswell
 */

defined( 'ABSPATH' ) || exit;

// Load classes.
require_once __DIR__ . '/includes/class-memory.php';
require_once __DIR__ . '/includes/class-rest-controller.php';

/**
 * Register REST API routes.
 */
add_action(
	'rest_api_init',
	function () {
		$controller = new Boswell_REST_Controller();
		$controller->register_routes();
	}
);

/**
 * Initialize default memory on activation.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( Boswell_Memory::OPTION_KEY ) ) {
			update_option( Boswell_Memory::OPTION_KEY, Boswell_Memory::get_default(), false );
			update_option( Boswell_Memory::UPDATED_AT_KEY, gmdate( 'c' ), false );
		}
	}
);

/**
 * Clean up options on uninstall.
 */
register_uninstall_hook(
	__FILE__,
	array( 'Boswell_Memory', 'uninstall' )
);
