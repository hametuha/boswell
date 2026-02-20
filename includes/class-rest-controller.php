<?php
/**
 * Boswell REST Controller
 *
 * Exposes Boswell's personas and memory via REST API for external access
 * (e.g., from the MCP server via Application Passwords).
 *
 * @package Boswell
 */

/**
 * REST API controller for Boswell endpoints.
 */
class Boswell_REST_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'boswell/v1';
		$this->rest_base = 'memory';
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /personas
		register_rest_route(
			$this->namespace,
			'/personas',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_personas' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// GET /personas/{id}
		register_rest_route(
			$this->namespace,
			'/personas/(?P<id>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_persona' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /ping
		register_rest_route(
			$this->namespace,
			'/ping',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ping' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'persona_id' => array(
							'type'              => 'string',
							'description'       => 'Persona ID to test with.',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET/PUT /memory
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_memory' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_memory' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'memory' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Full memory content as Markdown.',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		// POST /memory/entry
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/entry',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'append_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'section' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Section key: ' . implode( ', ', array_keys( Boswell_Memory::SECTIONS ) ),
							'enum'              => array_keys( Boswell_Memory::SECTIONS ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'entry'   => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Entry text (date prefix is added automatically).',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check: require manage_options capability.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Boswell.', 'boswell' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /personas — List all personas.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_personas( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Boswell_Persona::get_all() );
	}

	/**
	 * GET /personas/{id} — Get a single persona.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_persona( WP_REST_Request $request ) {
		$persona = Boswell_Persona::get( $request->get_param( 'id' ) );
		if ( ! $persona ) {
			return new WP_Error(
				'boswell_persona_not_found',
				__( 'Persona not found.', 'boswell' ),
				array( 'status' => 404 )
			);
		}
		return new WP_REST_Response( $persona );
	}

	/**
	 * POST /ping — Test AI connectivity with a persona.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ping( WP_REST_Request $request ) {
		if ( ! class_exists( 'WordPress\AI_Client\AI_Client' ) ) {
			return new WP_Error(
				'boswell_ai_client_missing',
				__( 'wp-ai-client is not available.', 'boswell' ),
				array( 'status' => 500 )
			);
		}

		$persona_id = $request->get_param( 'persona_id' );
		$persona    = Boswell_Persona::get( $persona_id );
		if ( ! $persona ) {
			return new WP_Error(
				'boswell_persona_not_found',
				__( 'Persona not found.', 'boswell' ),
				array( 'status' => 404 )
			);
		}

		try {
			$text = WordPress\AI_Client\AI_Client::prompt( 'Introduce yourself in one sentence.' )
				->using_provider( $persona['provider'] )
				->using_system_instruction( $persona['persona'] )
				->using_max_tokens( 200 )
				->generate_text();
		} catch ( \Exception $e ) {
			return new WP_Error(
				'boswell_ping_failed',
				$e->getMessage(),
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'provider' => $persona['provider'],
				'response' => $text,
			)
		);
	}

	/**
	 * GET /memory — Return full memory.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_memory( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'memory'     => Boswell_Memory::get(),
				'updated_at' => Boswell_Memory::get_updated_at(),
				'sections'   => array_keys( Boswell_Memory::SECTIONS ),
			)
		);
	}

	/**
	 * PUT /memory — Replace full memory.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_memory( WP_REST_Request $request ) {
		$markdown = $request->get_param( 'memory' );

		if ( ! Boswell_Memory::update( $markdown ) ) {
			return new WP_Error(
				'boswell_update_failed',
				__( 'Failed to update memory.', 'boswell' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'memory'     => Boswell_Memory::get(),
				'updated_at' => Boswell_Memory::get_updated_at(),
			)
		);
	}

	/**
	 * POST /memory/entry — Append an entry to a section.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function append_entry( WP_REST_Request $request ) {
		$section = $request->get_param( 'section' );
		$entry   = $request->get_param( 'entry' );

		if ( ! Boswell_Memory::append_entry( $section, $entry ) ) {
			return new WP_Error(
				'boswell_append_failed',
				__( 'Failed to append entry.', 'boswell' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'memory'     => Boswell_Memory::get(),
				'updated_at' => Boswell_Memory::get_updated_at(),
				'section'    => $section,
			)
		);
	}
}
