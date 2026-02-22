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
	 * Register the cron handler and default post selection filter.
	 */
	public static function init(): void {
		add_action( self::HOOK_NAME, array( __CLASS__, 'handle' ) );
		add_filter( 'boswell_select_post', array( __CLASS__, 'select_post' ), 10, 2 );
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

		/**
		 * Select a post to comment on.
		 *
		 * @param int                  $post_id Default 0 (no post).
		 * @param array<string, mixed> $persona Persona data.
		 * @return int Post ID, or 0 if none found.
		 */
		$post_id = apply_filters( 'boswell_select_post', 0, $persona );

		if ( empty( $post_id ) ) {
			return new WP_Error( 'boswell_no_post', __( 'No eligible post found to comment on.', 'boswell' ) );
		}

		return Boswell_Commenter::comment( $post_id, $persona_id );
	}

	/**
	 * Default post selection: random recent post not yet commented by the persona's user.
	 *
	 * @param int                  $post_id Current post ID (0 if none selected yet).
	 * @param array<string, mixed> $persona Persona data.
	 * @return int Post ID.
	 */
	public static function select_post( int $post_id, array $persona ): int {
		// If another filter already selected a post, don't override.
		if ( $post_id > 0 ) {
			return $post_id;
		}

		global $wpdb;

		$user_id = (int) $persona['user_id'];

		// Get recent published post IDs not commented by this user.
		// Uses post_date_gmt + UTC_TIMESTAMP() for timezone-safe comparison.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off selection query.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = 'post'
				AND p.post_status = 'publish'
				AND p.post_date_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 90 DAY )
				AND p.ID NOT IN (
					SELECT DISTINCT comment_post_ID FROM {$wpdb->comments}
					WHERE user_id = %d
				)
				ORDER BY RAND()
				LIMIT 1",
				$user_id
			)
		);

		return ! empty( $results ) ? (int) $results[0] : 0;
	}

	/**
	 * Clean up on uninstall.
	 */
	public static function uninstall(): void {
		self::unschedule();
	}
}
