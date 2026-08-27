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
			var token = String($tokenInput.val() || '').trim();
			if (!token) {
				report(heySupportAdmin.i18n.invalidToken, false);
				return;
			}
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
					report(failureMessage(statusOf(resp)), false);
				})
				.fail(function () {
					$verifyBtn.prop('disabled', false);
					report(heySupportAdmin.i18n.networkErr, false);
				});
		});

		$refreshBtn.on('click', function () {
			var token = String($tokenInput.val() || '').trim();
			if (!token) {
				report(heySupportAdmin.i18n.invalidToken, false);
				return;
			}
			$refreshBtn.prop('disabled', true).text(heySupportAdmin.i18n.refreshing);

			postAjax('hey_support_refresh_chatbots', { token: token })
				.done(function (resp) {
					$refreshBtn.prop('disabled', false).text(heySupportAdmin.i18n.refreshLabel);
					if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.chatbots)) {
						report(failureMessage(statusOf(resp)), false);
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
	});
}(jQuery));
