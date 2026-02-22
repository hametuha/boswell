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
		self::register_context_tool();
		self::register_memory_tool();
		self::register_add_memory_tool();
		self::register_write_prompt();
		self::register_list_posts_tool();
		self::register_create_post_tool();
		self::register_update_post_tool();
		self::register_delete_post_tool();
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
					return current_user_can( 'edit_posts' );
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
	 * Build the site context payload.
	 *
	 * @return array Site name, URL, personas, and memory.
	 */
	public static function build_context(): array {
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
				'execute_callback'    => array( __CLASS__, 'build_context' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
	 * Tool: Get site context (tool version of the resource for discoverability).
	 */
	private static function register_context_tool(): void {
		wp_register_ability(
			'boswell/get-context',
			array(
				'label'               => __( 'Get Site Context', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Get site info, all personas with style guides, and shared memory. Call this before writing a blog post.', 'boswell' ),
				'execute_callback'    => array( __CLASS__, 'build_context' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
					return current_user_can( 'edit_posts' );
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
					return current_user_can( 'edit_posts' );
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
					return current_user_can( 'edit_posts' );
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

	/**
	 * Tool: List posts.
	 */
	private static function register_list_posts_tool(): void {
		wp_register_ability(
			'boswell/list-posts',
			array(
				'label'               => __( 'List Posts', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'List WordPress posts with optional filters.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'Post status (publish, draft, pending, private). Default: publish.', 'boswell' ),
							'default'     => 'publish',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Number of posts to return. Default: 10, max: 100.', 'boswell' ),
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Search keyword.', 'boswell' ),
						),
						'category' => array(
							'type'        => 'string',
							'description' => __( 'Category slug to filter by.', 'boswell' ),
						),
					),
				),
				'execute_callback'    => function ( array $input ) {
					$args = array(
						'post_type'      => 'post',
						'post_status'    => sanitize_key( $input['status'] ?? 'publish' ),
						'posts_per_page' => min( (int) ( $input['per_page'] ?? 10 ), 100 ),
						'orderby'        => 'date',
						'order'          => 'DESC',
					);

					if ( ! empty( $input['search'] ) ) {
						$args['s'] = sanitize_text_field( $input['search'] );
					}
					if ( ! empty( $input['category'] ) ) {
						$args['category_name'] = sanitize_text_field( $input['category'] );
					}

					$posts = get_posts( $args );

					return array_map(
						function ( \WP_Post $post ): array {
							return array(
								'id'      => $post->ID,
								'title'   => $post->post_title,
								'status'  => $post->post_status,
								'date'    => $post->post_date,
								'url'     => get_permalink( $post ),
								'excerpt' => wp_trim_words( $post->post_content, 30 ),
							);
						},
						$posts
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
	 * Tool: Create a post (default: draft).
	 */
	private static function register_create_post_tool(): void {
		wp_register_ability(
			'boswell/create-post',
			array(
				'label'               => __( 'Create Post', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Create a new WordPress post. Defaults to draft status.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'Post title.', 'boswell' ),
						),
						'content'  => array(
							'type'        => 'string',
							'description' => __( 'Post content (HTML).', 'boswell' ),
						),
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'Post status: draft, publish, pending. Default: draft.', 'boswell' ),
							'default'     => 'draft',
						),
						'category' => array(
							'type'        => 'string',
							'description' => __( 'Category slug.', 'boswell' ),
						),
						'tags'     => array(
							'type'        => 'string',
							'description' => __( 'Comma-separated tag names.', 'boswell' ),
						),
						'excerpt'  => array(
							'type'        => 'string',
							'description' => __( 'Post excerpt.', 'boswell' ),
						),
					),
					'required'   => array( 'title', 'content' ),
				),
				'execute_callback'    => function ( array $input ) {
					$postarr = array(
						'post_title'   => sanitize_text_field( $input['title'] ),
						'post_content' => wp_kses_post( $input['content'] ),
						'post_status'  => sanitize_key( $input['status'] ?? 'draft' ),
						'post_type'    => 'post',
					);

					if ( ! empty( $input['excerpt'] ) ) {
						$postarr['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
					}
					if ( ! empty( $input['category'] ) ) {
						$term = get_term_by( 'slug', sanitize_text_field( $input['category'] ), 'category' );
						if ( $term ) {
							$postarr['post_category'] = array( $term->term_id );
						}
					}
					if ( ! empty( $input['tags'] ) ) {
						$postarr['tags_input'] = array_map( 'trim', explode( ',', $input['tags'] ) );
					}

					$post_id = wp_insert_post( $postarr, true );
					if ( is_wp_error( $post_id ) ) {
						throw new \Exception( esc_html( $post_id->get_error_message() ) );
					}

					$post = get_post( $post_id );
					return array(
						'id'     => $post->ID,
						'title'  => $post->post_title,
						'status' => $post->post_status,
						'url'    => get_permalink( $post ),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
	 * Tool: Update a post.
	 */
	private static function register_update_post_tool(): void {
		wp_register_ability(
			'boswell/update-post',
			array(
				'label'               => __( 'Update Post', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Update an existing WordPress post.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID to update.', 'boswell' ),
							'minimum'     => 1,
						),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'New post title.', 'boswell' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'New post content (HTML).', 'boswell' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'New post status.', 'boswell' ),
						),
						'excerpt' => array(
							'type'        => 'string',
							'description' => __( 'New post excerpt.', 'boswell' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => function ( array $input ) {
					$post_id = (int) $input['post_id'];
					$post    = get_post( $post_id );
					if ( ! $post ) {
						throw new \Exception( esc_html( "Post not found: {$post_id}" ) );
					}

					$postarr = array( 'ID' => $post_id );

					if ( isset( $input['title'] ) ) {
						$postarr['post_title'] = sanitize_text_field( $input['title'] );
					}
					if ( isset( $input['content'] ) ) {
						$postarr['post_content'] = wp_kses_post( $input['content'] );
					}
					if ( isset( $input['status'] ) ) {
						$postarr['post_status'] = sanitize_key( $input['status'] );
					}
					if ( isset( $input['excerpt'] ) ) {
						$postarr['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
					}

					$result = wp_update_post( $postarr, true );
					if ( is_wp_error( $result ) ) {
						throw new \Exception( esc_html( $result->get_error_message() ) );
					}

					$post = get_post( $post_id );
					return array(
						'id'     => $post->ID,
						'title'  => $post->post_title,
						'status' => $post->post_status,
						'url'    => get_permalink( $post ),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
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
	 * Tool: Delete (trash) a post.
	 */
	private static function register_delete_post_tool(): void {
		wp_register_ability(
			'boswell/delete-post',
			array(
				'label'               => __( 'Delete Post', 'boswell' ),
				'category'            => 'boswell',
				'description'         => __( 'Move a WordPress post to trash.', 'boswell' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID to trash.', 'boswell' ),
							'minimum'     => 1,
						),
					),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => function ( array $input ) {
					$post_id = (int) $input['post_id'];
					$post    = get_post( $post_id );
					if ( ! $post ) {
						throw new \Exception( esc_html( "Post not found: {$post_id}" ) );
					}

					$result = wp_trash_post( $post_id );
					if ( ! $result ) {
						throw new \Exception( esc_html( "Failed to trash post: {$post_id}" ) );
					}

					return array(
						'id'     => $post_id,
						'title'  => $post->post_title,
						'status' => 'trash',
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
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
}
