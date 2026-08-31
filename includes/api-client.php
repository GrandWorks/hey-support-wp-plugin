<?php
/**
 * Minimal client for the Hey Support v1 public API. Used only by the admin
 * Settings page - Verify-token button + chatbot dropdown refresh. The public
 * embed never sees the token; it only carries the chatbot ID.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fire a Bearer-authed GET against the v1 API.
 *
 * @param string $path  API path (e.g. "/api/v1/me").
 * @param string $token API token.
 * @return array{ok:bool,status:int,body:mixed,error?:string}
 */
function hey_support_api_get( string $path, string $token ): array {
	$url = HEY_SUPPORT_API_BASE . $path;
	$res = wp_remote_get(
		$url,
		[
			'timeout' => 8,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
		]
	);

	if ( is_wp_error( $res ) ) {
		return [
			'ok'     => false,
			'status' => 0,
			'body'   => null,
			'error'  => $res->get_error_message(),
		];
	}

	$status = (int) wp_remote_retrieve_response_code( $res );
	$body   = json_decode( wp_remote_retrieve_body( $res ), true );

	return [
		'ok'     => $status >= 200 && $status < 300,
		'status' => $status,
		'body'   => $body,
	];
}

/**
 * GET /api/v1/me - used to validate the token and surface team_name in the UI.
 *
 * @return array{ok:bool,status:int,body:mixed,error?:string}
 */
function hey_support_api_me( string $token ): array {
	return hey_support_api_get( '/api/v1/me', $token );
}

/**
 * GET /api/v1/chatbots - paginated list, used to populate the chatbot dropdown.
 *
 * @return array{ok:bool,status:int,body:mixed,error?:string}
 */
function hey_support_api_chatbots( string $token ): array {
	return hey_support_api_get( '/api/v1/chatbots?limit=100', $token );
}
