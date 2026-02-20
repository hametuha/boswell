<?php
/**
 * Boswell Settings
 *
 * Admin settings page for persona configuration.
 *
 * @package Boswell
 */

/**
 * Settings page for Boswell.
 */
class Boswell_Settings {

	const OPTION_KEY = 'boswell_persona';

	const OPTION_GROUP = 'boswell_settings';

	const PAGE_SLUG = 'boswell';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add options page under Settings menu.
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'Boswell Settings', 'boswell' ),
			__( 'Boswell', 'boswell' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'boswell_persona_section',
			__( 'Persona', 'boswell' ),
			array( __CLASS__, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'boswell_persona_field',
			__( 'Persona Definition', 'boswell' ),
			array( __CLASS__, 'render_persona_field' ),
			self::PAGE_SLUG,
			'boswell_persona_section'
		);
	}

	/**
	 * Render section description.
	 */
	public static function render_section(): void {
		echo '<p>';
		esc_html_e(
			'Define Boswell\'s personality in Markdown. This persona is used when generating comments and interacting with blog content.',
			'boswell'
		);
		echo '</p>';
	}

	/**
	 * Render persona textarea field.
	 */
	public static function render_persona_field(): void {
		$value = get_option( self::OPTION_KEY, '' );
		printf(
			'<textarea name="%s" id="%s" class="large-text code" rows="20">%s</textarea>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( self::OPTION_KEY ),
			esc_textarea( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'Write in Markdown. This text is passed to the AI as a system prompt.', 'boswell' );
		echo '</p>';
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Boswell Settings', 'boswell' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get the persona text.
	 *
	 * @return string
	 */
	public static function get_persona(): string {
		return get_option( self::OPTION_KEY, '' );
	}
}
