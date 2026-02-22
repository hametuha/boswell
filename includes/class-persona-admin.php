<?php
/**
 * Boswell Persona Admin
 *
 * Admin page for managing AI personas.
 *
 * @package Boswell
 */

/**
 * Admin UI for persona management.
 */
class Boswell_Persona_Admin {

	const PAGE_SLUG = 'boswell';

	const NONCE_ACTION = 'boswell_persona_save';

	const DELETE_ACTION = 'boswell_persona_delete';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_form' ) );
	}

	/**
	 * Add options page.
	 */
	public static function add_menu(): void {
		$hook = add_options_page(
			__( 'Boswell Settings', 'boswell' ),
			__( 'Boswell', 'boswell' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		}
	}

	/**
	 * Enqueue scripts on the Boswell settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
	}

	/**
	 * Handle form submissions (save / delete).
	 */
	public static function handle_form(): void {
		// Handle delete.
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete' === $_GET['action'] ) {
			self::handle_delete();
			return;
		}

		// Handle save.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! isset( $_POST['boswell_persona_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['boswell_persona_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'boswell' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'boswell' ) );
		}

		$data = array(
			'name'           => sanitize_text_field( wp_unslash( $_POST['persona_name'] ?? '' ) ),
			'persona'        => sanitize_textarea_field( wp_unslash( $_POST['persona_text'] ?? '' ) ),
			'user_id'        => (int) ( $_POST['persona_user_id'] ?? 0 ),
			'provider'       => sanitize_text_field( wp_unslash( $_POST['persona_provider'] ?? '' ) ),
			'cron_enabled'   => ! empty( $_POST['cron_enabled'] ),
			'cron_frequency' => sanitize_text_field( wp_unslash( $_POST['cron_frequency'] ?? 'daily' ) ),
		);

		$editing_id = sanitize_text_field( wp_unslash( $_POST['persona_id'] ?? '' ) );
		if ( ! empty( $editing_id ) ) {
			$data['id'] = $editing_id;
		}

		$result = Boswell_Persona::save( $data );
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'boswell', $result->get_error_code(), $result->get_error_message() );
			return;
		}

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => '1',
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle persona deletion.
	 */
	private static function handle_delete(): void {
		$id = sanitize_text_field( wp_unslash( $_GET['id'] ) );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::DELETE_ACTION . '_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'boswell' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'boswell' ) );
		}

		Boswell_Persona::delete( $id );

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'deleted' => '1',
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the admin page (routes to list, new, or edit view).
	 */
	public static function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only, no data mutation.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		if ( 'new' === $action || 'edit' === $action ) {
			self::render_form( $action );
		} else {
			self::render_list();
		}
	}

	/**
	 * Render the persona list view.
	 */
	private static function render_list(): void {
		$personas = Boswell_Persona::get_all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$updated = isset( $_GET['updated'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$deleted = isset( $_GET['deleted'] );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Boswell Personas', 'boswell' ); ?></h1>
			<a href="<?php echo esc_url( self::form_url( 'new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'boswell' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Persona saved.', 'boswell' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Persona deleted.', 'boswell' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $personas ) ) : ?>
				<p><?php esc_html_e( 'No personas configured yet. Add one to get started.', 'boswell' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'boswell' ); ?></th>
							<th><?php esc_html_e( 'User', 'boswell' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'boswell' ); ?></th>
							<th><?php esc_html_e( 'Auto Comment', 'boswell' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'boswell' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $personas as $p ) : ?>
							<?php $user = get_userdata( $p['user_id'] ); ?>
							<tr>
								<td><strong><?php echo esc_html( $p['name'] ); ?></strong><br><code><?php echo esc_html( $p['id'] ); ?></code></td>
								<td><?php echo $user ? esc_html( $user->display_name ) : esc_html__( '(unknown)', 'boswell' ); ?></td>
								<td><?php echo esc_html( $p['provider'] ); ?></td>
								<td>
									<?php if ( ! empty( $p['cron_enabled'] ) ) : ?>
										<?php echo esc_html( $p['cron_frequency'] ?? 'daily' ); ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( self::form_url( 'edit', $p['id'] ) ); ?>">
										<?php esc_html_e( 'Edit', 'boswell' ); ?>
									</a> |
									<a href="<?php echo esc_url( self::delete_url( $p['id'] ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this persona?', 'boswell' ) ); ?>');"
										style="color:#b32d2e;">
										<?php esc_html_e( 'Delete', 'boswell' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>
			<?php self::render_ping_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param string $action 'new' or 'edit'.
	 */
	private static function render_form( string $action ): void {
		$persona = array(
			'id'             => '',
			'name'           => '',
			'persona'        => '',
			'user_id'        => 0,
			'provider'       => 'anthropic',
			'cron_enabled'   => false,
			'cron_frequency' => 'daily',
		);

		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			$id    = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
			$found = Boswell_Persona::get( $id );
			if ( ! $found ) {
				wp_die( esc_html__( 'Persona not found.', 'boswell' ) );
			}
			$persona = $found;
		}

		$title = 'edit' === $action
			? __( 'Edit Persona', 'boswell' )
			: __( 'Add New Persona', 'boswell' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php settings_errors( 'boswell' ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, 'boswell_persona_nonce' ); ?>
				<input type="hidden" name="persona_id" value="<?php echo esc_attr( $persona['id'] ); ?>">
				<table class="form-table">
					<tr>
						<th><label for="persona_name"><?php esc_html_e( 'Name', 'boswell' ); ?></label></th>
						<td><input type="text" name="persona_name" id="persona_name" class="regular-text" value="<?php echo esc_attr( $persona['name'] ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="persona_user_id"><?php esc_html_e( 'WordPress User', 'boswell' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'     => 'persona_user_id',
									'id'       => 'persona_user_id',
									'selected' => $persona['user_id'],
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Comments will be posted as this user.', 'boswell' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="persona_provider"><?php esc_html_e( 'AI Provider', 'boswell' ); ?></label></th>
						<td>
							<select name="persona_provider" id="persona_provider">
								<?php foreach ( Boswell_Persona::PROVIDERS as $pid ) : ?>
									<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $persona['provider'], $pid ); ?>>
										<?php echo esc_html( $pid ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="persona_text"><?php esc_html_e( 'Persona Definition', 'boswell' ); ?></label></th>
						<td>
							<textarea name="persona_text" id="persona_text" class="large-text code" rows="15"><?php echo esc_textarea( $persona['persona'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Write in Markdown. This text is passed to the AI as a system prompt.', 'boswell' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto Comment', 'boswell' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cron_enabled" value="1" <?php checked( $persona['cron_enabled'] ); ?>>
								<?php esc_html_e( 'Automatically comment on posts', 'boswell' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="cron_frequency"><?php esc_html_e( 'Frequency', 'boswell' ); ?></label></th>
						<td>
							<?php
							$frequencies = array(
								'hourly'     => __( 'Hourly', 'boswell' ),
								'twicedaily' => __( 'Twice Daily', 'boswell' ),
								'daily'      => __( 'Daily', 'boswell' ),
							);
							?>
							<select name="cron_frequency" id="cron_frequency">
								<?php foreach ( $frequencies as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $persona['cron_frequency'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php
							$next = wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $persona['id'] ) );
							if ( $next ) :
								?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: next run datetime */
										esc_html__( 'Next scheduled run: %s', 'boswell' ),
										esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( 'edit' === $action ? __( 'Update Persona', 'boswell' ) : __( 'Add Persona', 'boswell' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the connectivity test section.
	 */
	private static function render_ping_section(): void {
		$personas = Boswell_Persona::get_all();
		if ( empty( $personas ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Connectivity Test', 'boswell' ); ?></h2>
		<p><?php esc_html_e( 'Send a test prompt using a persona. Make sure AI credentials are set first.', 'boswell' ); ?></p>
		<p>
			<label for="boswell-ping-persona"><?php esc_html_e( 'Persona:', 'boswell' ); ?></label>
			<select id="boswell-ping-persona">
				<?php foreach ( $personas as $p ) : ?>
					<option value="<?php echo esc_attr( $p['id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" id="boswell-ping-btn" class="button button-secondary">
				<?php esc_html_e( 'Test', 'boswell' ); ?>
			</button>
			<span id="boswell-ping-spinner" class="spinner" style="float:none;"></span>
		</p>
		<div id="boswell-ping-result" style="display:none;margin-top:12px;">
			<textarea class="large-text" rows="4" readonly></textarea>
		</div>
		<script>
		(function() {
			var btn     = document.getElementById('boswell-ping-btn');
			var sel     = document.getElementById('boswell-ping-persona');
			var spinner = document.getElementById('boswell-ping-spinner');
			var result  = document.getElementById('boswell-ping-result');
			var output  = result.querySelector('textarea');

			btn.addEventListener('click', function() {
				btn.disabled = true;
				spinner.classList.add('is-active');
				result.style.display = 'none';
				output.value = '';

				wp.apiFetch({
					path: '/boswell/v1/ping',
					method: 'POST',
					data: { persona_id: sel.value }
				}).then(function(res) {
					output.value = '[' + res.provider + '] ' + res.response;
					result.style.display = '';
				}).catch(function(err) {
					output.value = 'Error: ' + (err.message || err.code || 'Unknown error');
					result.style.display = '';
				}).finally(function() {
					btn.disabled = false;
					spinner.classList.remove('is-active');
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Build a URL for the add/edit form.
	 *
	 * @param string $action 'new' or 'edit'.
	 * @param string $id     Persona ID (for edit).
	 * @return string
	 */
	private static function form_url( string $action, string $id = '' ): string {
		$args = array(
			'page'   => self::PAGE_SLUG,
			'action' => $action,
		);
		if ( ! empty( $id ) ) {
			$args['id'] = $id;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Build a nonce-protected delete URL.
	 *
	 * @param string $id Persona ID.
	 * @return string
	 */
	private static function delete_url( string $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'delete',
					'id'     => $id,
				),
				admin_url( 'options-general.php' )
			),
			self::DELETE_ACTION . '_' . $id
		);
	}
}
