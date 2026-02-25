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
	 * [--parent=<comment_id>]
	 * : Reply to this comment instead of posting a top-level comment.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell comment 42 --persona=persona
	 *     wp boswell comment 42 --persona=persona --parent=4
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function comment( array $args, array $assoc_args ): void {
		$post_id    = (int) $args[0];
		$persona_id = $assoc_args['persona'];
		$parent_id  = (int) ( $assoc_args['parent'] ?? 0 );

		if ( $parent_id > 0 ) {
			WP_CLI::log( sprintf( 'Replying to comment #%d on post #%d as "%s"...', $parent_id, $post_id, $persona_id ) );
		} else {
			WP_CLI::log( sprintf( 'Generating comment for post #%d as "%s"...', $post_id, $persona_id ) );
		}

		$result = Boswell_Commenter::comment( $post_id, $persona_id, $parent_id );

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
	 * Auto-select a post and comment on it (same logic as cron).
	 *
	 * If --persona is given, runs for that persona only.
	 * Otherwise, runs for all personas with auto-commenting enabled.
	 *
	 * ## OPTIONS
	 *
	 * [--persona=<persona_id>]
	 * : Run for a specific persona. If omitted, runs all cron-enabled personas.
	 *
	 * [--strategy=<strategy_id>]
	 * : Use a specific strategy. If omitted, picks randomly by weight.
	 * Use `wp boswell strategies` to list available strategies.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell run
	 *     wp boswell run --persona=persona
	 *     wp boswell run --persona=persona --strategy=book_review
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$persona_id  = $assoc_args['persona'] ?? '';
		$strategy_id = $assoc_args['strategy'] ?? '';

		if ( ! empty( $persona_id ) ) {
			self::run_single( $persona_id, $strategy_id );
			return;
		}

		// Run all cron-enabled personas.
		$personas = Boswell_Persona::get_all();
		$enabled  = array_filter( $personas, fn( $p ) => ! empty( $p['cron_enabled'] ) );

		if ( empty( $enabled ) ) {
			WP_CLI::error( 'No personas with auto-commenting enabled. Use --persona or enable cron on a persona.' );
		}

		foreach ( $enabled as $p ) {
			self::run_single( $p['id'], $strategy_id );
		}
	}

	/**
	 * Run auto-comment for a single persona and display the result.
	 *
	 * @param string $persona_id  Persona ID.
	 * @param string $strategy_id Optional strategy ID.
	 */
	private static function run_single( string $persona_id, string $strategy_id = '' ): void {
		if ( ! empty( $strategy_id ) ) {
			WP_CLI::log( sprintf( 'Running auto-comment as "%s" with strategy "%s"...', $persona_id, $strategy_id ) );
		} else {
			WP_CLI::log( sprintf( 'Running auto-comment as "%s"...', $persona_id ) );
		}

		$result = Boswell_Cron::run( $persona_id, $strategy_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( sprintf( '[%s] %s', $persona_id, $result->get_error_message() ) );
			return;
		}

		$post = get_post( $result->comment_post_ID );
		WP_CLI::success(
			sprintf(
				'Comment #%d posted on "%s" (post #%d) by %s:',
				$result->comment_ID,
				$post ? $post->post_title : '(unknown)',
				$result->comment_post_ID,
				$result->comment_author
			)
		);
		WP_CLI::log( '' );
		WP_CLI::log( $result->comment_content );
	}

	/**
	 * List registered comment strategies.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts table, json, csv, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boswell strategies
	 *     wp boswell strategies --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function strategies( array $args, array $assoc_args ): void {
		$strategies = Boswell_Strategy_Selector::get_strategies();

		if ( empty( $strategies ) ) {
			WP_CLI::warning( 'No strategies registered.' );
			return;
		}

		$total = array_sum( array_column( $strategies, 'weight' ) );
		$items = array();
		foreach ( $strategies as $s ) {
			$weight  = max( 1, (int) ( $s['weight'] ?? 1 ) );
			$items[] = array(
				'id'          => $s['id'] ?? '(none)',
				'label'       => $s['label'] ?? '',
				'weight'      => $weight,
				'probability' => $total > 0 ? round( $weight / $total * 100 ) . '%' : '—',
				'hint'        => mb_strimwidth( $s['hint'] ?? '', 0, 60, '...' ),
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'label', 'weight', 'probability', 'hint' ) );
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
