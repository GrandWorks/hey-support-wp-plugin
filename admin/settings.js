/* global jQuery, heySupportAdmin */
(function ($) {
	'use strict';

	var OK_COLOR = '#1f7a1f';
	var ERR_COLOR = '#b3341d';

	$(function () {
		initManualToggle();
		initExcludePicker();
		initStorefrontCheck();
	});

	// --- "I have a chatbot ID" -------------------------------------------
	// Progressive disclosure rather than a second card: the manual path is a
	// fallback for local sites Hey Support cannot reach, and showing it beside
	// the primary action is what made the old screen read as two equal choices.
	function initManualToggle() {
		var $toggle = $('.hs-manual-toggle');
		var $panel = $('.hs-manual');
		if (!$toggle.length || !$panel.length) { return; }

		$toggle.on('click', function (e) {
			e.preventDefault();
			var open = $panel.is('[hidden]');
			$panel.prop('hidden', !open);
			$toggle.attr('aria-expanded', open ? 'true' : 'false');
			if (open) { $panel.find('input[type="text"]').trigger('focus'); }
		});
	}

	// --- Exclude-by-name picker -------------------------------------------
	// Replaces a textarea that asked for comma-separated post IDs, something
	// WordPress never shows anyone -- so the only way to comply was to go
	// hunting in URLs. The STORED value is still a comma-separated ID list, so
	// the option shape is unchanged and no migration was needed.
	//
	// Deliberately vanilla against /wp/v2/search rather than wp.components'
	// FormTokenField: this is a settings screen, and pulling in the block editor
	// component bundle to render a few chips would be the heavier half of the
	// page by a wide margin.
	function initExcludePicker() {
		var $ids = $('#hey-support-exclude-ids');
		var $chips = $('#hey-support-chips');
		var $search = $('#hey-support-exclude-search');
		var $results = $('#hey-support-results');
		if (!$ids.length || !$search.length) { return; }

		// Titles are resolved server-side: /wp/v2/search has no include= filter,
		// so the client cannot turn saved IDs back into names on its own.
		var chosen = (heySupportAdmin.excluded || []).slice();
		var timer = null;
		var lastQuery = '';

		function sync() {
			$ids.val(chosen.map(function (c) { return c.id; }).join(','));
			$chips.empty();
			chosen.forEach(function (item) {
				var $remove = $('<button type="button"></button>')
					.attr('aria-label', heySupportAdmin.i18n.remove + ': ' + item.title)
					.text('x')
					.on('click', function () {
						chosen = chosen.filter(function (c) { return c.id !== item.id; });
						sync();
						$search.trigger('focus');
					});
				$chips.append(
					$('<li class="hs-chip"></li>')
						.append($('<span></span>').text(item.title))
						.append($remove)
				);
			});
		}

		function closeResults() {
			$results.empty();
			$search.attr('aria-expanded', 'false');
		}

		function add(item) {
			if (!chosen.some(function (c) { return c.id === item.id; })) {
				chosen.push(item);
				sync();
			}
			$search.val('');
			lastQuery = '';
			closeResults();
			$search.trigger('focus');
		}

		function render(items) {
			$results.empty();
			if (!items.length) {
				$results.append(
					$('<li></li>').append(
						$('<button type="button" disabled></button>').text(heySupportAdmin.i18n.noResults)
					)
				);
				$search.attr('aria-expanded', 'true');
				return;
			}
			items.forEach(function (item) {
				var $btn = $('<button type="button" role="option"></button>')
					.append($('<span></span>').text(item.title))
					.append($('<span class="hs-result-type"></span>').text(item.subtype || ''))
					.on('click', function () { add({ id: item.id, title: item.title }); });
				$results.append($('<li></li>').append($btn));
			});
			$search.attr('aria-expanded', 'true');
		}

		function search(query) {
			$.ajax({
				url: heySupportAdmin.restUrl + '?search=' + encodeURIComponent(query) + '&per_page=8',
				headers: { 'X-WP-Nonce': heySupportAdmin.restNonce }
			})
				.done(function (rows) {
					if (!Array.isArray(rows)) { closeResults(); return; }
					render(
						rows.map(function (r) {
							// The search endpoint returns rendered titles, which
							// can carry HTML entities. .text() puts them back.
							return {
								id: parseInt(r.id, 10),
								title: $('<div></div>').html(r.title || '').text() || String(r.id),
								subtype: r.subtype || ''
							};
						})
					);
				})
				.fail(function () { closeResults(); });
		}

		$search.on('input', function () {
			var query = $.trim(String($search.val() || ''));
			if (timer) { clearTimeout(timer); }
			if (query.length < 2) { closeResults(); return; }
			if (query === lastQuery) { return; }
			lastQuery = query;
			timer = setTimeout(function () { search(query); }, 250);
		});

		$search.on('keydown', function (e) {
			if (e.key === 'Escape') { closeResults(); }
			// This sits inside the settings form: Enter in a search box must not
			// submit it.
			if (e.key === 'Enter') { e.preventDefault(); }
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('.hs-picker').length) { closeResults(); }
		});

		sync();
	}

	// --- Storefront self-check ---------------------------------------------
	// Asks THIS site whether its own Store API answers. WordPress fails in a few
	// very specific ways and each has a one-minute fix right here in wp-admin,
	// so naming the cause beats a generic "could not connect".
	function initStorefrontCheck() {
		var $btn = $('#hey-support-storefront-test');
		var $out = $('#hey-support-storefront-result');
		if (!$btn.length) { return; }

		$btn.on('click', function () {
			var label = $btn.text();
			$btn.prop('disabled', true);
			$out.text('');

			$.post(heySupportAdmin.ajaxUrl, {
				action: 'hey_support_storefront_test',
				nonce: heySupportAdmin.nonce
			})
				.done(function (resp) {
					$btn.prop('disabled', false).text(label);
					var data = (resp && resp.data) || {};
					var msg = data.message || '';
					if (resp && resp.success) {
						$out.text(msg).css('color', OK_COLOR);
						return;
					}
					$out.text(msg).css('color', ERR_COLOR);
					// A 404 is almost always Plain permalinks - link straight to
					// the screen that fixes it rather than describing where it is.
					if (data.permalinkUrl) {
						$out.append(
							$('<a></a>')
								.attr('href', data.permalinkUrl)
								.text(' ' + heySupportAdmin.i18n.permalinks)
						);
					}
				})
				.fail(function () {
					$btn.prop('disabled', false).text(label);
					$out.text(heySupportAdmin.i18n.networkErr).css('color', ERR_COLOR);
				});
		});
	}
}(jQuery));
