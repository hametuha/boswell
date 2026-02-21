<?php
/**
 * Boswell Abilities
 *
 * Registers Boswell capabilities via the WordPress Abilities API
 * so they are exposed as MCP tools, resources, and prompts.
 *
 * @package Boswell
 */

/**
 * Abilities registration for the MCP adapter.
 */
class Boswell_Abilities {

	/**
	 * Register all abilities on the appropriate hooks.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the Boswell ability category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'boswell',
			array(
				'label'       => __( 'Boswell', 'boswell' ),
				'description' => __( 'AI-powered commenting and content management.', 'boswell' ),
			)
		);
	}

	/**
	 * Register all Boswell abilities.
	 */
	public static function register(): void {
		self::register_comment_tool();
		self::register_context_resource();
		self::register_memory_tool();
		self::register_add_memory_tool();
		self::register_write_prompt();
	}

	/**
	 * Tool: Generate an AI comment on a post as a persona.
	 */
	private static function register_comment_tool(): void {
		wp_register_ability(
			'boswell/comment',
			array(
				'label'               => __( 'Generate AI Comment', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Generate and post an AI comment on a blog post as a specific persona.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'persona_id' => array(
							'type'        => 'string',
							'description' => __( 'Persona ID to comment as.', 'boswell' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Post ID to comment on.', 'boswell' ),
							'minimum'     => 1,
						),
						'parent_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Parent comment ID for replies (0 = top-level).', 'boswell' ),
							'default'     => 0,
						),
					),
					'required'   => array( 'persona_id', 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => __( 'The created comment ID.', 'boswell' ),
						),
						'content'    => array(
							'type'        => 'string',
							'description' => __( 'The generated comment text.', 'boswell' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'boswell' ),
						),
						'author'     => array(
							'type'        => 'string',
							'description' => __( 'Comment author name.', 'boswell' ),
						),
					),
				),
				'execute_callback'    => function ( array $input ) {
					$post_id    = (int) ( $input['post_id'] ?? 0 );
					$persona_id = $input['persona_id'] ?? '';
					$parent_id  = (int) ( $input['parent_id'] ?? 0 );

					$result = Boswell_Commenter::comment( $post_id, $persona_id, $parent_id );
					if ( is_wp_error( $result ) ) {
						throw new \Exception( esc_html( $result->get_error_message() ) );
					}

					return array(
						'comment_id' => (int) $result->comment_ID,
						'content'    => $result->comment_content,
						'post_id'    => (int) $result->comment_post_ID,
						'author'     => $result->comment_author,
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Resource: Site context (personas + memory).
	 */
	private static function register_context_resource(): void {
		wp_register_ability(
			'boswell/context',
			array(
				'label'               => __( 'Site Context', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Site info, all personas with style guides, and shared memory.', 'boswell' ),
				'execute_callback'    => function () {
					$personas = array_map(
						function ( array $p ): array {
							return array(
								'id'      => $p['id'],
								'name'    => $p['name'],
								'persona' => $p['persona'],
							);
						},
						Boswell_Persona::get_all()
					);

					return array(
						'site_name' => get_bloginfo( 'name' ),
						'site_url'  => home_url(),
						'personas'  => $personas,
						'memory'    => Boswell_Memory::get(),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'uri'         => 'boswell://context',
					'annotations' => array(
						'audience' => array( 'user', 'assistant' ),
						'priority' => 0.9,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);
	}

	/**
	 * Tool: Get memory.
	 */
	private static function register_memory_tool(): void {
		wp_register_ability(
			'boswell/get-memory',
			array(
				'label'               => __( 'Get Memory', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Retrieve Boswell shared memory (Markdown format).', 'boswell' ),
				'execute_callback'    => function () {
					return array(
						'memory'     => Boswell_Memory::get(),
						'updated_at' => Boswell_Memory::get_updated_at(),
						'sections'   => array_keys( Boswell_Memory::SECTIONS ),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Tool: Append memory entry.
	 */
	private static function register_add_memory_tool(): void {
		wp_register_ability(
			'boswell/add-memory',
			array(
				'label'               => __( 'Add Memory Entry', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Append an entry to a memory section. Sections: recent_activities, ongoing_topics, commentary_log, notes.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'section' => array(
							'type'        => 'string',
							'description' => __( 'Section key.', 'boswell' ),
							'enum'        => array_keys( Boswell_Memory::SECTIONS ),
						),
						'entry'   => array(
							'type'        => 'string',
							'description' => __( 'Entry text (date prefix added automatically).', 'boswell' ),
							'minLength'   => 1,
						),
					),
					'required'   => array( 'section', 'entry' ),
				),
				'execute_callback'    => function ( array $input ) {
					$section = $input['section'] ?? '';
					$entry   = $input['entry'] ?? '';

					if ( ! Boswell_Memory::append_entry( $section, $entry ) ) {
						throw new \Exception( 'Failed to append entry to section: ' . esc_html( $section ) );
					}

					return array(
						'section'    => $section,
						'updated_at' => Boswell_Memory::get_updated_at(),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Prompt: Write for site with persona context.
	 */
	private static function register_write_prompt(): void {
		wp_register_ability(
			'boswell/write-for-site',
			array(
				'label'               => __( 'Write for Site', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Get writing context (persona + memory) for content creation.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'persona' => array(
							'type'        => 'string',
							'description' => __( 'Persona ID (uses first persona if omitted).', 'boswell' ),
						),
						'topic'   => array(
							'type'        => 'string',
							'description' => __( 'Topic or theme for the post.', 'boswell' ),
						),
					),
				),
				'execute_callback'    => function ( array $input ) {
					$persona_id = $input['persona'] ?? '';
					$topic      = $input['topic'] ?? '';

					$personas = Boswell_Persona::get_all();
					$target   = null;

					if ( ! empty( $persona_id ) ) {
						$target = Boswell_Persona::get( $persona_id );
					} elseif ( ! empty( $personas ) ) {
						$target = $personas[0];
					}

					$parts = array();

					if ( $target ) {
						$parts[] = $target['persona'];
					}

					$memory = Boswell_Memory::get();
					if ( ! empty( $memory ) ) {
						$parts[] = "---\n\n## Your Memory\n\n" . $memory;
					}

					if ( ! empty( $topic ) ) {
						$parts[] = "---\n\n## Topic\n\n" . $topic;
					}

					$parts[] = "---\n\n"
						. 'Write content following these guidelines. '
						. 'The content will be posted as HTML to WordPress, '
						. 'so use appropriate HTML tags (h2, h3, p, pre, code, ul, ol, etc.).';

					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => implode( "\n\n", $parts ),
								),
							),
						),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'arguments'   => array(
						array(
							'name'        => 'persona',
							'description' => __( 'Persona ID (uses first persona if omitted).', 'boswell' ),
							'required'    => false,
						),
						array(
							'name'        => 'topic',
							'description' => __( 'Topic or theme for the post.', 'boswell' ),
							'required'    => false,
						),
					),
					'annotations' => array(
						'audience' => array( 'user', 'assistant' ),
						'priority' => 0.9,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);
	}
}
