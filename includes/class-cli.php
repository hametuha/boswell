<?php
/**
 * Boswell WP-CLI Commands
 *
 * @package Boswell
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Boswell AI commenting commands.
 */
class Boswell_CLI extends WP_CLI_Command {

	/**
	 * Generate an AI comment on a post as a persona.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to comment on.
	 *
	 * --persona=<persona_id>
	 * : The persona ID to use.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell comment 42 --persona=persona
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function comment( array $args, array $assoc_args ): void {
		$post_id    = (int) $args[0];
		$persona_id = $assoc_args['persona'];

		WP_CLI::log( sprintf( 'Generating comment for post #%d as "%s"...', $post_id, $persona_id ) );

		$result = Boswell_Commenter::comment( $post_id, $persona_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf( 'Comment #%d posted by %s:', $result->comment_ID, $result->comment_author )
		);
		WP_CLI::log( '' );
		WP_CLI::log( $result->comment_content );
	}

	/**
	 * List all configured personas.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell personas
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function personas( array $args, array $assoc_args ): void {
		$personas = Boswell_Persona::get_all();

		if ( empty( $personas ) ) {
			WP_CLI::warning( 'No personas configured.' );
			return;
		}

		$items = array();
		foreach ( $personas as $p ) {
			$user    = get_userdata( $p['user_id'] );
			$items[] = array(
				'id'       => $p['id'],
				'name'     => $p['name'],
				'user'     => $user ? $user->display_name : '(unknown)',
				'provider' => $p['provider'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'name', 'user', 'provider' ) );
	}
	/**
	 * Show Boswell's memory, narrated by a persona.
	 *
	 * ## OPTIONS
	 *
	 * [--persona=<persona_id>]
	 * : The persona to narrate the report. If omitted, the first persona is used.
	 *
	 * [--raw]
	 * : Output raw memory Markdown instead of the AI-narrated report.
	 *
	 * [--section=<section>]
	 * : Show only a specific section (recent_activities, ongoing_topics, commentary_log, notes). Implies --raw.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell memory
	 *     wp boswell memory --persona=persona
	 *     wp boswell memory --raw
	 *     wp boswell memory --section=commentary_log
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function memory( array $args, array $assoc_args ): void {
		// --section implies raw output.
		if ( ! empty( $assoc_args['section'] ) ) {
			$section = $assoc_args['section'];
			$content = Boswell_Memory::get_section( $section );
			WP_CLI::log( empty( $content ) ? sprintf( 'Section "%s" is empty.', $section ) : $content );
			return;
		}

		// --raw: dump markdown as-is.
		if ( ! empty( $assoc_args['raw'] ) ) {
			WP_CLI::log( Boswell_Memory::get() );
			return;
		}

		// Narrated report via AI.
		$persona = self::resolve_persona( $assoc_args );
		if ( is_wp_error( $persona ) ) {
			WP_CLI::error( $persona->get_error_message() );
		}

		if ( ! class_exists( 'WordPress\AI_Client\AI_Client' ) ) {
			WP_CLI::error( 'wp-ai-client is not available.' );
		}

		$memory = Boswell_Memory::get();
		$system = $persona['persona'];
		$prompt = "Here is your memory log:\n\n" . $memory
			. "\n\n---\n\nBriefly report what you have been up to recently, in your own voice. "
			. 'Be conversational and concise (a few sentences).';

		try {
			$report = WordPress\AI_Client\AI_Client::prompt( $prompt )
				->using_provider( $persona['provider'] )
				->using_system_instruction( $system )
				->using_max_tokens( 500 )
				->generate_text();
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::log( trim( $report ) );
	}

	/**
	 * Resolve a persona from --persona flag or fall back to the first available.
	 *
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function resolve_persona( array $assoc_args ) {
		if ( ! empty( $assoc_args['persona'] ) ) {
			$persona = Boswell_Persona::get( $assoc_args['persona'] );
			if ( ! $persona ) {
				return new WP_Error( 'not_found', sprintf( 'Persona "%s" not found.', $assoc_args['persona'] ) );
			}
			return $persona;
		}

		$all = Boswell_Persona::get_all();
		if ( empty( $all ) ) {
			return new WP_Error( 'no_personas', 'No personas configured. Create one first.' );
		}
		return $all[0];
	}
}

WP_CLI::add_command( 'boswell', 'Boswell_CLI' );
