<?php
/**
 * Boswell Commenter
 *
 * Core logic for generating AI-powered comments on blog posts.
 *
 * @package Boswell
 */

/**
 * Generates and posts AI comments as a persona.
 */
class Boswell_Commenter {

	/**
	 * Generate and post a comment on a given post as a given persona.
	 *
	 * @param int    $post_id    The post ID to comment on.
	 * @param string $persona_id The persona ID to comment as.
	 * @return WP_Comment|WP_Error The comment object on success, WP_Error on failure.
	 */
	public static function comment( int $post_id, string $persona_id ) {
		if ( ! class_exists( 'WordPress\AI_Client\AI_Client' ) ) {
			return new WP_Error( 'boswell_ai_client_missing', __( 'wp-ai-client is not available.', 'boswell' ) );
		}

		// 1. Resolve persona.
		$persona = Boswell_Persona::get( $persona_id );
		if ( ! $persona ) {
			return new WP_Error( 'boswell_persona_not_found', __( 'Persona not found.', 'boswell' ) );
		}

		// 2. Resolve post.
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'boswell_post_not_found', __( 'Published post not found.', 'boswell' ) );
		}

		// 3. Resolve user.
		$user = get_userdata( $persona['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'boswell_user_not_found', __( 'Persona user not found.', 'boswell' ) );
		}

		// 4. Build system instruction (persona + memory).
		$system = self::build_system_instruction( $persona );

		// 5. Build user prompt (post content + existing comments).
		$prompt = self::build_prompt( $post );

		// 6. Generate comment text via AI.
		try {
			$comment_text = WordPress\AI_Client\AI_Client::prompt( $prompt )
				->using_provider( $persona['provider'] )
				->using_system_instruction( $system )
				->using_max_tokens( 1500 )
				->generate_text();
		} catch ( \Exception $e ) {
			return new WP_Error( 'boswell_generation_failed', $e->getMessage() );
		}

		$comment_text = trim( $comment_text );
		if ( empty( $comment_text ) ) {
			return new WP_Error( 'boswell_empty_comment', __( 'AI returned an empty comment.', 'boswell' ) );
		}

		// 7. Insert comment as the persona's linked user.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post->ID,
				'comment_content'      => $comment_text,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => $user->user_url,
				'user_id'              => $user->ID,
				'comment_approved'     => 1,
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'boswell_insert_failed', __( 'Failed to insert comment.', 'boswell' ) );
		}

		// 8. Update memory.
		$title = $post->post_title;
		Boswell_Memory::append_entry(
			'recent_activities',
			sprintf( 'Commented on "%s" (post #%d)', $title, $post->ID )
		);
		Boswell_Memory::append_entry(
			'commentary_log',
			sprintf(
				'Post #%d "%s": %s',
				$post->ID,
				$title,
				mb_strimwidth( $comment_text, 0, 100, '...' )
			)
		);

		return get_comment( $comment_id );
	}

	/**
	 * Build the system instruction from persona definition and memory.
	 *
	 * @param array<string, mixed> $persona Persona data.
	 * @return string
	 */
	private static function build_system_instruction( array $persona ): string {
		$parts = array( $persona['persona'] );

		$memory = Boswell_Memory::get();
		if ( ! empty( $memory ) ) {
			$parts[] = "---\n\n## Your Memory\n\n" . $memory;
		}

		$parts[] = "---\n\n## Instructions\n\n"
			. "You are about to read a blog post. Write a comment on it as this persona.\n"
			. "- Write naturally in the persona's voice and language style.\n"
			. "- Reference your memory if relevant, but don't force it.\n"
			. "- Keep the comment concise (1-3 paragraphs).\n"
			. '- Do NOT include any metadata, headers, or labels — just the comment text.';

		return implode( "\n\n", $parts );
	}

	/**
	 * Build the user prompt from post content and existing comments.
	 *
	 * @param WP_Post $post The post object.
	 * @return string
	 */
	private static function build_prompt( WP_Post $post ): string {
		$content = wp_strip_all_tags( $post->post_content );
		// Truncate very long posts to stay within reasonable token limits.
		$content = mb_strimwidth( $content, 0, 5000, '...' );

		$parts = array(
			sprintf( "# %s\n\n%s", $post->post_title, $content ),
		);

		// Include existing comments for context.
		$comments = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'number'  => 10,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			)
		);

		if ( ! empty( $comments ) ) {
			$parts[] = "\n---\n\n## Existing Comments\n";
			foreach ( $comments as $c ) {
				$parts[] = sprintf(
					"**%s** (%s):\n%s\n",
					$c->comment_author,
					$c->comment_date,
					wp_strip_all_tags( $c->comment_content )
				);
			}
		}

		return implode( "\n", $parts );
	}
}
