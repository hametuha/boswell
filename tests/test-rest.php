<?php
/**
 * Tests for Boswell_REST_Controller
 *
 * @package Boswell
 */

class Test_Boswell_REST extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private static int $subscriber_id;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$this->server = rest_get_server();

		delete_option( Boswell_Memory::OPTION_KEY );
		delete_option( Boswell_Memory::UPDATED_AT_KEY );
		delete_option( Boswell_Persona::OPTION_KEY );
	}

	// ─── Context endpoint ─────────────────────────────────────────

	public function test_context_requires_auth(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/boswell/v1/context' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_context_forbidden_for_subscriber(): void {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/boswell/v1/context' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_context_returns_site_info(): void {
		wp_set_current_user( self::$admin_id );

		$request  = new WP_REST_Request( 'GET', '/boswell/v1/context' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'site_name', $data );
		$this->assertArrayHasKey( 'site_url', $data );
		$this->assertArrayHasKey( 'personas', $data );
		$this->assertArrayHasKey( 'memory', $data );
		$this->assertIsArray( $data['personas'] );
		$this->assertIsString( $data['memory'] );
	}

	public function test_context_includes_personas(): void {
		wp_set_current_user( self::$admin_id );

		// Create a persona.
		Boswell_Persona::save(
			array(
				'name'     => 'Test Bot',
				'persona'  => 'I am a test persona.',
				'user_id'  => self::$admin_id,
				'provider' => 'anthropic',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/boswell/v1/context' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['personas'] );
		$this->assertSame( 'Test Bot', $data['personas'][0]['name'] );
		$this->assertSame( 'I am a test persona.', $data['personas'][0]['persona'] );
		// Should not expose internal fields like user_id or provider.
		$this->assertArrayNotHasKey( 'user_id', $data['personas'][0] );
		$this->assertArrayNotHasKey( 'provider', $data['personas'][0] );
	}

	// ─── Comment endpoint ─────────────────────────────────────────

	public function test_comment_requires_auth(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/boswell/v1/comment' );
		$request->set_body_params(
			array(
				'persona_id' => 'test',
				'post_id'    => 1,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_comment_returns_404_for_missing_persona(): void {
		wp_set_current_user( self::$admin_id );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'POST', '/boswell/v1/comment' );
		$request->set_body_params(
			array(
				'persona_id' => 'nonexistent',
				'post_id'    => $post_id,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_comment_returns_404_for_missing_post(): void {
		wp_set_current_user( self::$admin_id );

		Boswell_Persona::save(
			array(
				'name'     => 'Bot',
				'persona'  => 'Test persona.',
				'user_id'  => self::$admin_id,
				'provider' => 'anthropic',
			)
		);

		$request = new WP_REST_Request( 'POST', '/boswell/v1/comment' );
		$request->set_body_params(
			array(
				'persona_id' => 'bot',
				'post_id'    => 999999,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_comment_requires_persona_id_and_post_id(): void {
		wp_set_current_user( self::$admin_id );

		// Missing persona_id.
		$request = new WP_REST_Request( 'POST', '/boswell/v1/comment' );
		$request->set_body_params( array( 'post_id' => 1 ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );

		// Missing post_id.
		$request = new WP_REST_Request( 'POST', '/boswell/v1/comment' );
		$request->set_body_params( array( 'persona_id' => 'test' ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	// ─── Existing endpoints (smoke tests) ─────────────────────────

	public function test_memory_get_returns_200(): void {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/boswell/v1/memory' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_personas_get_returns_200(): void {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/boswell/v1/personas' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}
}
