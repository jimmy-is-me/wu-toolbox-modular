(function ($) {
	'use strict';

	var settings = window.sparCustomerPointsBulk || {};

	if (!settings.ajaxUrl || !settings.jobId || !settings.nonce) {
		return;
	}

	$(function () {
		var $app = $('#spar-customer-points-bulk-app');
		if (!$app.length) {
			return;
		}

		var $stopButton = $('#spar-customer-points-bulk-stop');
		var $undoAllButton = $('#spar-customer-points-bulk-undo');
		var $spinner = $('#spar-customer-points-bulk-spinner');
		var $status = $('#spar-customer-points-bulk-status');
		var $progress = $('#spar-customer-points-bulk-progress');
		var $results = $('#spar-customer-points-bulk-results');
		var strings = settings.strings || {};

		var state = {
			running: true,
			stopRequested: false,
			offset: 0,
			total: 0,
			processed: 0,
			undoing: false,
			undoableCount: 0
		};

		function formatNumber(value) {
			var number = parseInt(value, 10);
			if (isNaN(number)) {
				number = 0;
			}
			return number.toLocaleString();
		}

		function signedNumber(value) {
			var number = parseInt(value, 10);
			if (isNaN(number)) {
				number = 0;
			}
			return (number > 0 ? '+' : '') + number.toLocaleString();
		}

		function statusLabel(status) {
			switch (status) {
				case 'updated':
					return strings.updated || 'Updated';
				case 'unchanged':
					return strings.unchanged || 'Unchanged';
				case 'skipped':
					return strings.skipped || 'Skipped';
				case 'undone':
					return strings.undone || 'Undone';
				default:
					return status || '';
			}
		}

		function setStatus(message) {
			$status.text(message);
		}

		function updateProgress(processed, total) {
			state.processed = processed || state.processed;
			state.total = total || state.total;

			if (state.total > 0) {
				var value = Math.min(100, Math.round((state.processed / state.total) * 100));
				$progress.attr('max', 100).val(value);
				setStatus((strings.processing || 'Processing %1$d of %2$d users...')
					.replace('%1$d', formatNumber(state.processed))
					.replace('%2$d', formatNumber(state.total)));
				return;
			}

			$progress.attr('max', 100).val(0);
			setStatus(strings.processingNoTotal || 'Processing users...');
		}

		function setFinished(message) {
			state.running = false;
			$spinner.removeClass('is-active');
			$stopButton.prop('disabled', true);
			setStatus(message);
			if (state.undoableCount > 0) {
				$undoAllButton.prop('disabled', false).removeAttr('hidden');
			}
		}

		function showUndoAllIfReady() {
			if (!state.running && state.undoableCount > 0) {
				$undoAllButton.prop('disabled', false).removeAttr('hidden');
			}
		}

		function rowStatusClass(status) {
			return 'spar-bulk-status spar-bulk-status--' + (status || 'updated');
		}

		function appendEntry(entry) {
			var userId = parseInt(entry.user_id, 10);
			var $row = $('<tr>', {
				'id': 'spar-bulk-row-' + userId,
				'class': entry.status === 'skipped' || entry.status === 'unchanged' ? 'spar-bulk-row-muted' : ''
			});
			var $undoCell = $('<td>');

			if (entry.can_undo) {
				$('<button>', {
					type: 'button',
					'class': 'button button-small spar-bulk-row-undo',
					'data-user-id': userId,
					text: strings.undo || 'Undo'
				}).appendTo($undoCell);
			} else {
				$undoCell.text('-');
			}

			$('<td>').text(entry.display_name || ('User #' + userId)).appendTo($row);
			$('<td>').append(
				$('<code>').text(entry.user_login || ''),
				$('<br>'),
				$('<small>').text(entry.user_email || '')
			).appendTo($row);
			$('<td>').text(formatNumber(entry.previous)).appendTo($row);
			$('<td>').text(signedNumber(entry.change)).appendTo($row);
			$('<td>').text(formatNumber(entry.new)).appendTo($row);
			$('<td>').append(
				$('<span>', {
					'class': rowStatusClass(entry.status),
					text: statusLabel(entry.status)
				}),
				entry.message ? $('<p>', { 'class': 'description', text: entry.message }) : ''
			).appendTo($row);
			$row.append($undoCell);
			$results.append($row);
		}

		function markRowUndone(row) {
			var userId = parseInt(row.user_id, 10);
			var $row = $('#spar-bulk-row-' + userId);
			if (!$row.length) {
				return;
			}

			$row.find('td').eq(4).text(formatNumber(row.new));
			$row.find('td').eq(5).empty().append(
				$('<span>', {
					'class': rowStatusClass(row.status),
					text: statusLabel(row.status)
				}),
				row.message ? $('<p>', { 'class': 'description', text: row.message }) : ''
			);

			if (row.undo_success) {
				$row.find('.spar-bulk-row-undo').prop('disabled', true).text(strings.undone || 'Undone');
			}
		}

		function requestFailedMessage(jqXHR, fallback) {
			var message = fallback || 'Request failed.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				message = jqXHR.responseJSON.data.message;
			} else if (jqXHR && jqXHR.statusText) {
				message = jqXHR.statusText;
			}
			return message;
		}

		function processBatch() {
			if (!state.running || state.stopRequested) {
				return;
			}

			$.post(settings.ajaxUrl, {
				action: 'spar_customer_points_batch_process',
				nonce: settings.nonce,
				job_id: settings.jobId,
				offset: state.offset
			}).done(function (response) {
				if (!response || !response.success) {
					var responseMessage = response && response.data && response.data.message ? response.data.message : 'Unexpected response from the server.';
					setFinished((strings.failed || 'Bulk points update failed: %s').replace('%s', responseMessage));
					return;
				}

				var data = response.data || {};
				if (Array.isArray(data.entries)) {
					data.entries.forEach(appendEntry);
				}

				var nextOffset = parseInt(data.next_offset, 10);
				if (isNaN(nextOffset)) {
					nextOffset = state.offset + (settings.batchSize || 50);
				}
				state.offset = nextOffset;

				state.undoableCount = parseInt(data.undoable_count, 10) || 0;
				updateProgress(parseInt(data.processed, 10) || state.offset, parseInt(data.total, 10) || 0);

				if (data.complete) {
					$progress.val(100);
					setFinished(strings.complete || 'Bulk points update complete.');
					return;
				}

				if (state.stopRequested) {
					setFinished(strings.stopped || 'Bulk points update stopped.');
					return;
				}

				window.setTimeout(processBatch, 150);
			}).fail(function (jqXHR) {
				setFinished((strings.failed || 'Bulk points update failed: %s').replace('%s', requestFailedMessage(jqXHR)));
			});
		}

		function stopBatch() {
			state.stopRequested = true;
			$stopButton.prop('disabled', true);

			$.post(settings.ajaxUrl, {
				action: 'spar_customer_points_batch_stop',
				nonce: settings.nonce,
				job_id: settings.jobId
			}).done(function (response) {
				if (response && response.success && response.data) {
					state.undoableCount = parseInt(response.data.undoable_count, 10) || state.undoableCount;
				}
				setFinished(strings.stopped || 'Bulk points update stopped.');
				showUndoAllIfReady();
			}).fail(function () {
				setFinished(strings.stopped || 'Bulk points update stopped.');
				showUndoAllIfReady();
			});
		}

		function undoBatch(userId) {
			if (state.undoing) {
				return;
			}

			state.undoing = true;
			$undoAllButton.prop('disabled', true);
			$spinner.addClass('is-active');
			setStatus(strings.undoing || 'Undoing processed updates...');

			$.post(settings.ajaxUrl, {
				action: 'spar_customer_points_batch_undo',
				nonce: settings.nonce,
				job_id: settings.jobId,
				user_id: userId || 0
			}).done(function (response) {
				if (!response || !response.success) {
					var responseMessage = response && response.data && response.data.message ? response.data.message : 'Unexpected response from the server.';
					setStatus((strings.undoFailed || 'Undo failed: %s').replace('%s', responseMessage));
					state.undoing = false;
					$spinner.removeClass('is-active');
					showUndoAllIfReady();
					return;
				}

				var data = response.data || {};
				if (Array.isArray(data.rows)) {
					data.rows.forEach(markRowUndone);
				}

				state.undoableCount = parseInt(data.undoable_count, 10) || 0;
				state.undoing = false;

				if (!userId && !data.complete && state.undoableCount > 0) {
					window.setTimeout(function () {
						undoBatch(0);
					}, 150);
					return;
				}

				$spinner.removeClass('is-active');
				setStatus(data.complete ? (strings.undoComplete || 'Processed updates have been undone.') : (strings.stopped || 'Bulk points update stopped.'));
				showUndoAllIfReady();
			}).fail(function (jqXHR) {
				state.undoing = false;
				$spinner.removeClass('is-active');
				setStatus((strings.undoFailed || 'Undo failed: %s').replace('%s', requestFailedMessage(jqXHR)));
				showUndoAllIfReady();
			});
		}

		$stopButton.on('click', function () {
			if (window.confirm(strings.confirmStop || 'Stop after the current batch finishes?')) {
				stopBatch();
			}
		});

		$undoAllButton.on('click', function () {
			if (window.confirm(strings.confirmUndoAll || 'Undo all processed updates for this batch?')) {
				undoBatch(0);
			}
		});

		$results.on('click', '.spar-bulk-row-undo', function () {
			var $button = $(this);
			var userId = parseInt($button.data('user-id'), 10);
			if (!userId) {
				return;
			}
			$button.prop('disabled', true);
			undoBatch(userId);
		});

		updateProgress(0, 0);
		processBatch();
	});
})(jQuery);
