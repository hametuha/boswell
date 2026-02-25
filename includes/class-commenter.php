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
	 * @param int                  $post_id    The post ID to comment on.
	 * @param string               $persona_id The persona ID to comment as.
	 * @param int                  $parent_id  Optional parent comment ID for replies.
	 * @param array<string, mixed> $context    Optional context from strategy selector.
	 * @return WP_Comment|WP_Error The comment object on success, WP_Error on failure.
	 */
	public static function comment( int $post_id, string $persona_id, int $parent_id = 0, array $context = array() ) {
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

		// 4. Safety valve — allows blocking comments on specific posts.
		/**
		 * Filter whether Boswell should comment on this post.
		 *
		 * Applies to ALL paths: cron, MCP, CLI, REST.
		 *
		 * @param bool                 $should  Whether to proceed. Default true.
		 * @param WP_Post              $post    The target post.
		 * @param array<string, mixed> $persona Persona data.
		 */
		$should = apply_filters( 'boswell_should_comment', true, $post, $persona );
		if ( ! $should ) {
			return new WP_Error( 'boswell_comment_blocked', __( 'Commenting on this post was blocked by a filter.', 'boswell' ) );
		}

		// 5. Enrich context for direct calls (MCP, REST, CLI with explicit post_id).
		if ( empty( $context ) ) {
			/**
			 * Filter the post context (direct call path).
			 *
			 * @param array<string, mixed> $context  Default context.
			 * @param WP_Post              $post     The target post.
			 * @param array<string, mixed> $strategy Empty array for direct calls.
			 */
			$context = apply_filters(
				'boswell_post_context',
				array(
					'strategy_id'   => 'direct',
					'strategy_hint' => '',
					'notes'         => array(),
				),
				$post,
				array()
			);
		}

		// 6. Build system instruction (persona + memory).
		$system = self::build_system_instruction( $persona );

		// 7. Resolve parent comment for replies.
		$parent = null;
		if ( $parent_id > 0 ) {
			$parent = get_comment( $parent_id );
			if ( ! $parent || (int) $parent->comment_post_ID !== $post->ID ) {
				return new WP_Error( 'boswell_parent_not_found', __( 'Parent comment not found on this post.', 'boswell' ) );
			}
		}

		// 8. Build user prompt (post content + existing comments + context).
		$prompt = self::build_prompt( $post, $parent, $context );

		// 9. Generate comment text via AI.
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

		// 10. Insert comment as the persona's linked user.
		$comment_data = array(
			'comment_post_ID'      => $post->ID,
			'comment_content'      => $comment_text,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,
			'user_id'              => $user->ID,
			'comment_approved'     => 1,
		);
		if ( $parent ) {
			$comment_data['comment_parent'] = $parent->comment_ID;
		}
		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return new WP_Error( 'boswell_insert_failed', __( 'Failed to insert comment.', 'boswell' ) );
		}

		// 11. Update memory.
		$title = $post->post_title;
		if ( $parent ) {
			Boswell_Memory::append_entry(
				'recent_activities',
				sprintf( 'Replied to %s on "%s" (post #%d)', $parent->comment_author, $title, $post->ID )
			);
			Boswell_Memory::append_entry(
				'commentary_log',
				sprintf(
					'Post #%d "%s" (reply to %s): %s',
					$post->ID,
					$title,
					$parent->comment_author,
					mb_strimwidth( $comment_text, 0, 100, '...' )
				)
			);
		} else {
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
		}

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
			. "You are about to read a blog post. Write a comment (or reply) on it as this persona.\n"
			. "- Write naturally in the persona's voice and language style.\n"
			. "- Reference your memory if relevant, but don't force it.\n"
			. "- Keep the comment concise (1-3 paragraphs).\n"
			. "- If you are replying to someone, address their points directly.\n"
			. '- Do NOT include any metadata, headers, or labels — just the comment text.';

		return implode( "\n\n", $parts );
	}

	/**
	 * Build the user prompt from post content, existing comments, and context.
	 *
	 * @param WP_Post              $post    The post object.
	 * @param WP_Comment|null      $parent  Parent comment when replying, or null.
	 * @param array<string, mixed> $context Context from strategy selector.
	 * @return string
	 */
	private static function build_prompt( WP_Post $post, ?WP_Comment $parent = null, array $context = array() ): string {
		$content = wp_strip_all_tags( $post->post_content );
		// Truncate very long posts to stay within reasonable token limits.
		$content = mb_strimwidth( $content, 0, 5000, '...' );

		// Date information.
		$post_date = get_the_date( 'Y-m-d', $post );
		$elapsed   = human_time_diff( get_post_timestamp( $post ), time() );

		// Categories.
		$categories = get_the_category( $post->ID );
		$cat_names  = ! empty( $categories )
			? implode( ', ', wp_list_pluck( $categories, 'name' ) )
			: '';

		$parts = array();

		// Title + date.
		$parts[] = sprintf(
			"# %s\nPublished: %s (%s)",
			$post->post_title,
			$post_date,
			/* translators: %s: human-readable elapsed time */
			sprintf( __( 'about %s ago', 'boswell' ), $elapsed )
		);

		// Categories.
		if ( ! empty( $cat_names ) ) {
			$parts[] = sprintf( 'Categories: %s', $cat_names );
		}

		// "Why this post" section from context.
		$why_parts = array();
		if ( ! empty( $context['strategy_hint'] ) ) {
			$why_parts[] = $context['strategy_hint'];
		}
		if ( ! empty( $context['notes'] ) ) {
			foreach ( $context['notes'] as $note ) {
				$why_parts[] = '- ' . $note;
			}
		}
		if ( ! empty( $why_parts ) ) {
			$parts[] = "## Why This Post\n\n" . implode( "\n", $why_parts );
		}

		// Content.
		$parts[] = sprintf( "## Content\n\n%s", $content );

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

		if ( $parent ) {
			$parts[] = "\n---\n\n## You Are Replying To\n";
			$parts[] = sprintf(
				"**%s** (%s):\n%s\n\nWrite your reply to this comment.",
				$parent->comment_author,
				$parent->comment_date,
				wp_strip_all_tags( $parent->comment_content )
			);
		}

		return implode( "\n", $parts );
	}
}
