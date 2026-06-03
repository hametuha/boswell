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
		// Self-heal: re-establish missing schedules on admin requests. The
		// deactivation hook wipes all events, and reactivation/upgrade (e.g. the
		// 1.x -> 2.0 directory-delete migration) does not re-save personas, so
		// without this the automated commenting would stay silent forever.
		add_action( 'admin_init', array( __CLASS__, 'sync_schedules' ) );
	}

	/**
	 * Reconcile cron events with persona settings.
	 *
	 * Idempotent: schedules an event for every cron-enabled persona that is
	 * missing one, and clears events for personas that have cron disabled.
	 * Safe to call on activation and on every admin request, so automated
	 * commenting survives plugin upgrades and reactivation that would otherwise
	 * leave the scheduled events cleared by the deactivation hook.
	 */
	public static function sync_schedules(): void {
		foreach ( Boswell_Persona::get_all() as $persona ) {
			$persona_id = $persona['id'] ?? '';
			if ( '' === $persona_id ) {
				continue;
			}

			$args        = array( $persona_id );
			$is_enabled  = ! empty( $persona['cron_enabled'] );
			$is_schedule = (bool) wp_next_scheduled( self::HOOK_NAME, $args );

			if ( $is_enabled && ! $is_schedule ) {
				$frequency = $persona['cron_frequency'] ?? 'daily';
				if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
					$frequency = 'daily';
				}
				wp_schedule_event( time(), $frequency, self::HOOK_NAME, $args );
			} elseif ( ! $is_enabled && $is_schedule ) {
				wp_clear_scheduled_hook( self::HOOK_NAME, $args );
			}
		}
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
	 * @param string $persona_id  The persona to comment as.
	 * @param string $strategy_id Optional strategy ID. If empty, picks randomly by weight.
	 * @return WP_Comment|WP_Error The posted comment or error.
	 */
	public static function run( string $persona_id, string $strategy_id = '' ) {
		if ( empty( $persona_id ) ) {
			return new WP_Error( 'boswell_no_persona', __( 'No persona specified.', 'boswell' ) );
		}

		$persona = Boswell_Persona::get( $persona_id );
		if ( ! $persona ) {
			return new WP_Error( 'boswell_persona_not_found', __( 'Persona not found.', 'boswell' ) );
		}

		// Strategy-based selection.
		$result  = Boswell_Strategy_Selector::select( $persona, $strategy_id );
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
