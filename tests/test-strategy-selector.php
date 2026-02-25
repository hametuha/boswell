<?php
/**
 * Tests for Boswell_Strategy_Selector
 *
 * @package Boswell
 */

class Test_Boswell_Strategy_Selector extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'boswell_comment_strategies' );
		remove_all_filters( 'boswell_select_post_args' );
		remove_all_filters( 'boswell_post_context' );
	}

	// --- get_strategies() ---

	public function test_default_strategies_contains_recent(): void {
		$strategies = Boswell_Strategy_Selector::get_strategies();
		$this->assertCount( 1, $strategies );
		$this->assertSame( 'recent', $strategies[0]['id'] );
		$this->assertArrayHasKey( 'query_args', $strategies[0] );
		$this->assertArrayHasKey( 'hint', $strategies[0] );
	}

	public function test_strategies_filter_adds_custom(): void {
		add_filter(
			'boswell_comment_strategies',
			function ( $s ) {
				$s[] = array(
					'id'         => 'custom',
					'weight'     => 1,
					'query_args' => array(),
					'hint'       => 'Custom.',
				);
				return $s;
			}
		);

		$strategies = Boswell_Strategy_Selector::get_strategies();
		$this->assertCount( 2, $strategies );
		$this->assertSame( 'custom', $strategies[1]['id'] );
	}

	// --- pick_weighted() ---

	public function test_pick_weighted_single_strategy(): void {
		$strategies = array(
			array(
				'id'     => 'only',
				'weight' => 1,
			),
		);
		$picked     = Boswell_Strategy_Selector::pick_weighted( $strategies );
		$this->assertSame( 'only', $picked['id'] );
	}

	public function test_pick_weighted_respects_weights(): void {
		$strategies = array(
			array(
				'id'     => 'heavy',
				'weight' => 100,
			),
			array(
				'id'     => 'light',
				'weight' => 1,
			),
		);
		$counts     = array(
			'heavy' => 0,
			'light' => 0,
		);
		for ( $i = 0; $i < 200; $i++ ) {
			$picked = Boswell_Strategy_Selector::pick_weighted( $strategies );
			++$counts[ $picked['id'] ];
		}
		$this->assertGreaterThan( 150, $counts['heavy'] );
	}

	// --- select() ---

	public function test_select_returns_recent_post(): void {
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s' ) ) );
		$persona = array( 'user_id' => $user_id );

		$result = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'recent', $result['context']['strategy_id'] );
	}

	public function test_select_excludes_commented_posts(): void {
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s' ) ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => $user_id,
			)
		);
		$persona = array( 'user_id' => $user_id );

		$result = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( 0, $result['post_id'] );
	}

	public function test_select_ignores_old_posts_with_default_strategy(): void {
		$user_id = self::factory()->user->create();
		self::factory()->post->create(
			array( 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '-120 days' ) ) )
		);
		$persona = array( 'user_id' => $user_id );

		$result = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( 0, $result['post_id'] );
	}

	public function test_select_returns_zero_when_no_strategies(): void {
		add_filter( 'boswell_comment_strategies', '__return_empty_array' );

		$persona = array( 'user_id' => 1 );
		$result  = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertEmpty( $result['context'] );
	}

	// --- boswell_select_post_args filter ---

	public function test_select_post_args_filter_can_exclude_category(): void {
		$user_id = self::factory()->user->create();
		$cat_id  = self::factory()->category->create( array( 'slug' => 'no-ai' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_date'     => gmdate( 'Y-m-d H:i:s' ),
				'post_category' => array( $cat_id ),
			)
		);

		add_filter(
			'boswell_select_post_args',
			function ( $args ) use ( $cat_id ) {
				$args['category__not_in'] = array( $cat_id );
				return $args;
			},
			10,
			3
		);

		$persona = array( 'user_id' => $user_id );
		$result  = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( 0, $result['post_id'] );
	}

	// --- boswell_post_context filter ---

	public function test_post_context_filter_adds_notes(): void {
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s' ) ) );

		add_filter(
			'boswell_post_context',
			function ( $context ) {
				$context['notes'][] = 'Test note';
				return $context;
			},
			10,
			3
		);

		$persona = array( 'user_id' => $user_id );
		$result  = Boswell_Strategy_Selector::select( $persona );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertContains( 'Test note', $result['context']['notes'] );
	}

	public function test_context_contains_strategy_hint(): void {
		$user_id = self::factory()->user->create();
		self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s' ) ) );
		$persona = array( 'user_id' => $user_id );

		$result = Boswell_Strategy_Selector::select( $persona );
		$this->assertArrayHasKey( 'strategy_hint', $result['context'] );
		$this->assertNotEmpty( $result['context']['strategy_hint'] );
	}
}
