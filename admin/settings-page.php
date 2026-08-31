<?php
/**
 * Settings -> Hey Support.
 *
 * Rebuilt around the one thing someone opening this screen is actually here to
 * do: get their agent onto the site. Everything else is secondary and looks it.
 *
 * The screen this replaced was a flat form-table where six rows shouted equally,
 * and three of them said ID, token and REST API key to a person who wanted a
 * chat bubble. Two things caused most of that:
 *
 *   - The workspace API token field is GONE. One-click connect replaced both
 *     things it did, and it was gated to the Scale plan, so it advertised a
 *     capability most people could never use, directly under the one they
 *     needed. The stored value is preserved rather than deleted -- sanitize
 *     keeps it when the field is absent -- so nothing is destroyed on upgrade.
 *   - "Hide on these post IDs" asked for something WordPress never shows anyone,
 *     so people had to go hunting in URLs. It is a search-by-title picker now.
 *     The STORED shape is unchanged, so there is no migration and no risk to
 *     saved data.
 *
 * `enable_on` deliberately keeps all four modes. Posts-only and pages-only are
 * TYPE filters that do not map onto a simpler everywhere/except/only model, and
 * silently dropping a working setting on upgrade is worse than the problem that
 * would have solved.
 *
 * Native WordPress chrome throughout: WP's own controls and colours, arranged as
 * cards. A full brand re-skin would fight the admin theme and break under the
 * alternate admin colour schemes.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'hey_support_admin_menu' );
add_action( 'admin_init', 'hey_support_register_settings' );
add_action( 'admin_enqueue_scripts', 'hey_support_admin_enqueue' );
add_action( 'wp_ajax_hey_support_storefront_test', 'hey_support_ajax_storefront_test' );
add_filter( 'plugin_action_links_' . HEY_SUPPORT_BASENAME, 'hey_support_plugin_action_links' );
add_filter( 'plugin_row_meta', 'hey_support_plugin_row_meta', 10, 2 );
add_action( 'admin_notices', 'hey_support_unconnected_notice' );

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

/**
 * A Settings link on the Plugins row. Standard for any plugin with a settings
 * screen, and its absence is one of the first things that makes one feel
 * unfinished.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function hey_support_plugin_action_links( $links ): array {
	$links    = is_array( $links ) ? $links : [];
	$settings = sprintf(
		'<a href="%s">%s</a>',
		esc_url( hey_support_settings_url() ),
		esc_html__( 'Settings', 'hey-support-ai-chatbot' )
	);
	array_unshift( $links, $settings );
	return $links;
}

/**
 * @param string[] $links Existing row meta.
 * @param string   $file  Plugin file this row is for.
 * @return string[]
 */
function hey_support_plugin_row_meta( $links, $file ): array {
	$links = is_array( $links ) ? $links : [];
	if ( HEY_SUPPORT_BASENAME !== $file ) {
		return $links;
	}
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener">%s</a>',
		esc_url( HEY_SUPPORT_API_BASE . '/docs/integrations/wordpress' ),
		esc_html__( 'Documentation', 'hey-support-ai-chatbot' )
	);
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener">%s</a>',
		esc_url( HEY_SUPPORT_API_BASE ),
		esc_html__( 'Support', 'hey-support-ai-chatbot' )
	);
	return $links;
}

/**
 * One dismissible nudge while no agent is connected, and only where it is
 * actionable. A plugin that installs and then says nothing leaves people
 * assuming it is broken.
 */
function hey_support_unconnected_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'dashboard' ], true ) ) {
		return;
	}
	$opts = hey_support_get_options();
	if ( '' !== (string) $opts['chatbot_id'] ) {
		return;
	}
	printf(
		'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'Hey Support is installed but not connected yet.', 'hey-support-ai-chatbot' ),
		esc_url( hey_support_settings_url() ),
		esc_html__( 'Connect your agent', 'hey-support-ai-chatbot' )
	);
}

function hey_support_admin_enqueue( string $hook ): void {
	if ( 'settings_page_hey-support-ai-chatbot' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'hey-support-ai-chatbot-admin',
		HEY_SUPPORT_URL . 'admin/settings.css',
		[],
		HEY_SUPPORT_VERSION
	);
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
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'hey_support_admin' ),
			'restUrl'   => esc_url_raw( rest_url( 'wp/v2/search' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			// Resolved server-side rather than by a second REST round trip:
			// /wp/v2/search has no include= filter, so the client could not turn
			// saved IDs back into titles on its own.
			'excluded'  => hey_support_excluded_for_ui(),
			'i18n'      => [
				'searching'  => __( 'Searching...', 'hey-support-ai-chatbot' ),
				'noResults'  => __( 'Nothing matched.', 'hey-support-ai-chatbot' ),
				'remove'     => __( 'Remove', 'hey-support-ai-chatbot' ),
				'networkErr' => __( 'Network error. Check your connection and try again.', 'hey-support-ai-chatbot' ),
				'permalinks' => __( 'Open Permalinks settings', 'hey-support-ai-chatbot' ),
			],
		]
	);
}

