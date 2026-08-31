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
 * BRANDED MASTHEAD, NATIVE BODY -- the shape Jetpack, WooCommerce and Rank Math
 * all settled on. The masthead is a self-contained cream surface, so it cannot
 * clash with any of the admin colour schemes; everything below it uses WordPress
 * controls, WordPress button classes and WordPress spacing.
 *
 * No accent is invented anywhere. Interactive colour comes from WordPress's own
 * `.button-primary` and its own form controls, which already carry whichever of
 * the eight admin colour schemes the user picked; a hand-chosen brand blue would
 * be wrong on seven of them. What makes the screen feel considered is the type
 * scale, the hierarchy and the structure, not paint.
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
		[ 'dashicons' ],
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
				'copy'       => __( 'Copy', 'hey-support-ai-chatbot' ),
				'copied'     => __( 'Copied', 'hey-support-ai-chatbot' ),
				'checking'   => __( 'Checking...', 'hey-support-ai-chatbot' ),
				'confirmOff' => __( 'Disconnect this agent? The chat widget will stop appearing on your site. Your conversations and settings in Hey Support are not affected.', 'hey-support-ai-chatbot' ),
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
	?>
	<div class="wrap hs-wrap">
		<?php hey_support_render_masthead( $opts, $connected ); ?>
		<?php hey_support_flush_connect_result(); ?>

		<?php if ( ! $connected ) : ?>
			<?php hey_support_render_connect_card(); ?>
		<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'hey_support_settings_group' ); ?>
				<?php hey_support_render_placement_card( $opts ); ?>
				<?php hey_support_render_commerce_card(); ?>
				<?php hey_support_render_advanced_card( $opts ); ?>
				<div class="hs-actions">
					<?php submit_button( __( 'Save changes', 'hey-support-ai-chatbot' ), 'primary', 'submit', false ); ?>
					<span class="hs-actions-note">
						<?php esc_html_e( 'Placement changes take effect immediately. Nothing here changes what your agent says.', 'hey-support-ai-chatbot' ); ?>
					</span>
				</div>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The masthead: identity on the left, connection state on the right.
 *
 * The connection state used to be a fourth card that looked exactly like the
 * three setting cards below it, which was wrong twice over -- it is a statement
 * of fact rather than a control, and it pushed the actual controls a whole card
 * further down. Folding it in here deletes a card and gives the top of the page
 * something to say.
 *
 * @param array<string, mixed> $opts      Merged settings.
 * @param bool                 $connected Whether an agent is linked.
 */
