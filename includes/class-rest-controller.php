<?php
/**
 * Boswell REST Controller
 *
 * Exposes Boswell's memory via REST API for external access
 * (e.g., from the MCP server via Application Passwords).
 *
 * @package Boswell
 */

/**
 * REST API controller for memory endpoints.
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
				__( 'You do not have permission to access Boswell memory.', 'boswell' ),
				array( 'status' => 403 )
			);
		}
		return true;
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
				'persona'    => Boswell_Settings::get_persona(),
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
