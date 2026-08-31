/* global jQuery, heySupportAdmin */
(function ($) {
	'use strict';

	var OK_COLOR = '#1f7a1f';
	var ERR_COLOR = '#b3341d';

	function postAjax(action, payload) {
		return $.post(heySupportAdmin.ajaxUrl, $.extend({
			action: action,
			nonce: heySupportAdmin.nonce
		}, payload));
	}

	/**
	 * Map an API failure onto operator-facing copy.
	 *
	 * 403 is NOT a bad token: the Hey Support public API is gated to the Scale
	 * plan, so most installs get one with a perfectly valid key. Reporting that
	 * as "Invalid token" sent people off minting new keys that also failed.
	 */
	function failureMessage(status) {
		if (status === 403) { return heySupportAdmin.i18n.planGate; }
		if (status === 429) { return heySupportAdmin.i18n.rateLimited; }
		if (status === 0) { return heySupportAdmin.i18n.networkErr; }
		return heySupportAdmin.i18n.invalidToken;
	}

	function statusOf(resp) {
		return resp && resp.data && typeof resp.data.status === 'number' ? resp.data.status : null;
	}

	/** Server-named failures win over status-code guessing. */
	function codeOf(resp) {
		return resp && resp.data && typeof resp.data.code === 'string' ? resp.data.code : null;
	}

	function errorFor(resp) {
		if (codeOf(resp) === 'no_token') { return heySupportAdmin.i18n.noToken; }
		return failureMessage(statusOf(resp));
	}

	$(function () {
		var $verifyBtn = $('#hey-support-ai-chatbot-verify');
		var $verifyResult = $('#hey-support-ai-chatbot-verify-result');
		var $tokenInput = $('#hey_support_api_token');
		var $refreshBtn = $('#hey-support-ai-chatbot-refresh-chatbots');
		var $chatbotInput = $('#hey_support_chatbot_id');

		function report(msg, ok) {
			$verifyResult.css('color', ok ? OK_COLOR : ERR_COLOR).text(msg);
		}

		$verifyBtn.on('click', function () {
			// An empty field means "re-check the saved token", not "no token" - the
			// field is write-only and always renders empty. The server resolves it.
			var token = String($tokenInput.val() || '').trim();
			$verifyBtn.prop('disabled', true);
			$verifyResult.text(heySupportAdmin.i18n.verifying).css('color', '');

			postAjax('hey_support_verify_token', { token: token })
				.done(function (resp) {
					$verifyBtn.prop('disabled', false);
					if (resp && resp.success && resp.data) {
						report(
							heySupportAdmin.i18n.connectedTo + ' ' +
							(resp.data.team_name || '') +
							(resp.data.key_name ? ' - ' + resp.data.key_name : ''),
							true
						);
						return;
					}
					report(errorFor(resp), false);
				})
				.fail(function () {
					$verifyBtn.prop('disabled', false);
					report(heySupportAdmin.i18n.networkErr, false);
				});
		});

		$refreshBtn.on('click', function () {
			// Empty means "use the saved token" - see the Verify handler above.
			var token = String($tokenInput.val() || '').trim();
			$refreshBtn.prop('disabled', true).text(heySupportAdmin.i18n.refreshing);

			postAjax('hey_support_refresh_chatbots', { token: token })
				.done(function (resp) {
					$refreshBtn.prop('disabled', false).text(heySupportAdmin.i18n.refreshLabel);
					if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.chatbots)) {
						report(errorFor(resp), false);
						return;
					}
					var bots = resp.data.chatbots;
					if (!bots.length) {
						report(heySupportAdmin.i18n.noChatbots, false);
						return;
					}
					var current = String($chatbotInput.val() || '');
					var $select = $('<select></select>')
						.attr('id', $chatbotInput.attr('id'))
						.attr('name', $chatbotInput.attr('name'))
						.addClass('regular-text');
					bots.forEach(function (b) {
						$select.append(
							$('<option></option>')
								.val(b.id)
								.text(b.name + ' (' + b.id + ')')
								.prop('selected', b.id === current)
						);
					});
					$chatbotInput.replaceWith($select);
					$chatbotInput = $select;
				})
				.fail(function () {
					$refreshBtn.prop('disabled', false).text(heySupportAdmin.i18n.refreshLabel);
					report(heySupportAdmin.i18n.networkErr, false);
				});
		});

		// --- Storefront self-check ------------------------------------------
		// Asks THIS site whether its own Store API answers. WordPress fails in a
		// few very specific ways and each has a one-minute fix right here in
		// wp-admin, so naming the cause beats a generic "could not connect".
		var $storefrontBtn = $('#hey-support-storefront-test');
		var $storefrontOut = $('#hey-support-storefront-result');

		$storefrontBtn.on('click', function () {
			var label = $storefrontBtn.text();
			$storefrontBtn.prop('disabled', true);
			$storefrontOut.removeClass('notice notice-error notice-success').text('');

			$.post(heySupportAdmin.ajaxUrl, {
				action: 'hey_support_storefront_test',
				nonce: heySupportAdmin.nonce
			})
				.done(function (resp) {
					$storefrontBtn.prop('disabled', false).text(label);
					var data = (resp && resp.data) || {};
					var msg = data.message || '';
					if (resp && resp.success) {
						$storefrontOut.text(msg).css('color', OK_COLOR);
						return;
					}
					$storefrontOut.text(msg).css('color', ERR_COLOR);
					// A 404 is almost always Plain permalinks - link straight to the
					// screen that fixes it rather than describing where it lives.
					if (data.permalinkUrl) {
						$storefrontOut.append(
							$('<a></a>')
								.attr('href', data.permalinkUrl)
								.text(' ' + heySupportAdmin.i18n.permalinks)
						);
					}
				})
				.fail(function () {
					$storefrontBtn.prop('disabled', false).text(label);
					$storefrontOut.text(heySupportAdmin.i18n.networkErr).css('color', ERR_COLOR);
				});
		});
	});
}(jQuery));