function hey_support_render_masthead( array $opts, bool $connected ): void {
	$name = trim( (string) ( $opts['chatbot_name'] ?? '' ) );
	?>
	<div class="hs-masthead">
		<div class="hs-masthead-id">
			<img class="hs-logo"
				src="<?php echo esc_url( HEY_SUPPORT_URL . 'admin/logo-lockup.svg' ); ?>"
				width="139" height="30"
				alt="<?php esc_attr_e( 'Hey Support', 'hey-support-ai-chatbot' ); ?>" />
			<h1><?php esc_html_e( 'Hey Support for WordPress', 'hey-support-ai-chatbot' ); ?></h1>
			<p class="hs-lede">
				<?php
				echo $connected
					? esc_html__( 'This screen controls where your chat widget appears on this site and what it can read from your store. How the agent looks, what it knows and what it says are set in your Hey Support dashboard.', 'hey-support-ai-chatbot' )
					: esc_html__( 'Add an AI agent to this site that answers your visitors around the clock. Connect it below, then choose where the chat button appears. Everything the agent knows and says is set in your Hey Support dashboard.', 'hey-support-ai-chatbot' );
				?>
			</p>
		</div>

		<div class="hs-masthead-state">
			<?php if ( $connected ) : ?>
				<p class="hs-state">
					<span class="hs-dot" aria-hidden="true"></span>
					<span class="hs-state-text">
						<strong>
							<?php
							echo '' !== $name
								/* translators: %s: the connected agent's name. */
								? esc_html( sprintf( __( 'Connected to %s', 'hey-support-ai-chatbot' ), $name ) )
								: esc_html__( 'Connected', 'hey-support-ai-chatbot' );
							?>
						</strong>
						<span class="hs-state-host"><?php echo esc_html( hey_support_site_host() ); ?></span>
					</span>
				</p>
				<p class="hs-state-actions">
					<a class="button button-primary" target="_blank" rel="noopener"
						href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard' ); ?>">
						<?php esc_html_e( 'Open dashboard', 'hey-support-ai-chatbot' ); ?>
					</a>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php esc_html_e( 'View your site', 'hey-support-ai-chatbot' ); ?>
					</a>
				</p>
				<p class="hs-state-sub">
					<?php hey_support_connect_button( false ); ?>
				</p>
			<?php else : ?>
				<p class="hs-state hs-state-off">
					<span class="hs-dot hs-dot-off" aria-hidden="true"></span>
					<span class="hs-state-text">
						<strong><?php esc_html_e( 'Not connected', 'hey-support-ai-chatbot' ); ?></strong>
						<span class="hs-state-host"><?php echo esc_html( hey_support_site_host() ); ?></span>
					</span>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
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
			<?php esc_html_e( 'One button. You will sign in to Hey Support, pick which agent belongs on this site, and land back here with it live. There is nothing to copy, paste or configure.', 'hey-support-ai-chatbot' ); ?>
		</p>
		<?php hey_support_connect_button( true ); ?>
		<ul class="hs-steps">
			<li><?php esc_html_e( 'Sign in or create a free account', 'hey-support-ai-chatbot' ); ?></li>
			<li><?php esc_html_e( 'Choose the agent for this site', 'hey-support-ai-chatbot' ); ?></li>
			<li><?php esc_html_e( 'Come back here and choose where it appears', 'hey-support-ai-chatbot' ); ?></li>
		</ul>
		<p class="hs-manual-row">
			<button type="button" class="button-link hs-manual-toggle" aria-expanded="false" aria-controls="hs-manual">
				<?php esc_html_e( 'Enter a chatbot ID instead', 'hey-support-ai-chatbot' ); ?>
			</button>
		</p>
		<div class="hs-manual" id="hs-manual" hidden>
			<form method="post" action="options.php">
				<?php settings_fields( 'hey_support_settings_group' ); ?>
				<?php hey_support_chatbot_id_input( '' ); ?>
				<p class="description">
					<?php esc_html_e( 'For sites Hey Support cannot reach from the internet, such as a local install, and for when support asks you to.', 'hey-support-ai-chatbot' ); ?>
				</p>
				<?php submit_button( __( 'Save', 'hey-support-ai-chatbot' ), 'secondary', 'submit', false ); ?>
			</form>
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
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hs-inline-form">
		<input type="hidden" name="action" value="hey_support_connect" />
		<?php wp_nonce_field( 'hey_support_connect' ); ?>
		<button type="submit" class="<?php echo $primary ? 'button button-primary button-hero' : 'button-link'; ?>">
			<?php
			echo $primary
				? esc_html__( 'Connect to Hey Support', 'hey-support-ai-chatbot' )
				: esc_html__( 'Connect a different agent', 'hey-support-ai-chatbot' );
			?>
		</button>
	</form>
	<?php
}

/**
 * Card header: dashicon, title, description.
 *
 * Dashicons rather than an icon set of our own -- they ship with WordPress, cost
 * nothing to load, and already track the admin colour schemes. The point of them
 * here is scanning, not decoration: a settings screen with no glyphs is read
 * linearly, which is why the old one felt like a wall.
 */
function hey_support_card_head( string $icon, string $title, string $desc ): void {
	?>
	<div class="hs-card-head">
		<div class="hs-card-title">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
		</div>
		<p class="hs-card-desc"><?php echo esc_html( $desc ); ?></p>
	</div>
	<?php
}

