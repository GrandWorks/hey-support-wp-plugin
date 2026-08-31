<?php
/**
 * One-click connect.
 *
 * The admin presses Connect, picks an agent on hey.support, and lands back here
 * connected. Nothing is typed and no ID is copied.
 *
 * HOW IT IS SAFE. We mint a random `verifier`, keep it on THIS server, and send
 * only its hash (the `challenge`). Before hey.support shows anyone a picker it
 * fetches that hash back from this site, at the REST route below. Only a site
 * whose admin actually pressed Connect holds the record that produces it, which
 * is what proves WHICH site is being connected -- a thing the redirect alone can
 * never prove, since anyone can put any domain in a URL.
 *
 * The last step is then a server-to-server call from here, carrying the
 * verifier. That is why a forged redirect is worthless: finishing the flow needs
 * a secret that never left this server.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

/** Where the in-flight handshakes live. One option row, keyed by state. */
const HEY_SUPPORT_LINK_OPTION = 'hey_support_links';

/**
 * Long on purpose. The flow can include creating a Hey Support account AND
 * clicking a verification email, possibly on another device an hour later. The
 * SHORT window lives on the server side and covers only the final exchange.
 */
const HEY_SUPPORT_LINK_TTL = 3600;

add_action( 'rest_api_init', 'hey_support_register_connect_route' );
add_action( 'admin_post_hey_support_connect', 'hey_support_handle_connect' );
add_action( 'admin_post_hey_support_disconnect', 'hey_support_handle_disconnect' );
add_action( 'admin_init', 'hey_support_maybe_finish_connect' );
add_filter( 'allowed_redirect_hosts', 'hey_support_allow_api_host' );

/**
 * Read the in-flight handshakes, dropping anything expired.
 *
 * A wp_options row rather than a transient, deliberately. With an external
 * object cache -- Redis or Memcached, standard on managed WordPress -- transients
 * are evictable at any moment, so a 10-minute transient is a hint, not a
 * guarantee, and this flow would fail at random under memory pressure.
 *
 * Keyed by state so two admins connecting at once cannot silently cancel each
 * other, which a single site-wide record would do.
 *
 * @return array<string, array<string, mixed>>
 */
function hey_support_link_all(): array {
	$raw = get_option( HEY_SUPPORT_LINK_OPTION, [] );
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$now  = time();
	$live = [];
	foreach ( $raw as $state => $row ) {
		if ( ! is_array( $row ) || empty( $row['created_at'] ) ) {
			continue;
		}
		if ( ( (int) $row['created_at'] ) + HEY_SUPPORT_LINK_TTL < $now ) {
			continue;
		}
		$live[ (string) $state ] = $row;
	}
	return $live;
}

/**
 * Store one in-flight handshake. Caps the row so an admin repeatedly pressing
 * Connect cannot grow the option without bound.
 */
function hey_support_link_put( string $state, array $row ): void {
	$all           = hey_support_link_all();
	$all[ $state ] = $row;
	if ( count( $all ) > 5 ) {
		$all = array_slice( $all, -5, null, true );
	}
	update_option( HEY_SUPPORT_LINK_OPTION, $all, false );
}

/**
 * Consume one in-flight handshake. Single-use: a replayed return URL finds
 * nothing.
 *
 * @return array<string, mixed>|null
 */
function hey_support_link_take( string $state ): ?array {
	$all = hey_support_link_all();
	if ( ! isset( $all[ $state ] ) ) {
		return null;
	}
	$row = $all[ $state ];
	unset( $all[ $state ] );
	update_option( HEY_SUPPORT_LINK_OPTION, $all, false );
	return is_array( $row ) ? $row : null;
}

/**
 * The route hey.support probes to prove this site started a handshake.
 *
 * Public by necessity -- the caller is hey.support's server, which has no
 * WordPress session. It is safe to be public because it answers only for a state
 * this site minted, and what it returns is a hash we already put in the URL.
 */
function hey_support_register_connect_route(): void {
	register_rest_route(
		'hey-support/v1',
		'/handshake',
		[
			'methods'             => 'GET',
			'callback'            => 'hey_support_rest_handshake',
			'permission_callback' => '__return_true',
			'args'                => [
				'state' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);
}

/**
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function hey_support_rest_handshake( $request ) {
	$state = (string) $request->get_param( 'state' );
	$all   = hey_support_link_all();

	if ( '' === $state || ! isset( $all[ $state ] ) ) {
		return new WP_Error(
			'hey_support_unknown_state',
			__( 'Unknown or expired handshake.', 'hey-support-ai-chatbot' ),
			[ 'status' => 404 ]
		);
	}

	// NOT consumed here. hey.support probes once to render the picker and again
	// when the operator submits; consuming on the first would break the second.
	// The single-use guard is on the return leg, where it belongs.
	return new WP_REST_Response(
		[
			'challenge' => (string) $all[ $state ]['challenge'],
			// Computed by WordPress, because only WordPress knows it. This is a
			// different setting from home_url(): WordPress can live in a
			// subdirectory while the site answers at the root, wp-admin can be
			// relocated, FORCE_SSL_ADMIN can change the scheme, and multisite
			// differs again. hey.support redirects here, so it has to be right.
			'admin_url' => hey_support_settings_url(),
			'site_name' => get_bloginfo( 'name' ),
		],
		200
	);
}

/** The plugin's own settings screen, absolute. */
function hey_support_settings_url(): string {
	return admin_url( 'options-general.php?page=hey-support-ai-chatbot' );
}

/**
 * Start the handshake.
 *
 * A nonce-checked POST rather than a link, because it WRITES (it mints the
 * verifier). As a bare anchor, any page could make an admin's browser burn the
 * record, and the TTL would start when the settings page rendered rather than
 * when they clicked.
 */
function hey_support_handle_connect(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'hey-support-ai-chatbot' ) );
	}
	check_admin_referer( 'hey_support_connect' );

	$verifier  = bin2hex( random_bytes( 32 ) );
	$state     = bin2hex( random_bytes( 16 ) );
	$challenge = hey_support_base64url( hash( 'sha256', $verifier, true ) );

	hey_support_link_put(
		$state,
		[
			'verifier'   => $verifier,
			'challenge'  => $challenge,
			// Bound to the admin who started it: without this, admin B pressing
			// Connect would let them finish admin A's handshake.
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
		]
	);

	$args = [
		'state'     => $state,
		'challenge' => $challenge,
		'site_url'  => home_url(),
	];
	if ( class_exists( 'WooCommerce' ) ) {
		$args['woo'] = '1';
	}

	// Top-level navigation, never a new tab: the return redirect has to land in
	// the SAME tab, or the original settings screen sits there saying "not
	// connected" forever.
	//
	// wp_safe_redirect, not wp_redirect: the destination host is allowed by the
	// narrow filter below rather than by trusting this call site. The URL is
	// built from a constant today, but a redirect that can only ever reach one
	// known host stays correct even if that stops being true.
	wp_safe_redirect( add_query_arg( $args, HEY_SUPPORT_API_BASE . '/connect/wordpress' ) );
	exit;
}

