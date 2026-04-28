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
	});
})(jQuery);