function hey_support_render_placement_card( array $opts ): void {
	// Each option carries the consequence of choosing it. A bare radio label
	// makes the reader simulate the outcome; one short line states it.
	$choices = [
		'all'            => [
			__( 'Everywhere', 'hey-support-ai-chatbot' ),
			__( 'Every page, post and product on your site. This is the right answer for almost everyone.', 'hey-support-ai-chatbot' ),
		],
		'posts'          => [
			__( 'Blog posts only', 'hey-support-ai-chatbot' ),
			__( 'Posts and the pages that list them. Not your pages or products.', 'hey-support-ai-chatbot' ),
		],
		'pages'          => [
			__( 'Pages only', 'hey-support-ai-chatbot' ),
			__( 'Pages such as Home, About and Contact. Not your blog posts.', 'hey-support-ai-chatbot' ),
		],
		'shortcode-only' => [
			__( 'Only where I place it', 'hey-support-ai-chatbot' ),
			__( 'Nothing appears until you add the Hey Support block or the shortcode to a page yourself.', 'hey-support-ai-chatbot' ),
		],
	];
	?>
	<div class="hs-card">
		<?php
		hey_support_card_head(
			'dashicons-visibility',
			__( 'Where it appears', 'hey-support-ai-chatbot' ),
			__( 'The chat button sits in the bottom corner of whichever pages you choose. Visitors tap it to start a conversation.', 'hey-support-ai-chatbot' )
		);
		?>

		<fieldset class="hs-choices">
			<legend class="screen-reader-text"><?php esc_html_e( 'Where it appears', 'hey-support-ai-chatbot' ); ?></legend>
			<?php foreach ( $choices as $value => $choice ) : ?>
				<label class="hs-choice">
					<input type="radio"
						name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[enable_on]"
						value="<?php echo esc_attr( $value ); ?>"
						<?php checked( $opts['enable_on'], $value ); ?> />
					<span class="hs-choice-text">
						<span class="hs-choice-title"><?php echo esc_html( $choice[0] ); ?></span>
						<span class="hs-choice-note"><?php echo esc_html( $choice[1] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<div class="hs-subfield" id="hs-exclusions">
			<label class="hs-label" for="hey-support-exclude-search">
				<?php esc_html_e( 'Except on these pages', 'hey-support-ai-chatbot' ); ?>
			</label>
			<p class="hs-help">
				<?php esc_html_e( 'Add any page you would rather keep quiet, such as checkout or a landing page you are running ads to. Leave this empty to show the widget everywhere you chose above.', 'hey-support-ai-chatbot' ); ?>
			</p>
			<ul class="hs-chips" id="hey-support-chips"></ul>
			<div class="hs-picker">
				<input type="text" id="hey-support-exclude-search" class="regular-text"
					autocomplete="off" role="combobox" aria-expanded="false"
					aria-controls="hey-support-results"
					placeholder="<?php echo esc_attr__( 'Start typing a page or post name', 'hey-support-ai-chatbot' ); ?>" />
				<ul class="hs-results" id="hey-support-results" role="listbox"></ul>
			</div>
			<?php // Never disabled, even when the section is: a disabled input submits nothing, which would silently wipe the saved list. ?>
			<input type="hidden" id="hey-support-exclude-ids"
				name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[exclude_ids]"
				value="<?php echo esc_attr( $opts['exclude_ids'] ); ?>" />
			<p class="hs-subfield-off">
				<?php esc_html_e( 'Not used while the widget only appears where you place it yourself. Your list is kept.', 'hey-support-ai-chatbot' ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * WooCommerce, as two real sections rather than one paragraph with a bold line
 * in the middle of it.
 *
 * They are genuinely different things -- one is public and needs no permission,
 * the other mints a key and needs an explicit grant -- and the old card said so
 * with a <strong>, which reads as an afterthought bolted onto the first.
 */
function hey_support_render_commerce_card(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	?>
	<div class="hs-card">
		<?php
		hey_support_card_head(
			'dashicons-cart',
			__( 'Your store', 'hey-support-ai-chatbot' ),
			__( 'WooCommerce is active on this site, so your agent can help people shop as well as answer questions.', 'hey-support-ai-chatbot' )
		);
		?>

		<div class="hs-section">
			<h3><?php esc_html_e( 'Product answers', 'hey-support-ai-chatbot' ); ?></h3>
			<p class="hs-help">
				<?php esc_html_e( 'Your agent shows real products from this store with live prices and stock, and can add them to the shopper\'s basket. No keys and no setup - it reads the public store data any visitor can already see.', 'hey-support-ai-chatbot' ); ?>
			</p>
			<p class="hs-row">
				<a class="button button-primary" target="_blank" rel="noopener"
					href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard?connect=woocommerce_storefront&site_url=' . rawurlencode( home_url() ) ); ?>">
					<?php esc_html_e( 'Turn on product answers', 'hey-support-ai-chatbot' ); ?>
				</a>
				<button type="button" class="button" id="hey-support-storefront-test">
					<?php esc_html_e( 'Test this site first', 'hey-support-ai-chatbot' ); ?>
				</button>
			</p>
			<p class="hs-result" id="hey-support-storefront-result" role="status"></p>
		</div>

		<div class="hs-section">
			<h3><?php esc_html_e( 'Order lookups', 'hey-support-ai-chatbot' ); ?></h3>
			<p class="hs-help">
				<?php esc_html_e( 'Let your agent answer "where is my order?", update a shipping address and issue a coupon. This one needs your permission because it reads customer orders: you approve it once in your own WooCommerce admin and the key is created and sent for you.', 'hey-support-ai-chatbot' ); ?>
			</p>
			<p class="hs-row">
				<a class="button" target="_blank" rel="noopener"
					href="<?php echo esc_url( HEY_SUPPORT_API_BASE . '/dashboard?connect=woocommerce&site_url=' . rawurlencode( home_url() ) ); ?>">
					<?php esc_html_e( 'Set up order lookups', 'hey-support-ai-chatbot' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php
}

function hey_support_render_advanced_card( array $opts ): void {
	?>
	<div class="hs-card hs-card-flush">
		<details class="hs-advanced">
			<summary>
				<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Advanced', 'hey-support-ai-chatbot' ); ?></span>
				<span class="hs-summary-note"><?php esc_html_e( 'Identity, auto-open, shortcode, disconnect', 'hey-support-ai-chatbot' ); ?></span>
			</summary>

			<div class="hs-advanced-body">
				<div class="hs-field">
					<span class="hs-label"><?php esc_html_e( 'Chatbot ID', 'hey-support-ai-chatbot' ); ?></span>
					<p class="hs-help"><?php esc_html_e( 'Which agent this site is linked to. Set for you when you connect, and safe to share - it is visible in your site\'s page source. Change it only if support asks you to.', 'hey-support-ai-chatbot' ); ?></p>
					<div class="hs-copyrow">
						<code><?php echo esc_html( (string) $opts['chatbot_id'] ); ?></code>
						<button type="button" class="button button-small hs-copy"
							data-copy="<?php echo esc_attr( (string) $opts['chatbot_id'] ); ?>">
							<?php esc_html_e( 'Copy', 'hey-support-ai-chatbot' ); ?>
						</button>
						<button type="button" class="button-link hs-edit-toggle"
							aria-expanded="false" aria-controls="hs-id-edit">
							<?php esc_html_e( 'Edit', 'hey-support-ai-chatbot' ); ?>
						</button>
					</div>
					<?php
					// `hidden` on the wrapper does not stop the input submitting -- only
					// `disabled` would -- so this one field carries the value whether the
					// section is open or shut. A second hidden mirror would have been the
					// bug: two inputs of the same name means the last one wins, so the
					// mirror would have silently overwritten every edit.
					?>
					<div class="hs-id-edit" id="hs-id-edit" hidden>
						<?php hey_support_chatbot_id_input( (string) $opts['chatbot_id'] ); ?>
					</div>
				</div>

				<div class="hs-field">
					<span class="hs-label"><?php esc_html_e( 'Opening behaviour', 'hey-support-ai-chatbot' ); ?></span>
					<label class="hs-check">
						<input type="checkbox" id="hey_support_auto_open"
							name="<?php echo esc_attr( HEY_SUPPORT_OPTION_KEY ); ?>[auto_open]"
							value="1" <?php checked( ! empty( $opts['auto_open'] ) ); ?> />
						<span><?php esc_html_e( 'Open the chat by itself a moment after the page loads', 'hey-support-ai-chatbot' ); ?></span>
					</label>
					<p class="hs-help"><?php esc_html_e( 'Off is usually better. Opening on its own interrupts whatever the visitor came to do, and the chat button is already hard to miss.', 'hey-support-ai-chatbot' ); ?></p>
				</div>

				<div class="hs-field">
					<span class="hs-label"><?php esc_html_e( 'Place it yourself', 'hey-support-ai-chatbot' ); ?></span>
					<p class="hs-help"><?php esc_html_e( 'Add the Hey Support block in the editor, or paste this shortcode. Either one loads the widget on that page even when you have chosen "Only where I place it" above.', 'hey-support-ai-chatbot' ); ?></p>
					<div class="hs-copyrow">
						<code>[hey_support]</code>
						<button type="button" class="button button-small hs-copy" data-copy="[hey_support]">
							<?php esc_html_e( 'Copy', 'hey-support-ai-chatbot' ); ?>
						</button>
					</div>
				</div>

				<div class="hs-field hs-danger">
					<span class="hs-label"><?php esc_html_e( 'Disconnect', 'hey-support-ai-chatbot' ); ?></span>
					<p class="hs-help"><?php esc_html_e( 'Unlink this site from the agent and stop showing the chat widget. Your conversations, leads and settings in Hey Support are untouched, and you can connect again at any time.', 'hey-support-ai-chatbot' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hs-inline-form">
						<input type="hidden" name="action" value="hey_support_disconnect" />
						<?php wp_nonce_field( 'hey_support_disconnect' ); ?>
						<button type="submit" class="button hs-disconnect">
							<?php esc_html_e( 'Disconnect this site', 'hey-support-ai-chatbot' ); ?>
						</button>
					</form>
				</div>
			</div>
		</details>
	</div>
	<?php
}

function hey_support_chatbot_id_input( string $value ): void {
	?>
	<label class="screen-reader-text" for="hey_support_chatbot_id"><?php esc_html_e( 'Chatbot ID', 'hey-support-ai-chatbot' ); ?></label>
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
	$ok = 'ok' === $code || 'disconnected' === $code;
	switch ( $code ) {
		case 'ok':
			$message = '' !== $name
				/* translators: %s: the connected agent's name. */
				? sprintf( __( 'Connected to %s. Your agent is live on this site.', 'hey-support-ai-chatbot' ), $name )
				: __( 'Connected. Your agent is live on this site.', 'hey-support-ai-chatbot' );
			break;
		case 'disconnected':
			$message = __( 'Disconnected. The chat widget is no longer shown on this site.', 'hey-support-ai-chatbot' );
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
			'message' => sprintf( _n( 'Ready - %d product is visible to your agent.', 'Ready - %d products are visible to your agent.', $total, 'hey-support-ai-chatbot' ), $total ),
		]
	);
}
