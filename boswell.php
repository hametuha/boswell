<?php
/**
 * Plugin Name: Boswell
 * Plugin URI:  https://github.com/hametuha/boswell
 * Description: Enrich your WordPress blog contents with AI-powered assistant.
 * Version:     0.1.0
 * Requires at least: 7.0
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
require_once __DIR__ . '/includes/class-persona.php';
require_once __DIR__ . '/includes/class-persona-admin.php';
require_once __DIR__ . '/includes/class-commenter.php';
require_once __DIR__ . '/includes/class-strategy-selector.php';
require_once __DIR__ . '/includes/class-cron.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-abilities.php';
require_once __DIR__ . '/includes/class-cli.php';

// MCP adapter (exposes Boswell abilities as MCP tools/resources/prompts).
if ( class_exists( 'WP\MCP\Plugin' ) ) {
	WP\MCP\Plugin::instance();
}

// Abilities API registration.
if ( function_exists( 'wp_register_ability' ) ) {
	Boswell_Abilities::init();
}

// Cron handler.
Boswell_Cron::init();

// Admin settings page.
if ( is_admin() ) {
	Boswell_Persona_Admin::init();
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
 * Initialize defaults and run migrations on activation.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( Boswell_Memory::OPTION_KEY ) ) {
			update_option( Boswell_Memory::OPTION_KEY, Boswell_Memory::get_default(), false );
			update_option( Boswell_Memory::UPDATED_AT_KEY, gmdate( 'c' ), false );
		}
		Boswell_Persona::migrate();
	}
);

/**
 * Unschedule cron on deactivation.
 */
register_deactivation_hook( __FILE__, array( 'Boswell_Cron', 'unschedule' ) );

/**
 * Clean up options on uninstall.
 */
register_uninstall_hook( __FILE__, 'boswell_uninstall' );

/**
 * Uninstall callback — remove all Boswell data.
 */
function boswell_uninstall(): void {
	Boswell_Memory::uninstall();
	Boswell_Persona::uninstall();
	Boswell_Cron::uninstall();
}
