/**
 * Admin settings page scripts.
 *
 * @package LEAStudios\EmailTemplates
 */

/* global jQuery, wp, leastudiosEmailTemplates */

(function ($) {
	'use strict';

	$(function () {

		// Color picker.
		$('.leastudios-color-picker').wpColorPicker();

		// Logo upload.
		$('#leastudios-upload-logo').on('click', function (e) {
			e.preventDefault();

			var frame = wp.media({
				title: leastudiosEmailTemplates.strings.selectImage,
				button: { text: leastudiosEmailTemplates.strings.useImage },
				multiple: false,
				library: { type: 'image' }
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$('#leastudios-logo-url').val(attachment.url);
				$('#leastudios-logo-preview').html(
					'<img src="' + attachment.url + '" style="max-height:50px;margin-bottom:10px;display:block;" />'
				);
				$('#leastudios-remove-logo').show();
			});

			frame.open();
		});

		$('#leastudios-remove-logo').on('click', function (e) {
			e.preventDefault();
			$('#leastudios-logo-url').val('');
			$('#leastudios-logo-preview').html('');
			$(this).hide();
		});

		// Accordion for email types.
		$('.leastudios-email-type-header').on('click', function () {
			var $body = $(this).next('.leastudios-email-type-body');
			var $icon = $(this).find('.dashicons');

			$body.slideToggle(200);
			$icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
		});

		// Email preview.
		$('#leastudios-preview-email').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text('Loading...');

			$.post(leastudiosEmailTemplates.ajaxUrl, {
				action: 'leastudios_email_templates_preview',
				_wpnonce: leastudiosEmailTemplates.previewNonce
			}, function (response) {
				$btn.prop('disabled', false).text($btn.data('original-text') || 'Preview Email Template');

				if (response.success) {
					var $frame = $('#leastudios-preview-frame');
					var $iframe = $('#leastudios-preview-iframe');

					$frame.show();

					var doc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
					doc.open();
					doc.write(response.data.html);
					doc.close();
				}
			}).fail(function () {
				$btn.prop('disabled', false);
			});
		});

		// Store original button text.
		$('#leastudios-preview-email').data('original-text', $('#leastudios-preview-email').text());

		// Per-type preview.
		$(document).on('click', '.leastudios-preview-type', function () {
			var $btn = $(this);
			var type = $btn.data('type');

			var $section = $btn.closest('.leastudios-email-type-section');
			var subject = $section.find('input[name$="[subject]"]').val();
			var body = $section.find('textarea[name$="[body]"]').val();

			$btn.prop('disabled', true);

			$.post(leastudiosEmailTemplates.ajaxUrl, {
				action: 'leastudios_email_templates_preview_type',
				_wpnonce: leastudiosEmailTemplates.previewNonce,
				type: type,
				subject: subject || '',
				body: body || ''
			}, function (response) {
				$btn.prop('disabled', false);

				if (!response.success) {
					window.alert(response.data || 'Preview failed.');
					return;
				}

				var $subjectLine = $('.leastudios-preview-subject[data-type="' + type + '"]');
				$subjectLine.text('Subject: ' + response.data.subject).show();

				var $frameWrap = $('.leastudios-preview-frame[data-type="' + type + '"]');
				var $iframe = $frameWrap.find('iframe');
				$frameWrap.show();

				var doc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
				doc.open();
				doc.write(response.data.html);
				doc.close();
			}).fail(function () {
				$btn.prop('disabled', false);
			});
		});

		// Send-test.
		$(document).on('click', '.leastudios-send-test', function () {
			var $btn = $(this);
			var type = $btn.data('type');
			var $input = $('.leastudios-send-test-to[data-type="' + type + '"]');
			var to = ($input.val() || $input.attr('placeholder') || '').trim();
			var $result = $('.leastudios-send-test-result[data-type="' + type + '"]').text('');

			if (!to) {
				$result.text('Enter an email address first.').css('color', '#b32d2e');
				return;
			}

			$btn.prop('disabled', true);

			$.post(leastudiosEmailTemplates.ajaxUrl, {
				action: 'leastudios_email_templates_send_test',
				_wpnonce: leastudiosEmailTemplates.previewNonce,
				type: type,
				to: to
			}, function (response) {
				$btn.prop('disabled', false);
				if (response.success) {
					$result.text(response.data.message).css('color', '#1d8a1d');
				} else {
					$result.text(response.data || 'Send failed.').css('color', '#b32d2e');
				}
			}).fail(function () {
				$btn.prop('disabled', false);
				$result.text('Network error.').css('color', '#b32d2e');
			});
		});
	});
})(jQuery);