/**
 * Permit redirects to the Hey Support host, and nothing else.
 *
 * wp_safe_redirect() refuses any host but this site's own, which is the correct
 * default and exactly wrong for a handshake that has to leave. Deriving the host
 * from HEY_SUPPORT_API_BASE means a wp-config override for local development
 * keeps working without widening anything.
 *
 * @param string[] $hosts Hosts WordPress will redirect to.
 * @return string[]
 */
function hey_support_allow_api_host( $hosts ): array {
	$hosts = is_array( $hosts ) ? $hosts : [];
	$host  = wp_parse_url( HEY_SUPPORT_API_BASE, PHP_URL_HOST );
	if ( is_string( $host ) && '' !== $host ) {
		$hosts[] = $host;
	}
	return $hosts;
}

/** base64url, no padding -- matching the server's digest('base64url'). */
function hey_support_base64url( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Finish the handshake when the browser lands back here.
 *
 * Auto-completing on a GET is only defensible because all three of these hold:
 * the state is 16 random bytes we minted, it is bound to this exact user, and
 * the capability is re-checked. A mail scanner or a prefetch cannot satisfy the
 * second or third.
 */
function hey_support_maybe_finish_connect(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The state token IS the credential here: 16 random bytes minted by this site, bound to this user id, and single-use. A nonce would add nothing a third party could not equally lack.
	$state = isset( $_GET['hs_state'] ) ? sanitize_text_field( wp_unslash( $_GET['hs_state'] ) ) : '';
	if ( '' === $state ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$row = hey_support_link_take( $state );
	if ( ! $row || empty( $row['verifier'] ) ) {
		hey_support_connect_notice( 'expired' );
		return;
	}
	if ( (int) ( $row['user_id'] ?? 0 ) !== get_current_user_id() ) {
		hey_support_connect_notice( 'wrong_user' );
		return;
	}

	$res = wp_remote_post(
		HEY_SUPPORT_API_BASE . '/api/oauth/wordpress/exchange',
		[
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode(
				[
					'state'    => $state,
					'verifier' => $row['verifier'],
				]
			),
		]
	);

	if ( is_wp_error( $res ) ) {
		hey_support_connect_notice( 'network' );
		return;
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $body['chatbot_id'] ) ) {
		hey_support_connect_notice( 'rejected' );
		return;
	}

	// Read-modify-write. hey_support_sanitize_options rebuilds the whole array,
	// so writing a bare ['chatbot_id' => ...] would destroy enable_on,
	// exclude_ids, auto_open and the saved token.
	$name = sanitize_text_field( (string) ( $body['chatbot_name'] ?? '' ) );

	$opts                 = hey_support_get_options();
	$opts['chatbot_id']   = sanitize_text_field( (string) $body['chatbot_id'] );
	$opts['chatbot_name'] = $name;
	update_option( HEY_SUPPORT_OPTION_KEY, $opts );

	// Also kept for the one-shot success notice, which fires on the very next
	// request. The stored copy above is what the settings screen reads from then
	// on -- the name used to live ONLY in this transient, so it was shown once
	// and thrown away, leaving the screen permanently unable to say which agent
	// it was actually connected to.
	set_transient( 'hey_support_connected_name', $name, 60 );
	hey_support_connect_notice( 'ok' );
}

/**
 * Unlink this site from its agent.
 *
 * Clears only the identity. `enable_on`, `exclude_ids` and `auto_open` survive,
 * so reconnecting later restores the placement the operator had already chosen
 * rather than resetting them to defaults -- and nothing at all is touched on the
 * Hey Support side, where the conversations and settings live.
 */
function hey_support_handle_disconnect(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'hey-support-ai-chatbot' ) );
	}
	check_admin_referer( 'hey_support_disconnect' );

	$opts                 = hey_support_get_options();
	$opts['chatbot_id']   = '';
	$opts['chatbot_name'] = '';
	update_option( HEY_SUPPORT_OPTION_KEY, $opts );

	hey_support_connect_notice( 'disconnected' );
	wp_safe_redirect( hey_support_settings_url() );
	exit;
}

/** Carry a one-shot result to the settings screen. */
function hey_support_connect_notice( string $code ): void {
	set_transient( 'hey_support_connect_result', $code, 60 );
}