/**
 * Saved exclusions as {id, title} for the picker.
 *
 * A deleted post keeps its ID in the option but has no title; it is shown as its
 * bare ID rather than dropped, so saving cannot silently discard a row the
 * operator never chose to remove.
 *
 * @return array<int, array{id:int,title:string}>
 */
function hey_support_excluded_for_ui(): array {
	$out = [];
	foreach ( hey_support_excluded_post_ids() as $id ) {
		$title = get_the_title( $id );
		$out[] = [
			'id'    => (int) $id,
			/* translators: %d: numeric ID of an item that no longer exists. */
			'title' => '' !== $title ? $title : sprintf( __( 'Deleted item (%d)', 'hey-support-ai-chatbot' ), $id ),
		];
	}
	return $out;
}

function hey_support_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage these settings.', 'hey-support-ai-chatbot' ) );
	}

	$opts      = hey_support_get_options();
	$connected = '' !== (string) $opts['chatbot_id'];

	hey_support_flush_connect_result();
	?>
	<div class="wrap hs-wrap">
		<div class="hs-head">
			<?php hey_support_mark(); ?>
			<h1 style="margin:0;padding:0;font-size:20px;"><?php esc_html_e( 'Hey Support', 'hey-support-ai-chatbot' ); ?></h1>
		</div>

		<?php if ( ! $connected ) : ?>
			<?php hey_support_render_connect_card(); ?>
		<?php else : ?>
			<?php hey_support_render_connected_header(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'hey_support_settings_group' ); ?>
				<?php hey_support_render_placement_card( $opts ); ?>
				<?php hey_support_render_commerce_card(); ?>
				<?php hey_support_render_advanced_card( $opts ); ?>
				<?php submit_button(); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The brand mark: the real one, shipped as an asset.
 *
 * An <img> rather than inlined SVG because the path data is a single 3KB string
 * that no amount of wrapping keeps readable -- and wrapping it is worse than
 * unreadable, since breaking path data on whitespace silently corrupts the
 * shape. As a file it stays byte-identical to the source vectors, and the
 * browser caches it.
 *
 * This is the one place any brand colour appears on this screen.
 */
function hey_support_mark(): void {
	printf(
		'<img src="%s" width="38" height="22" alt="%s" />',
		esc_url( HEY_SUPPORT_URL . 'admin/logo.svg' ),
		esc_attr__( 'Hey Support', 'hey-support-ai-chatbot' )
	);
}

/**
 * First run: one card, one button, nothing else.
 *
 * No placement rules, no store card. Settings for a widget that does not exist
 * yet are noise, and they are what made the old screen unreadable.
 */
