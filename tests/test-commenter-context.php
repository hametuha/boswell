<?php
/**
 * Tests for Boswell_Commenter context and safety valve
 *
 * @package Boswell
 */

class Test_Boswell_Commenter_Context extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Boswell_Persona::OPTION_KEY );
		remove_all_filters( 'boswell_should_comment' );
		remove_all_filters( 'boswell_post_context' );
	}

	/**
	 * Create a test persona.
	 *
	 * @return array{persona_id: string, user_id: int}
	 */
	private function create_test_persona(): array {
		$user_id = self::factory()->user->create();
		$id      = Boswell_Persona::save(
			array(
				'name'     => 'Test Persona',
				'persona'  => 'A test persona.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);
		return array(
			'persona_id' => $id,
			'user_id'    => $user_id,
		);
	}

	public function test_should_comment_filter_blocks_comment(): void {
		$setup   = $this->create_test_persona();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$filter_called = false;
		add_filter(
			'boswell_should_comment',
			function ( $should, $post, $persona ) use ( &$filter_called ) {
				$filter_called = true;
				return false;
			},
			10,
			3
		);

		$result = Boswell_Commenter::comment( $post_id, $setup['persona_id'] );

		$this->assertTrue( $filter_called );
		$this->assertWPError( $result );
		$this->assertSame( 'boswell_comment_blocked', $result->get_error_code() );
	}

	public function test_should_comment_filter_receives_correct_args(): void {
		$setup   = $this->create_test_persona();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$received_post    = null;
		$received_persona = null;
		add_filter(
			'boswell_should_comment',
			function ( $should, $post, $persona ) use ( &$received_post, &$received_persona ) {
				$received_post    = $post;
				$received_persona = $persona;
				return false; // Block to prevent AI call.
			},
			10,
			3
		);

		Boswell_Commenter::comment( $post_id, $setup['persona_id'] );

		$this->assertInstanceOf( WP_Post::class, $received_post );
		$this->assertSame( $post_id, $received_post->ID );
		$this->assertIsArray( $received_persona );
		$this->assertSame( $setup['persona_id'], $received_persona['id'] );
	}

	public function test_should_comment_default_allows(): void {
		$setup   = $this->create_test_persona();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Without the filter returning false, comment() proceeds past the safety valve.
		// It will fail at the AI client step (not installed in tests), but the error
		// should NOT be 'boswell_comment_blocked'.
		$result = Boswell_Commenter::comment( $post_id, $setup['persona_id'] );

		$this->assertWPError( $result );
		$this->assertNotSame( 'boswell_comment_blocked', $result->get_error_code() );
	}
}
