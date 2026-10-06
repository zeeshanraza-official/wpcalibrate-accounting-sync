/**
 * WPCalibrate Accounting Sync — Admin Scripts
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Copy to clipboard helper.
		$(document).on('click', '.wpcalibrate-copy-btn', function(e) {
			e.preventDefault();
			const $btn = $(this);
			const targetSelector = $btn.data('target');
			const $target = $(targetSelector);

			if ($target.length) {
				const val = $target.val() || $target.text();
				navigator.clipboard.writeText(val).then(function() {
					const origText = $btn.text();
					$btn.text(wpcalibrateSyncData.copiedMsg || 'Copied!');
					setTimeout(function() {
						$btn.text(origText);
					}, 2000);
				});
			}
		});

		// Live test connection.
		$(document).on('click', '.wpcalibrate-test-connection-btn', function(e) {
			e.preventDefault();
			const $btn = $(this);
			$btn.prop('disabled', true).text('Testing...');

			$.ajax({
				url: wpcalibrateSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpcalibrate_sync_test_connection',
					nonce: wpcalibrateSyncData.nonce
				},
				success: function(res) {
					if (res.success) {
						alert(res.data.message);
					} else {
						alert('Connection Error: ' + (res.data ? res.data.message : 'Unknown error'));
					}
				},
				error: function() {
					alert('Network error testing connection.');
				},
				complete: function() {
					$btn.prop('disabled', false).text('Test Connection');
				}
			});
		});
	});
})(jQuery);
