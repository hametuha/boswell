<?php
/**
 * Boswell Persona
 *
 * CRUD for AI personas stored as a JSON array in wp_options.
 *
 * @package Boswell
 */

/**
 * Persona storage and management.
 */
class Boswell_Persona {

	const OPTION_KEY = 'boswell_personas';

	const OLD_OPTION_KEY = 'boswell_persona';

	const PROVIDERS = array( 'anthropic', 'openai', 'google' );

	/**
	 * Get all personas.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		$personas = get_option( self::OPTION_KEY, array() );
		return is_array( $personas ) ? $personas : array();
	}

	/**
	 * Get a single persona by ID.
	 *
	 * @param string $id Persona slug ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $persona ) {
			if ( $persona['id'] === $id ) {
				return $persona;
			}
		}
		return null;
	}

	/**
	 * Save (create or update) a persona.
	 *
	 * If $data contains an 'id' that already exists, the persona is updated.
	 * Otherwise a new persona is created with a generated ID.
	 *
	 * @param array<string, mixed> $data Persona fields: name, persona, user_id, provider, and optionally id.
	 * @return string|WP_Error The persona ID on success, WP_Error on failure.
	 */
	public static function save( array $data ) {
		$valid = self::validate( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$personas = self::get_all();
		$id       = ! empty( $data['id'] ) ? $data['id'] : '';

		// Check if updating an existing persona.
		$index = self::find_index( $id, $personas );

		if ( false !== $index ) {
			// Update existing.
			$personas[ $index ] = self::normalize( $data, $id );
		} else {
			// Create new.
			$id         = self::generate_id( $data['name'], $personas );
			$data['id'] = $id;
			$personas[] = self::normalize( $data, $id );
		}

		update_option( self::OPTION_KEY, $personas, false );

		// Reschedule cron for this persona.
		Boswell_Cron::reschedule( $id );

		return $id;
	}

	/**
	 * Delete a persona by ID.
	 *
	 * @param string $id Persona slug ID.
	 * @return bool True if deleted, false if not found.
	 */
	public static function delete( string $id ): bool {
		$personas = self::get_all();
		$index    = self::find_index( $id, $personas );

		if ( false === $index ) {
			return false;
		}

		array_splice( $personas, $index, 1 );
		update_option( self::OPTION_KEY, $personas, false );

		// Remove cron schedule for deleted persona.
		Boswell_Cron::unschedule( $id );

		return true;
	}

	/**
	 * Generate a unique slug ID from a persona name.
	 *
	 * @param string                           $name     Persona display name.
	 * @param array<int, array<string, mixed>> $personas Existing personas (to check for duplicates).
	 * @return string
	 */
	public static function generate_id( string $name, array $personas = array() ): string {
		$base = sanitize_title( $name );

		// sanitize_title() produces percent-encoded slugs for non-ASCII names (e.g. Japanese).
		// These break when passed through sanitize_text_field() which strips %XX sequences.
		// Fall back to a generic "persona-N" ID in that case.
		if ( empty( $base ) || str_contains( $base, '%' ) ) {
			$base = 'persona';
		}

		$candidate    = $base;
		$suffix       = 2;
		$existing_ids = array_column( $personas, 'id' );

		while ( in_array( $candidate, $existing_ids, true ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Validate persona data.
	 *
	 * @param array<string, mixed> $data Persona fields.
	 * @return true|WP_Error
	 */
	public static function validate( array $data ) {
		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Persona name is required.', 'boswell' ) );
		}

		if ( empty( $data['persona'] ) ) {
			return new WP_Error( 'missing_persona', __( 'Persona definition is required.', 'boswell' ) );
		}

		if ( empty( $data['user_id'] ) || ! get_userdata( (int) $data['user_id'] ) ) {
			return new WP_Error( 'invalid_user', __( 'A valid WordPress user must be selected.', 'boswell' ) );
		}

		if ( empty( $data['provider'] ) || ! in_array( $data['provider'], self::PROVIDERS, true ) ) {
			return new WP_Error( 'invalid_provider', __( 'A valid provider must be selected.', 'boswell' ) );
		}

		return true;
	}

	/**
	 * Migrate from the legacy single-persona option to the new array format.
	 *
	 * Runs on activation. If the old option exists and the new one is empty,
	 * creates a persona entry from the old data.
	 */
	public static function migrate(): void {
		$personas = self::get_all();

		// Fix any existing personas with percent-encoded IDs.
		$dirty = false;
		foreach ( $personas as $index => $persona ) {
			if ( str_contains( $persona['id'], '%' ) ) {
				$personas[ $index ]['id'] = self::generate_id( $persona['name'], $personas );
				$dirty                    = true;
			}
		}
		if ( $dirty ) {
			update_option( self::OPTION_KEY, $personas, false );
		}

		// Migrate legacy single-persona option to the new array format.
		$old_persona = get_option( self::OLD_OPTION_KEY, '' );
		if ( empty( $old_persona ) || ! empty( $personas ) ) {
			return;
		}

		$admin_user = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		$user_id = ! empty( $admin_user ) ? (int) $admin_user[0] : 1;

		$personas = array(
			array(
				'id'       => 'default',
				'name'     => 'Default',
				'persona'  => $old_persona,
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			),
		);

		update_option( self::OPTION_KEY, $personas, false );
	}

	/**
	 * Delete all persona data. Used on uninstall.
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::OLD_OPTION_KEY );
	}

	/**
	 * Find the index of a persona by ID within a list.
	 *
	 * @param string                           $id       Persona ID.
	 * @param array<int, array<string, mixed>> $personas List of personas.
	 * @return int|false
	 */
	private static function find_index( string $id, array $personas ) {
		if ( empty( $id ) ) {
			return false;
		}
		foreach ( $personas as $index => $persona ) {
			if ( $persona['id'] === $id ) {
				return $index;
			}
		}
		return false;
	}

	/**
	 * Normalize persona data into a consistent shape.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @param string               $id   Persona ID.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $data, string $id ): array {
		$frequency = $data['cron_frequency'] ?? 'daily';

		return array(
			'id'             => $id,
			'name'           => sanitize_text_field( $data['name'] ),
			'persona'        => sanitize_textarea_field( $data['persona'] ),
			'user_id'        => (int) $data['user_id'],
			'provider'       => sanitize_text_field( $data['provider'] ),
			'cron_enabled'   => ! empty( $data['cron_enabled'] ),
			'cron_frequency' => in_array( $frequency, Boswell_Cron::FREQUENCIES, true ) ? $frequency : 'daily',
		);
	}
}
