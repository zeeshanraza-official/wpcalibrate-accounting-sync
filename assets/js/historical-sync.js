/**
 * WPCalibrate Accounting Sync — Historical Sync AJAX Controller
 */

(function($) {
	'use strict';

	let orderIdsQueue = [];
	let isSyncing = false;
	let isCancelled = false;

	$(document).ready(function() {
		const $dryRunBtn    = $('#wpcalibrate_dry_run_btn');
		const $startBtn     = $('#wpcalibrate_start_hist_btn');
		const $cancelBtn    = $('#wpcalibrate_cancel_hist_btn');
		const $notice       = $('#wpcalibrate_dry_run_notice');
		const $noticeText   = $('#wpcalibrate_dry_run_text');
		const $progressWrap = $('#wpcalibrate_progress_container');
		const $progressBar  = $('#wpcalibrate_progress_bar');
		const $statusText   = $('#wpcalibrate_progress_status');

		// 1. Dry Run / Estimation.
		$dryRunBtn.on('click', function(e) {
			e.preventDefault();
			$dryRunBtn.prop('disabled', true).text('Estimating...');
			$notice.hide();

			const statuses = [];
			$('input[name="hist_statuses[]"]:checked').each(function() {
				statuses.push($(this).val());
			});

			$.ajax({
				url: wpcalibrateSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpcalibrate_sync_dry_run_historical',
					nonce: wpcalibrateSyncData.nonce,
					date_from: $('#hist_date_from').val(),
					date_to: $('#hist_date_to').val(),
					statuses: statuses
				},
				success: function(res) {
					if (res.success) {
						orderIdsQueue = res.data.order_ids || [];
						$noticeText.text(res.data.message);
						$notice.show();

						if (orderIdsQueue.length > 0) {
							$startBtn.prop('disabled', false);
						} else {
							$startBtn.prop('disabled', true);
						}
					} else {
						alert(res.data.message || 'Error executing dry run.');
					}
				},
				error: function() {
					alert('Network error during dry run estimation.');
				},
				complete: function() {
					$dryRunBtn.prop('disabled', false).text('Estimate Count (Dry Run)');
				}
			});
		});

		// 2. Start Historical Sync.
		$startBtn.on('click', function(e) {
			e.preventDefault();
			if (!orderIdsQueue.length) {
				return;
			}

			if (!confirm('Are you sure you want to enqueue ' + orderIdsQueue.length + ' orders into the background synchronization queue?')) {
				return;
			}

			isSyncing   = true;
			isCancelled = false;

			$dryRunBtn.prop('disabled', true);
			$startBtn.prop('disabled', true);
			$cancelBtn.show();
			$progressWrap.show();

			processBatch(0, orderIdsQueue.length);
		});

		// 3. Cancel Sync.
		$cancelBtn.on('click', function(e) {
			e.preventDefault();
			isCancelled = true;
			$cancelBtn.text('Cancelling...');
		});

		function processBatch(offset, total) {
			if (isCancelled) {
				$statusText.text('Historical sync cancelled by user.');
				$cancelBtn.hide();
				$dryRunBtn.prop('disabled', false);
				$startBtn.prop('disabled', false);
				return;
			}

			$.ajax({
				url: wpcalibrateSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpcalibrate_sync_process_historical_batch',
					nonce: wpcalibrateSyncData.nonce,
					order_ids: orderIdsQueue,
					offset: offset,
					batch_size: 25
				},
				success: function(res) {
					if (res.success) {
						const currentOffset = res.data.offset;
						const pct = Math.min(100, Math.round((currentOffset / total) * 100));

						$progressBar.css('width', pct + '%');
						$statusText.text('Enqueued ' + currentOffset + ' of ' + total + ' orders (' + pct + '%)');

						if (!res.data.completed && !isCancelled) {
							processBatch(currentOffset, total);
						} else {
							$statusText.text('Complete! All ' + total + ' orders have been successfully enqueued into Action Scheduler.');
							$cancelBtn.hide();
							$dryRunBtn.prop('disabled', false);
							$startBtn.prop('disabled', true);
						}
					} else {
						alert('Error processing batch.');
					}
				},
				error: function() {
					alert('Network error communicating batch progress.');
				}
			});
		}
	});
})(jQuery);
