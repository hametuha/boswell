<?php
/**
 * Boswell Strategy Selector
 *
 * Selects a post using weighted-random strategy picking and WP_Query.
 *
 * @package Boswell
 */

/**
 * Strategy-based post selection for automatic commenting.
 */
class Boswell_Strategy_Selector {

	/**
	 * Select a post for a persona using the strategy system.
	 *
	 * @param array<string, mixed> $persona     Persona data.
	 * @param string               $strategy_id Optional strategy ID. If empty, picks randomly by weight.
	 * @return array{post_id: int, context: array<string, mixed>}
	 */
	public static function select( array $persona, string $strategy_id = '' ): array {
		$strategies = self::get_strategies();

		if ( empty( $strategies ) ) {
			return array(
				'post_id' => 0,
				'context' => array(),
			);
		}

		// Pick a specific strategy or random by weight.
		$strategy = null;
		if ( ! empty( $strategy_id ) ) {
			foreach ( $strategies as $s ) {
				if ( ( $s['id'] ?? '' ) === $strategy_id ) {
					$strategy = $s;
					break;
				}
			}
			if ( ! $strategy ) {
				return array(
					'post_id' => 0,
					'context' => array(),
				);
			}
		} else {
			$strategy = self::pick_weighted( $strategies );
		}
		$args = self::build_query_args( $strategy );

		/**
		 * Filter the WP_Query arguments before execution.
		 *
		 * @param array<string, mixed> $args     WP_Query arguments.
		 * @param array<string, mixed> $strategy The selected strategy.
		 * @param array<string, mixed> $persona  Persona data.
		 */
		$args = apply_filters( 'boswell_select_post_args', $args, $strategy, $persona );

		// Inject exclusion AFTER the filter so it cannot be removed.
		$args = self::exclude_commented_posts( $args, $persona );

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return array(
				'post_id' => 0,
				'context' => array(),
			);
		}

		$post    = $query->posts[0];
		$context = self::build_context( $post, $strategy );

		return array(
			'post_id' => $post->ID,
			'context' => $context,
		);
	}

	/**
	 * Get all strategies (default + filtered).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_strategies(): array {
		$defaults = array(
			array(
				'id'         => 'recent',
				'label'      => __( 'Recent posts', 'boswell' ),
				'weight'     => 1,
				'query_args' => array(
					'date_query' => array( array( 'after' => '90 days ago' ) ),
					'orderby'    => 'rand',
				),
				'hint'       => __( 'A recently published post.', 'boswell' ),
			),
		);

		/**
		 * Filter available comment strategies.
		 *
		 * Each strategy is an array with:
		 *   - id         (string) Unique identifier.
		 *   - label      (string) Human-readable label.
		 *   - weight     (int)    Selection weight (higher = more likely).
		 *   - query_args (array)  WP_Query arguments.
		 *   - hint       (string) Context hint passed to the AI prompt.
		 *
		 * @param array<int, array<string, mixed>> $strategies Default strategies.
		 */
		return apply_filters( 'boswell_comment_strategies', $defaults );
	}

	/**
	 * Pick a strategy using weighted random selection.
	 *
	 * @param array<int, array<string, mixed>> $strategies Available strategies.
	 * @return array<string, mixed> The chosen strategy.
	 */
	public static function pick_weighted( array $strategies ): array {
		$total = array_sum( array_column( $strategies, 'weight' ) );
		$roll  = wp_rand( 1, max( 1, $total ) );

		$cumulative = 0;
		foreach ( $strategies as $strategy ) {
			$cumulative += max( 1, (int) ( $strategy['weight'] ?? 1 ) );
			if ( $roll <= $cumulative ) {
				return $strategy;
			}
		}

		return $strategies[0];
	}

	/**
	 * Build WP_Query arguments from a strategy.
	 *
	 * @param array<string, mixed> $strategy Strategy data.
	 * @return array<string, mixed>
	 */
	private static function build_query_args( array $strategy ): array {
		$args = $strategy['query_args'] ?? array();

		$args['post_type']      = 'post';
		$args['post_status']    = 'publish';
		$args['posts_per_page'] = 1;

		return $args;
	}

	/**
	 * Inject post__not_in for posts already commented by the persona's user.
	 *
	 * Runs AFTER boswell_select_post_args to ensure it cannot be removed.
	 *
	 * @param array<string, mixed> $args    WP_Query arguments.
	 * @param array<string, mixed> $persona Persona data.
	 * @return array<string, mixed>
	 */
	private static function exclude_commented_posts( array $args, array $persona ): array {
		global $wpdb;

		$user_id = (int) $persona['user_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off selection query.
		$commented_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT comment_post_ID FROM {$wpdb->comments} WHERE user_id = %d",
				$user_id
			)
		);

		if ( ! empty( $commented_ids ) ) {
			$existing             = $args['post__not_in'] ?? array();
			$args['post__not_in'] = array_merge( $existing, array_map( 'intval', $commented_ids ) );
		}

		return $args;
	}

	/**
	 * Build post context from strategy hint and filter.
	 *
	 * @param WP_Post              $post     The selected post.
	 * @param array<string, mixed> $strategy The strategy that selected it.
	 * @return array<string, mixed>
	 */
	private static function build_context( WP_Post $post, array $strategy ): array {
		$context = array(
			'strategy_id'   => $strategy['id'] ?? 'unknown',
			'strategy_hint' => $strategy['hint'] ?? '',
			'notes'         => array(),
		);

		/**
		 * Filter the post context after selection.
		 *
		 * Use this to add background information (e.g., page views,
		 * editorial notes) that will appear in the AI prompt.
		 *
		 * @param array<string, mixed> $context  Context data with strategy_id, strategy_hint, notes.
		 * @param WP_Post              $post     The selected post.
		 * @param array<string, mixed> $strategy The strategy used.
		 */
		return apply_filters( 'boswell_post_context', $context, $post, $strategy );
	}
}