function hey_support_render_connect_card(): void {
	?>
	<div class="hs-card hs-connect">
		<h2><?php esc_html_e( 'Connect your agent', 'hey-support-ai-chatbot' ); ?></h2>
		<p class="hs-card-desc">
			<?php esc_html_e( 'Sign in to Hey Support and pick the agent for this site. Nothing to copy or paste.', 'hey-support-ai-chatbot' ); ?>
		</p>
		<?php hey_support_connect_button( true ); ?>
		<p class="hs-card-desc" style="margin-top:16px;margin-bottom:0;">
			<button type="button" class="button-link hs-manual-toggle" aria-expanded="false">
				<?php esc_html_e( 'I have a chatbot ID', 'hey-support-ai-chatbot' ); ?>
			</button>
		</p>
		<div class="hs-manual" hidden>
			<form method="post" action="options.php" style="max-width:340px;margin:14px auto 0;">
				<?php settings_fields( 'hey_support_settings_group' ); ?>
				<?php hey_support_chatbot_id_input( '' ); ?>
				<p class="description" style="text-align:left;">
					<?php esc_html_e( 'For local sites Hey Support cannot reach, and for support.', 'hey-support-ai-chatbot' ); ?>
				</p>
				<?php submit_button( __( 'Save', 'hey-support-ai-chatbot' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
	</div>
	<?php
}

/**
 * Connected: what is true, and how to change it.
 *
 * It says the widget is on the site, which is what this screen can actually
 * know. Whether anyone has USED it is a different question, and the dashboard is
 * where that gets answered -- so it links there rather than inventing a status.
 */
function hey_support_render_connected_header(): void {
	?>
	<div class="hs-card">
		<div class="hs-status">
			<span class="hs-dot" aria-hidden="true"></span>
			<span class="hs-status-name"><?php esc_html_e( 'Your agent is live on this site', 'hey-support-ai-chatbot' ); ?></span>
			<span class="hs-status-meta"><?php echo esc_html( hey_support_site_host() ); ?></span>
			<span class="hs-status-actions">
				<a class="button" target="_blank" rel="noopener"
					href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard' ); ?>">
					<?php esc_html_e( 'Open dashboard', 'hey-support-ai-chatbot' ); ?>
				</a>
				<?php hey_support_connect_button( false ); ?>
			</span>
		</div>
	</div>
	<?php
}

/**
 * The connect form.
 *
 * Deliberately its own form, outside the settings form: navigating away from a
 * form discards unsaved edits, so Connect must never be a button sitting inside
 * one.
 */
function hey_support_connect_button( bool $primary ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
		<input type="hidden" name="action" value="hey_support_connect" />
		<?php wp_nonce_field( 'hey_support_connect' ); ?>
		<button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?>">
			<?php
			echo $primary
				? esc_html__( 'Connect to Hey Support', 'hey-support-ai-chatbot' )
				: esc_html__( 'Change agent', 'hey-support-ai-chatbot' );
			?>
		</button>
	</form>
	<?php
}

function hey_support_render_placement_card( array $opts ): void {
	$choices = [
		'all'            => __( 'Everywhere', 'hey-support-ai-chatbot' ),
		'posts'          => __( 'Posts only', 'hey-support-ai-chatbot' ),
		'pages'          => __( 'Pages only', 'hey-support-ai-chatbot' ),
		'shortcode-only' => __( 'Only where I place it with the block or shortcode', 'hey-support-ai-chatbot' ),
	];
	?>
	<div class="hs-card">
		<h2><?php esc_html_e( 'Where it appears', 'hey-support-ai-chatbot' ); ?></h2>
		<p class="hs-card-desc"><?php esc_html_e( 'The chat button sits in the corner of every page you choose.', 'hey-support-ai-chatbot' ); ?></p>

		<div class="hs-field">
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Where it appears', 'hey-support-ai-chatbot' ); ?></legend>
				<?php foreach ( $choices as $value => $label ) : ?>
					<label class="hs-radio">
						<input type="radio"
							name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[enable_on]"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $opts['enable_on'], $value ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>

		<div class="hs-field">
			<label for="hey-support-exclude-search"><?php esc_html_e( 'Except on these pages', 'hey-support-ai-chatbot' ); ?></label>
			<ul class="hs-chips" id="hey-support-chips"></ul>
			<div class="hs-picker">
				<input type="text" id="hey-support-exclude-search" class="regular-text"
					autocomplete="off" role="combobox" aria-expanded="false"
					aria-controls="hey-support-results"
					placeholder="<?php echo esc_attr__( 'Search pages and posts by name', 'hey-support-ai-chatbot' ); ?>" />
				<ul class="hs-results" id="hey-support-results" role="listbox"></ul>
			</div>
			<input type="hidden" id="hey-support-exclude-ids"
				name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[exclude_ids]"
				value="<?php echo esc_attr( $opts['exclude_ids'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Search by name. Leave empty to show it on everything above.', 'hey-support-ai-chatbot' ); ?></p>
		</div>
	</div>
	<?php
}

/** WooCommerce, in one card rather than two rows that read as unrelated. */
function hey_support_render_commerce_card(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	?>
	<div class="hs-card">
		<h2><?php esc_html_e( 'Your store', 'hey-support-ai-chatbot' ); ?></h2>
		<p class="hs-card-desc">
			<?php esc_html_e( 'Your agent can show real products from this store, with live prices and stock, and add them to the shopper\'s cart. No keys needed - it reads your public store data.', 'hey-support-ai-chatbot' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="hey-support-storefront-test">
				<?php esc_html_e( 'Check my store is reachable', 'hey-support-ai-chatbot' ); ?>
			</button>
			<a class="button" target="_blank" rel="noopener"
				href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard?connect=woocommerce_storefront&site_url=' . rawurlencode( home_url() ) ); ?>">
				<?php esc_html_e( 'Manage in Hey Support', 'hey-support-ai-chatbot' ); ?>
			</a>
		</p>
		<p class="description" id="hey-support-storefront-result"></p>

		<p class="hs-card-desc" style="margin-top:18px;margin-bottom:6px;">
			<strong><?php esc_html_e( 'Order lookups', 'hey-support-ai-chatbot' ); ?></strong><br />
			<?php esc_html_e( 'Let your agent look up orders, update shipping and issue coupons. One approval in your own WooCommerce admin - the key is created and sent for you.', 'hey-support-ai-chatbot' ); ?>
		</p>
		<p>
			<a class="button" target="_blank" rel="noopener"
				href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard?connect=woocommerce&site_url=' . rawurlencode( home_url() ) ); ?>">
				<?php esc_html_e( 'Set up order lookups', 'hey-support-ai-chatbot' ); ?>
			</a>
		</p>
	</div>
	<?php
}

function hey_support_render_advanced_card( array $opts ): void {
	?>
	<div class="hs-card">
		<details class="hs-advanced">
			<summary><?php esc_html_e( 'Advanced', 'hey-support-ai-chatbot' ); ?></summary>

			<div class="hs-field">
				<?php hey_support_chatbot_id_input( (string) $opts['chatbot_id'] ); ?>
				<p class="description"><?php esc_html_e( 'Set for you when you connect. Change it only if support asks you to.', 'hey-support-ai-chatbot' ); ?></p>
			</div>

			<div class="hs-field">
				<label for="hey_support_auto_open"><?php esc_html_e( 'Auto-open', 'hey-support-ai-chatbot' ); ?></label>
				<label class="hs-radio">
					<input type="checkbox" id="hey_support_auto_open"
						name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[auto_open]"
						value="1" <?php checked( ! empty( $opts['auto_open'] ) ); ?> />
					<?php esc_html_e( 'Open the chat automatically shortly after the page loads.', 'hey-support-ai-chatbot' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Off is usually better. Opening on its own interrupts whatever the visitor came to do.', 'hey-support-ai-chatbot' ); ?></p>
			</div>

			<div class="hs-field">
				<label><?php esc_html_e( 'Place it yourself', 'hey-support-ai-chatbot' ); ?></label>
				<p class="hs-inline-note">
					<?php esc_html_e( 'Use the Hey Support block, or this shortcode:', 'hey-support-ai-chatbot' ); ?>
					<code>[hey_support]</code>
				</p>
			</div>
		</details>
	</div>
	<?php
}

function hey_support_chatbot_id_input( string $value ): void {
	?>
	<label for="hey_support_chatbot_id"><?php esc_html_e( 'Chatbot ID', 'hey-support-ai-chatbot' ); ?></label>
	<input type="text" id="hey_support_chatbot_id"
		name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[chatbot_id]"
		value="<?php echo esc_attr( $value ); ?>" class="regular-text"
		placeholder="<?php echo esc_attr__( 'e.g. ncimu', 'hey-support-ai-chatbot' ); ?>" />
	<?php
}

function hey_support_site_host(): string {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) ? $host : home_url();
}

/** Show and clear the one-shot connect outcome. */
function hey_support_flush_connect_result(): void {
	$result = get_transient( 'hey_support_connect_result' );
	if ( ! $result ) {
		return;
	}
	delete_transient( 'hey_support_connect_result' );
	$name = get_transient( 'hey_support_connected_name' );
	delete_transient( 'hey_support_connected_name' );
	hey_support_connect_result_notice( (string) $result, is_string( $name ) ? $name : '' );
}

/** One line per outcome, naming the fix where there is one. */
function hey_support_connect_result_notice( string $code, string $name ): void {
	$ok = 'ok' === $code;
	switch ( $code ) {
		case 'ok':
			$message = '' !== $name
				/* translators: %s: the connected agent's name. */
				? sprintf( __( 'Connected to %s. Your agent is live on this site.', 'hey-support-ai-chatbot' ), $name )
				: __( 'Connected. Your agent is live on this site.', 'hey-support-ai-chatbot' );
			break;
		case 'expired':
			$message = __( 'That connect link had expired. Press Connect to try again.', 'hey-support-ai-chatbot' );
			break;
		case 'wrong_user':
			$message = __( 'That connect link was started by a different user. Press Connect to start your own.', 'hey-support-ai-chatbot' );
			break;
		case 'network':
			$message = __( 'We could not reach Hey Support from this server. Check outbound connections and try again.', 'hey-support-ai-chatbot' );
			break;
		default:
			$message = __( 'Hey Support could not confirm that connection. Press Connect to try again.', 'hey-support-ai-chatbot' );
	}
	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		$ok ? 'success' : 'error',
		esc_html( $message )
	);
}

// --- AJAX: Store API reachability self-check --------------------------------

/**
 * Ask THIS site whether its own Store API answers, and report the fix in the
 * place the merchant can apply it.
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
