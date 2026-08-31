<?php
/**
 * Settings -> Hey Support page.
 *
 * Uses `register_setting()` so the value lands in wp_options under
 * HEY_SUPPORT_OPTION_KEY with the core nonce + option-group allowlist, but the
 * fields themselves are a hand-rolled form-table rather than
 * add_settings_section/add_settings_field.
 *
 * Verify-token + Refresh-chatbots are admin-AJAX endpoints - no JS bundler
 * needed. Both ride the PUBLIC v1 API, which is gated to the Scale plan and up,
 * so they are presented as optional conveniences: pasting a chatbot ID by hand
 * works on every plan.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'hey_support_admin_menu' );
add_action( 'admin_init', 'hey_support_register_settings' );
add_action( 'admin_enqueue_scripts', 'hey_support_admin_enqueue' );
add_action( 'wp_ajax_hey_support_verify_token', 'hey_support_ajax_verify_token' );
add_action( 'wp_ajax_hey_support_refresh_chatbots', 'hey_support_ajax_refresh_chatbots' );
add_action( 'wp_ajax_hey_support_storefront_test', 'hey_support_ajax_storefront_test' );

function hey_support_admin_menu(): void {
	add_options_page(
		__( 'Hey Support', 'hey-support-ai-chatbot' ),
		__( 'Hey Support', 'hey-support-ai-chatbot' ),
		'manage_options',
		'hey-support-ai-chatbot',
		'hey_support_render_settings_page'
	);
}

function hey_support_register_settings(): void {
	register_setting(
		'hey_support_settings_group',
		HEY_SUPPORT_OPTION_KEY,
		[
			'type'              => 'array',
			'sanitize_callback' => 'hey_support_sanitize_options',
			'default'           => hey_support_default_options(),
		]
	);
}

function hey_support_admin_enqueue( string $hook ): void {
	if ( 'settings_page_hey-support-ai-chatbot' !== $hook ) {
		return;
	}
	wp_enqueue_script(
		'hey-support-ai-chatbot-admin',
		HEY_SUPPORT_URL . 'admin/settings.js',
		[ 'jquery' ],
		HEY_SUPPORT_VERSION,
		true
	);
	wp_localize_script(
		'hey-support-ai-chatbot-admin',
		'heySupportAdmin',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'hey_support_admin' ),
			'i18n'    => [
				'verifying'    => __( 'Verifying...', 'hey-support-ai-chatbot' ),
				'refreshing'   => __( 'Refreshing...', 'hey-support-ai-chatbot' ),
				'refreshLabel' => __( 'Refresh chatbot list', 'hey-support-ai-chatbot' ),
				'connectedTo'  => __( 'Connected to', 'hey-support-ai-chatbot' ),
				'invalidToken' => __( 'Invalid token. Mint a new key in your Hey Support dashboard under Settings, API keys.', 'hey-support-ai-chatbot' ),
				'planGate'     => __( 'API access requires the Scale plan. You can still paste your chatbot ID manually below.', 'hey-support-ai-chatbot' ),
				'rateLimited'  => __( 'Rate limited. Try again in a minute.', 'hey-support-ai-chatbot' ),
				'networkErr'   => __( 'Network error. Check your connection and try again.', 'hey-support-ai-chatbot' ),
				'noChatbots'   => __( 'No chatbots found for this workspace.', 'hey-support-ai-chatbot' ),
				'noToken'      => __( 'Add your workspace API token first.', 'hey-support-ai-chatbot' ),
				'permalinks'   => __( 'Open Permalinks settings', 'hey-support-ai-chatbot' ),
			],
		]
	);
}

function hey_support_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage these settings.', 'hey-support-ai-chatbot' ) );
	}
	$opts         = hey_support_get_options();
	$masked_token = hey_support_mask_token( (string) $opts['api_token'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Hey Support', 'hey-support-ai-chatbot' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: opening link tag to the hey.support dashboard, 2: closing link tag. */
				esc_html__( 'Paste a chatbot ID and save - your widget appears on every page. Grab the ID from your %1$sHey Support dashboard%2$s.', 'hey-support-ai-chatbot' ),
				'<a href="' . esc_url( 'https://hey.support/dashboard' ) . '" target="_blank" rel="noopener">',
				'</a>'
			);
			?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'hey_support_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="hey_support_chatbot_id"><?php esc_html_e( 'Chatbot ID', 'hey-support-ai-chatbot' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="hey_support_chatbot_id"
							name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[chatbot_id]"
							value="<?php echo esc_attr( $opts['chatbot_id'] ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr__( 'e.g. ncimu', 'hey-support-ai-chatbot' ); ?>"
						/>
						<button type="button" class="button" id="hey-support-ai-chatbot-refresh-chatbots"><?php esc_html_e( 'Refresh chatbot list', 'hey-support-ai-chatbot' ); ?></button>
						<p class="description"><?php esc_html_e( 'This is all the widget needs. Paste the public ID from your chatbot dashboard, or add a token below and click Refresh to pick from a list.', 'hey-support-ai-chatbot' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="hey_support_api_token"><?php esc_html_e( 'Workspace API token (optional)', 'hey-support-ai-chatbot' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="hey_support_api_token"
							name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[api_token]"
							value=""
							class="regular-text"
							autocomplete="off"
							<?php if ( '' !== $masked_token ) : ?>
								placeholder="<?php echo esc_attr( $masked_token ); ?>"
							<?php endif; ?>
						/>
						<button type="button" class="button" id="hey-support-ai-chatbot-verify"><?php esc_html_e( 'Verify token', 'hey-support-ai-chatbot' ); ?></button>
						<p class="description" id="hey-support-ai-chatbot-verify-result"></p>
						<?php if ( '' !== $masked_token ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: masked API token prefix, e.g. "hs_live_ab12...". */
									esc_html__( 'Saved: %s. Leave the field empty to keep it.', 'hey-support-ai-chatbot' ),
									'<code>' . esc_html( $masked_token ) . '</code>'
								);
								?>
							</p>
							<p>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[api_token_clear]"
										value="1"
									/>
									<?php esc_html_e( 'Remove the saved token', 'hey-support-ai-chatbot' ); ?>
								</label>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Only used by the Verify and Refresh buttons, never printed into your pages. Both ride the Hey Support public API, which requires the Scale plan; on other plans just paste the chatbot ID above.', 'hey-support-ai-chatbot' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Live products in chat', 'hey-support-ai-chatbot' ); ?></th>
					<td>
						<?php if ( class_exists( 'WooCommerce' ) ) : ?>
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'Let your chatbot show real products from this store, with live prices and stock, and add them to the shopper\'s cart. No API key needed - it reads your public store data.', 'hey-support-ai-chatbot' ); ?>
							</p>
							<p>
								<button type="button" class="button" id="hey-support-storefront-test">
									<?php esc_html_e( 'Check my store is reachable', 'hey-support-ai-chatbot' ); ?>
								</button>
								<a class="button button-primary" target="_blank" rel="noopener"
									href="<?php echo esc_url( 'https://hey.support/dashboard?connect=woocommerce_storefront&site_url=' . rawurlencode( home_url() ) ); ?>">
									<?php esc_html_e( 'Turn it on in Hey Support', 'hey-support-ai-chatbot' ); ?>
								</a>
							</p>
							<p class="description" id="hey-support-storefront-result"></p>
						<?php else : ?>
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'WooCommerce is not active on this site. Install WooCommerce to show products in chat.', 'hey-support-ai-chatbot' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'AI Actions (WooCommerce)', 'hey-support-ai-chatbot' ); ?></th>
					<td>
						<?php if ( class_exists( 'WooCommerce' ) ) : ?>
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'Let your chatbot look up orders, update shipping, and issue coupons. Approve it once in your own WooCommerce admin - the API key is created and sent for you.', 'hey-support-ai-chatbot' ); ?>
							</p>
							<p>
								<a class="button button-primary" target="_blank" rel="noopener"
									href="<?php echo esc_url( 'https://hey.support/dashboard?connect=woocommerce&site_url=' . rawurlencode( home_url() ) ); ?>">
									<?php esc_html_e( 'Connect to Hey Support', 'hey-support-ai-chatbot' ); ?>
								</a>
							</p>
							<p class="description">
								<?php esc_html_e( 'Opens Hey Support with this store URL already filled in:', 'hey-support-ai-chatbot' ); ?>
								<code><?php echo esc_html( home_url() ); ?></code>
							</p>
						<?php else : ?>
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'WooCommerce is not active on this site. Install WooCommerce to enable order/coupon AI Actions.', 'hey-support-ai-chatbot' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Show widget on', 'hey-support-ai-chatbot' ); ?></th>
					<td>
						<fieldset>
							<?php
							$choices = [
								'all'            => __( 'All pages and posts', 'hey-support-ai-chatbot' ),
								'posts'          => __( 'Posts only', 'hey-support-ai-chatbot' ),
								'pages'          => __( 'Pages only', 'hey-support-ai-chatbot' ),
								'shortcode-only' => __( 'Only where [hey_support] shortcode or block is used', 'hey-support-ai-chatbot' ),
							];
							foreach ( $choices as $value => $label ) :
								?>
								<label style="display:block;margin-bottom:4px;">
									<input
										type="radio"
										name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[enable_on]"
										value="<?php echo esc_attr( $value ); ?>"
										<?php checked( $opts['enable_on'], $value ); ?>
									/>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="hey_support_exclude_ids"><?php esc_html_e( 'Hide on these post IDs', 'hey-support-ai-chatbot' ); ?></label>
					</th>
					<td>
						<textarea
							id="hey_support_exclude_ids"
							name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[exclude_ids]"
							rows="2"
							class="regular-text"
							placeholder="42, 108, 215"
						><?php echo esc_textarea( $opts['exclude_ids'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma-separated post / page IDs to skip.', 'hey-support-ai-chatbot' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-open widget', 'hey-support-ai-chatbot' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[auto_open]"
								value="1"
								<?php checked( ! empty( $opts['auto_open'] ) ); ?>
							/>
							<?php esc_html_e( 'Open the chat 500 ms after the page loads.', 'hey-support-ai-chatbot' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// --- AJAX: Verify token -----------------------------------------------------

/**
 * Resolve which token to test: what the operator just typed, else the one
 * already stored.
 *
 * The field renders EMPTY by design - the token is write-only, so an empty
 * submission means "leave it alone" everywhere else. Treating empty as "no
 * token" here meant that saving a token and then pressing Verify reported
 * "Invalid token. Mint a new key...", which sent people off minting keys that
 * were never the problem.
 *
 * @param string $typed Sanitized value straight from the request.
 */
function hey_support_resolve_token( string $typed ): string {
	if ( '' !== $typed ) {
		return $typed;
	}
	$opts = hey_support_get_options();
	return (string) $opts['api_token'];
}

function hey_support_ajax_verify_token(): void {
	check_ajax_referer( 'hey_support_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden', 'hey-support-ai-chatbot' ) ], 403 );
	}

	$typed = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$token = hey_support_resolve_token( $typed );
	if ( '' === $token ) {
		// 200, not 400: a non-2xx lands in jQuery's fail() branch, which can only
		// report a generic network error and would bury this exact message.
		wp_send_json_error( [ 'code' => 'no_token' ], 200 );
	}

	$res = hey_support_api_me( $token );
	if ( $res['ok'] && is_array( $res['body'] ) && isset( $res['body']['data'] ) ) {
		$data = $res['body']['data'];
		wp_send_json_success(
			[
				'team_name' => isset( $data['team_name'] ) ? (string) $data['team_name'] : '',
				'key_name'  => isset( $data['key_name'] ) ? (string) $data['key_name'] : '',
			]
		);
	}

	wp_send_json_error(
		[
			'status'  => $res['status'],
			'message' => $res['error'] ?? '',
		],
		200
	);
}

