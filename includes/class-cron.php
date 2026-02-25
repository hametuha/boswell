<?php
/**
 * Boswell Cron
 *
 * Per-persona automatic commenting and post selection.
 *
 * @package Boswell
 */

/**
 * Cron scheduling and automatic comment generation.
 */
class Boswell_Cron {

	const HOOK_NAME = 'boswell_cron_comment';

	/**
	 * Allowed frequencies (WordPress built-in schedules).
	 */
	const FREQUENCIES = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Register the cron handler.
	 */
	public static function init(): void {
		add_action( self::HOOK_NAME, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Reschedule cron for a specific persona based on its current settings.
	 *
	 * Called by Boswell_Persona::save() after persisting persona data.
	 *
	 * @param string $persona_id Persona ID.
	 */
	public static function reschedule( string $persona_id ): void {
		self::unschedule( $persona_id );

		$persona = Boswell_Persona::get( $persona_id );
		if ( $persona && ! empty( $persona['cron_enabled'] ) ) {
			$frequency = $persona['cron_frequency'] ?? 'daily';
			wp_schedule_event( time(), $frequency, self::HOOK_NAME, array( $persona_id ) );
		}
	}

	/**
	 * Remove the scheduled cron event for a persona (or all if no ID given).
	 *
	 * @param string $persona_id Persona ID, or empty to unschedule all.
	 */
	public static function unschedule( string $persona_id = '' ): void {
		if ( ! empty( $persona_id ) ) {
			wp_clear_scheduled_hook( self::HOOK_NAME, array( $persona_id ) );
			return;
		}

		// Unschedule all — iterate personas.
		foreach ( Boswell_Persona::get_all() as $p ) {
			wp_clear_scheduled_hook( self::HOOK_NAME, array( $p['id'] ) );
		}
		// Also clear any orphaned events (e.g. deleted personas).
		wp_clear_scheduled_hook( self::HOOK_NAME );
	}

	/**
	 * Cron handler — runs one comment cycle for a persona.
	 *
	 * @param string $persona_id Persona ID.
	 */
	public static function handle( string $persona_id ): void {
		$result = self::run( $persona_id );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cron context logging.
			error_log( sprintf( 'Boswell cron [%s]: %s', $persona_id, $result->get_error_message() ) );
		}
	}

	/**
	 * Execute one comment cycle: select a post and comment on it.
	 *
	 * @param string $persona_id The persona to comment as.
	 * @return WP_Comment|WP_Error The posted comment or error.
	 */
	public static function run( string $persona_id ) {
		if ( empty( $persona_id ) ) {
			return new WP_Error( 'boswell_no_persona', __( 'No persona specified.', 'boswell' ) );
		}

		$persona = Boswell_Persona::get( $persona_id );
		if ( ! $persona ) {
			return new WP_Error( 'boswell_persona_not_found', __( 'Persona not found.', 'boswell' ) );
		}

		// Strategy-based selection.
		$result  = Boswell_Strategy_Selector::select( $persona );
		$post_id = $result['post_id'];
		$context = $result['context'];

		if ( empty( $post_id ) ) {
			return new WP_Error( 'boswell_no_post', __( 'No eligible post found to comment on.', 'boswell' ) );
		}

		return Boswell_Commenter::comment( $post_id, $persona_id, 0, $context );
	}

	/**
	 * Clean up on uninstall.
	 */
	public static function uninstall(): void {
		self::unschedule();
	}
}
