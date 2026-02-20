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

// Composer autoloader (wp-ai-client, providers, etc.).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Load classes.
require_once __DIR__ . '/includes/class-memory.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-settings.php';

// Initialize wp-ai-client (will be unnecessary after WordPress 7.0).
if ( class_exists( 'WordPress\AI_Client\AI_Client' ) ) {
	add_action( 'init', array( 'WordPress\AI_Client\AI_Client', 'init' ) );
}

// Admin settings page.
if ( is_admin() ) {
	Boswell_Settings::init();
}

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