// --- AJAX: Store API reachability self-check --------------------------------

/**
 * Ask THIS site whether its own Store API answers, and report the fix in the
 * place the merchant can apply it.
 *
 * The point of doing it here rather than only on hey.support: WordPress fails in
 * a handful of very specific ways - Plain permalinks, a security plugin sitting
 * on /wp-json/, a maintenance page - and each has a one-minute fix in wp-admin.
 *
 * Honest caveat, reflected in the copy: this is a LOOPBACK request. Some
 * firewalls allow the server's own IP while blocking outside traffic, so a pass
 * here is necessary but not sufficient - hey.support probes again on connect.
 */
function hey_support_ajax_storefront_test(): void {
	check_ajax_referer( 'hey_support_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden', 'hey-support-ai-chatbot' ) ], 403 );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'WooCommerce is not active on this site.', 'hey-support-ai-chatbot' ) ], 200 );
	}

	$url = rest_url( 'wc/store/v1/products?per_page=1' );
	$res = wp_remote_get( $url, [ 'timeout' => 10 ] );

	if ( is_wp_error( $res ) ) {
		wp_send_json_error(
			[ 'message' => __( 'Could not reach your own REST API from this server.', 'hey-support-ai-chatbot' ) ],
			200
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	if ( 404 === $code ) {
		wp_send_json_error(
			[
				'message'      => __( 'WordPress did not recognise the Store API address. Set Settings > Permalinks to Post name and press Save, then try again.', 'hey-support-ai-chatbot' ),
				'permalinkUrl' => admin_url( 'options-permalink.php' ),
			],
			200
		);
	}
	if ( 401 === $code || 403 === $code ) {
		wp_send_json_error(
			[ 'message' => __( 'A security plugin or firewall is blocking /wp-json/. Allow it for logged-out visitors and try again.', 'hey-support-ai-chatbot' ) ],
			200
		);
	}
	if ( 200 !== $code ) {
		/* translators: %d: HTTP status code. */
		wp_send_json_error( [ 'message' => sprintf( __( 'Your store answered with HTTP %d.', 'hey-support-ai-chatbot' ), $code ) ], 200 );
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		wp_send_json_error(
			[ 'message' => __( 'Your site answered with a page instead of data. A caching or maintenance plugin is usually intercepting /wp-json/.', 'hey-support-ai-chatbot' ) ],
			200
		);
	}

	$total = (int) wp_remote_retrieve_header( $res, 'x-wp-total' );
	wp_send_json_success(
		[
			/* translators: %d: number of published products. */
			'message' => sprintf( _n( 'Ready - %d product visible.', 'Ready - %d products visible.', $total, 'hey-support-ai-chatbot' ), $total ),
		]
	);
}

// --- AJAX: Refresh chatbot list ---------------------------------------------

function hey_support_ajax_refresh_chatbots(): void {
	check_ajax_referer( 'hey_support_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden', 'hey-support-ai-chatbot' ) ], 403 );
	}

	$typed = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$token = hey_support_resolve_token( $typed );
	if ( '' === $token ) {
		// 200, not 400: a non-2xx lands in jQuery's fail() branch, which can only
		// report a generic network error and would bury this exact message.
		wp_send_json_error( [ 'code' => 'no_token' ], 200 );
	}

	$res = hey_support_api_chatbots( $token );
	if ( ! $res['ok'] || ! is_array( $res['body'] ) || ! isset( $res['body']['data'] ) ) {
		wp_send_json_error(
			[
				'status'  => $res['status'],
				'message' => $res['error'] ?? '',
			],
			200
		);
	}

	$bots = [];
	foreach ( $res['body']['data'] as $row ) {
		if ( isset( $row['id'], $row['name'] ) ) {
			$bots[] = [
				'id'   => (string) $row['id'],
				'name' => (string) $row['name'],
			];
		}
	}
	wp_send_json_success( [ 'chatbots' => $bots ] );
}
