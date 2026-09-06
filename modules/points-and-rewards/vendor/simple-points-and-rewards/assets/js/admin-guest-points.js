/**
 * Guest Points Admin JavaScript
 */
jQuery(function($) {
	'use strict';

	if (typeof window.sparGuestPointsAdmin === 'undefined') {
		return;
	}

	const config = window.sparGuestPointsAdmin;
	const strings = config.strings || {};

	// Show any pending sync summary stored before the last page reload.
	(function() {
		try {
			const stored = sessionStorage.getItem('spar_sync_complete');
			if (stored) {
				sessionStorage.removeItem('spar_sync_complete');
				const msg = JSON.parse(stored);
				if (msg && msg.html) {
					$('#spar-sync-guest-points-status')
						.removeClass('notice-info notice-success notice-error notice-warning')
						.addClass('notice-success')
						.show()
						.find('p')
						.html(msg.html);
				}
			}
		} catch (e) {}
	}());
	const ajaxUrl = config.ajaxUrl || window.ajaxurl;

	function formatString(template, values) {
		let output = template || '';
		values.forEach(function(value, index) {
			const placeholder = new RegExp('%' + (index + 1) + '\\$d', 'g');
			output = output.replace(placeholder, value);
		});
		return output;
	}

	function addReasonCounts(target, source) {
		Object.keys(source || {}).forEach(function(reason) {
			const count = parseInt(source[reason], 10) || 0;
			if (count > 0) {
				target[reason] = (target[reason] || 0) + count;
			}
		});
	}

	function formatSkipReasonBreakdown(skipReasons) {
		const labels = strings.syncSkipReasonLabels || {};
		return Object.keys(skipReasons || {})
			.filter(function(reason) {
				return (parseInt(skipReasons[reason], 10) || 0) > 0;
			})
			.sort(function(a, b) {
				return (parseInt(skipReasons[b], 10) || 0) - (parseInt(skipReasons[a], 10) || 0);
			})
			.map(function(reason) {
				const count = parseInt(skipReasons[reason], 10) || 0;
				return count + ' ' + (labels[reason] || reason.replace(/_/g, ' '));
			})
			.join(', ');
	}

	function getResponseMessage(response, fallback) {
		if (response && response.data) {
			if (typeof response.data === 'string') {
				return response.data;
			}
			if (response.data.message) {
				return response.data.message;
			}
		}
		return fallback;
	}

	function setSyncStatus(type, message) {
		const status = $('#spar-sync-guest-points-status');
		status
			.removeClass('notice-info notice-success notice-error notice-warning')
			.addClass('notice-' + type)
			.show()
			.find('p')
			.text(message);
	}

	function setSyncStatusHtml(type, html) {
		const status = $('#spar-sync-guest-points-status');
		status
			.removeClass('notice-info notice-success notice-error notice-warning')
			.addClass('notice-' + type)
			.show()
			.find('p')
			.html(html);
	}

	function updateSyncProgress(totals) {
		let message;
		if (totals.totalOrders > 0) {
			message = formatString(strings.syncProgress, [
				totals.processed,
				totals.totalOrders,
				totals.addedGuestCustomers,
				totals.addedGuest,
				totals.updatedGuest,
				totals.creditedUser,
				totals.skipped
			]);
		} else {
			message = formatString(strings.syncProgressNoTotal, [
				totals.processed,
				totals.addedGuestCustomers,
				totals.addedGuest,
				totals.updatedGuest,
				totals.creditedUser,
				totals.skipped
			]);
		}

		setSyncStatus('info', message);
	}

	function toggleSpecificEmailField() {
		const isOverride = $('#spar-sync-override-existing').val() === 'override';
		const isSpecific = $('#spar-sync-email-scope').val() === 'specific' && !isOverride;
		$('.spar-sync-specific-email-field').prop('hidden', !isSpecific);
		$('#spar-sync-specific-email').prop('disabled', !isSpecific);
	}

	function updateSyncModeDescription() {
		const isOverride = $('#spar-sync-override-existing').val() === 'override';
		const message = isOverride
			? strings.overrideModeDescription
			: strings.fillMissingModeDescription;

		$('#spar-sync-mode-description').text(message || '');
	}

	function toggleOverrideFields() {
		const isOverride = $('#spar-sync-override-existing').val() === 'override';

		if (isOverride) {
			$('#spar-sync-email-scope').val('all');
			$('#spar-sync-status-filter').val('eligible');
			$('#spar-sync-specific-email, #spar-sync-date-from, #spar-sync-date-to').val('');
		}

		$('#spar-sync-email-scope, #spar-sync-status-filter, #spar-sync-date-from, #spar-sync-date-to')
			.prop('disabled', isOverride);
		updateSyncModeDescription();
		toggleSpecificEmailField();
	}

	function getSyncFilters() {
		const overrideExisting = $('#spar-sync-override-existing').val() === 'override';
		const creditRegistered = $('#spar-sync-credit-registered-users').is(':checked') ? 1 : 0;

		if (overrideExisting) {
			return {
				email_scope: 'all',
				email: '',
				status_filter: 'eligible',
				date_from: '',
				date_to: '',
				batch_size: parseInt($('#spar-sync-batch-size').val(), 10) || config.batchSize || 25,
				override_existing: 1,
				credit_registered_users: creditRegistered
			};
		}

		return {
			email_scope: $('#spar-sync-email-scope').val() || 'all',
			email: $.trim($('#spar-sync-specific-email').val() || ''),
			status_filter: $('#spar-sync-status-filter').val() || 'eligible',
			date_from: $('#spar-sync-date-from').val() || '',
			date_to: $('#spar-sync-date-to').val() || '',
			batch_size: parseInt($('#spar-sync-batch-size').val(), 10) || config.batchSize || 25,
			override_existing: 0,
			credit_registered_users: creditRegistered
		};
	}

	function setSyncControlsDisabled(disabled) {
		$('#spar-sync-guest-points, #spar-sync-guest-points-options').find('button, input, select').prop('disabled', disabled);
		$('#spar-sync-guest-points').prop('disabled', disabled).toggleClass('disabled', disabled);
	}

	function validateSyncFilters(filters) {
		if (filters.email_scope === 'specific') {
			const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			if (!emailPattern.test(filters.email)) {
				setSyncStatus('error', strings.invalidEmail || 'Please enter a valid email address.');
				$('#spar-sync-specific-email').trigger('focus');
				return false;
			}
		}

		if (filters.date_from && filters.date_to && filters.date_from > filters.date_to) {
			setSyncStatus('error', strings.invalidDateRange || 'The start date must be before the end date.');
			$('#spar-sync-date-from').trigger('focus');
			return false;
		}

		return true;
	}

	$('#spar-sync-email-scope').on('change', toggleSpecificEmailField);
	$('#spar-sync-override-existing').on('change', toggleOverrideFields);
	toggleOverrideFields();

	$('#spar-sync-guest-points').on('click', function(e) {
		e.preventDefault();

		const panel = $('#spar-sync-guest-points-options');
		const shouldShow = panel.prop('hidden');
		panel.prop('hidden', !shouldShow);

		// Close add-guest panel when opening sync panel.
		if (shouldShow) {
			$('#spar-add-guest-options').prop('hidden', true);
			$('#spar-start-guest-points-sync').trigger('focus');
		}
	});

	$('#spar-cancel-guest-points-sync').on('click', function(e) {
		e.preventDefault();
		$('#spar-sync-guest-points-options').prop('hidden', true);
	});

	$('#spar-start-guest-points-sync').on('click', function(e) {
		e.preventDefault();

		const filters = getSyncFilters();
		if (!validateSyncFilters(filters)) {
			return;
		}

		const confirmMessage = filters.override_existing ? strings.confirmOverrideSync : strings.confirmSync;
		if (confirmMessage && !window.confirm(confirmMessage)) {
			return;
		}

		const totals = {
			processed: 0,
			addedGuestCustomers: 0,
			addedGuest: 0,
			updatedGuest: 0,
			creditedUser: 0,
			skipped: 0,
			skipReasons: {},
			failed: 0,
			totalOrders: 0
		};
		const syncRunId = 'sync_' + Date.now() + '_' + Math.random().toString(36).slice(2);

		setSyncControlsDisabled(true);
		setSyncStatus('info', strings.syncStarting || 'Starting guest points sync...');

		function runBatch(page) {
			const data = $.extend({}, filters, {
				action: 'spar_sync_guest_points',
				nonce: config.syncNonce,
				sync_run_id: syncRunId,
				page: page
			});

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: data,
				success: function(response) {
					if (!response || !response.success) {
						setSyncStatus('error', getResponseMessage(response, strings.syncError || 'Guest points sync failed.'));
						setSyncControlsDisabled(false);
						toggleOverrideFields();
						return;
					}

					const data = response.data || {};
					totals.processed += parseInt(data.processed, 10) || 0;
					totals.addedGuestCustomers += parseInt(data.added_guest_customer, 10) || 0;
					totals.addedGuest += parseInt(data.added_guest, 10) || 0;
					totals.updatedGuest += parseInt(data.updated_guest, 10) || 0;
					totals.creditedUser += parseInt(data.credited_user, 10) || 0;
					totals.skipped += parseInt(data.skipped, 10) || 0;
					addReasonCounts(totals.skipReasons, data.skip_reasons || {});
					totals.failed += parseInt(data.failed, 10) || 0;
					totals.totalOrders = parseInt(data.total_orders, 10) || totals.totalOrders;

					if (data.done) {
						const parts = [];

						if (totals.addedGuestCustomers > 0) {
							parts.push(
								(strings.syncCompleteGuestsAdded || '%1$d new guest customers created')
									.replace('%1$d', totals.addedGuestCustomers)
							);
						}
						if (totals.addedGuest > 0) {
							parts.push(
								(strings.syncCompleteAdded || '%1$d new guest order point entries added')
									.replace('%1$d', totals.addedGuest)
							);
						}
						if (totals.updatedGuest > 0) {
							parts.push(
								(strings.syncCompleteUpdated || '%1$d existing guest order point entries rebuilt')
									.replace('%1$d', totals.updatedGuest)
							);
						}
						if (totals.creditedUser > 0) {
							parts.push(
								(strings.syncCompleteCredited || '%1$d registered customers credited')
									.replace('%1$d', totals.creditedUser)
							);
						}
						if (totals.skipped > 0) {
							parts.push(
								(strings.syncCompleteSkipped || '%1$d orders skipped with no guest point changes')
									.replace('%1$d', totals.skipped)
							);
						}

						const summary = (strings.syncComplete || 'Sync complete.')
							+ (parts.length ? ' ' + parts.join(', ') + '.' : '');

						const logUrl = config.logPageUrl || '';
						const logLabel = strings.syncCompleteViewLog || 'View Points Activity Log';

						const logLink = logUrl
							? ' <a href="' + logUrl + '">' + $('<span>').text(logLabel).html() + '</a>.'
							: '';
						const skipBreakdown = formatSkipReasonBreakdown(totals.skipReasons);
						const skipDetails = skipBreakdown
							? '<br><span class="description">' + $('<span>').text((strings.syncSkipReasonPrefix || 'Skipped orders:') + ' ' + skipBreakdown + '.').html() + '</span>'
							: '';

						const html = '<strong>' + $('<span>').text(summary).html() + '</strong>' + logLink + skipDetails;

						try {
							sessionStorage.setItem('spar_sync_complete', JSON.stringify({ html: html }));
						} catch (e) {}

						window.location.reload();
						return;
					}

					updateSyncProgress(totals);
					runBatch(parseInt(data.next_page, 10) || page + 1);
				},
				error: function() {
					setSyncStatus('error', strings.syncError || 'Guest points sync failed.');
					setSyncControlsDisabled(false);
					toggleOverrideFields();
				}
			});
		}

		runBatch(1);
	});

	function setInlineMessage(container, message, type) {
		container
			.find('.spar-guest-points-message')
			.removeClass('is-success is-error')
			.addClass(type === 'success' ? 'is-success' : 'is-error')
			.text(message)
			.show();
	}

	function setEditMode(container, editing) {
		container.find('.spar-guest-points-message').hide().text('');
		container.find('.spar-guest-points-display, .spar-guest-points-edit-button').toggle(!editing);
		container.find('.spar-guest-points-editor').prop('hidden', !editing);

		if (editing) {
			container.find('.spar-guest-points-input')
				.val(container.data('current-points'))
				.trigger('focus')
				.trigger('select');
		}
	}

	function setRowLoading(container, loading) {
		container.find('button, input').prop('disabled', loading);
		container.find('.spar-guest-points-spinner').toggleClass('is-active', loading);
	}

	$(document).on('click', '.spar-guest-points-edit-button', function(e) {
		e.preventDefault();
		setEditMode($(this).closest('.spar-guest-points-edit'), true);
	});

	$(document).on('click', '.spar-guest-points-cancel-button', function(e) {
		e.preventDefault();
		setEditMode($(this).closest('.spar-guest-points-edit'), false);
	});

	$(document).on('click', '.spar-guest-points-save-button', function(e) {
		e.preventDefault();

		const button = $(this);
		const container = button.closest('.spar-guest-points-edit');
		const input = container.find('.spar-guest-points-input');
		const rawPoints = input.val();
		const numericPoints = Number(rawPoints);

		if (rawPoints === '' || !Number.isFinite(numericPoints) || numericPoints < 0) {
			setInlineMessage(container, strings.invalidPoints || 'Please enter a valid points total.', 'error');
			input.trigger('focus');
			return;
		}

		const points = Math.floor(numericPoints);
		setRowLoading(container, true);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'spar_update_guest_points_total',
				nonce: config.editNonce,
				email: container.data('email'),
				points: points
			},
			success: function(response) {
				if (!response || !response.success) {
					setInlineMessage(container, getResponseMessage(response, strings.saveError || 'Could not save guest points.'), 'error');
					return;
				}

				const data = response.data || {};
				const newPoints = parseInt(data.points, 10) || 0;
				container.data('current-points', newPoints).attr('data-current-points', newPoints);
				container.find('.spar-guest-points-value').text(data.points_formatted || newPoints.toLocaleString());
				setEditMode(container, false);
				setInlineMessage(container, strings.saved || 'Saved.', 'success');
			},
			error: function() {
				setInlineMessage(container, strings.saveError || 'Could not save guest points.', 'error');
			},
			complete: function() {
				setRowLoading(container, false);
			}
		});
	});

	$(document).on('keydown', '.spar-guest-points-input', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$(this).closest('.spar-guest-points-editor').find('.spar-guest-points-save-button').trigger('click');
		} else if (e.key === 'Escape') {
			e.preventDefault();
			setEditMode($(this).closest('.spar-guest-points-edit'), false);
		}
	});

	// ── Add Guest Customer ────────────────────────────────────

	function setAddGuestStatus(type, message) {
		const status = $('#spar-add-guest-status');
		status
			.removeClass('notice-info notice-success notice-error notice-warning')
			.addClass('notice-' + type)
			.show()
			.find('p')
			.text(message);
	}

	function resetAddGuestForm() {
		$('#spar-add-guest-email').val('');
		$('#spar-add-guest-points').val('');
		$('#spar-add-guest-status').hide().find('p').text('');
		$('#spar-submit-add-guest, #spar-cancel-add-guest').prop('disabled', false);
		$('.spar-add-guest-spinner').removeClass('is-active');
	}

	$('#spar-add-guest-customer').on('click', function(e) {
		e.preventDefault();
		const addPanel = $('#spar-add-guest-options');
		const shouldShow = addPanel.prop('hidden');

		// Close sync panel when opening add-guest panel.
		if (shouldShow) {
			$('#spar-sync-guest-points-options').prop('hidden', true);
		}

		addPanel.prop('hidden', !shouldShow);

		if (shouldShow) {
			$('#spar-add-guest-email').trigger('focus');
		}
	});

	$('#spar-cancel-add-guest').on('click', function(e) {
		e.preventDefault();
		$('#spar-add-guest-options').prop('hidden', true);
		resetAddGuestForm();
	});

	$('#spar-submit-add-guest').on('click', function(e) {
		e.preventDefault();

		const email = $.trim($('#spar-add-guest-email').val() || '');
		const rawPoints = $('#spar-add-guest-points').val();
		const points = parseInt(rawPoints, 10);
		const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

		if (!emailPattern.test(email)) {
			setAddGuestStatus('error', strings.invalidEmail || 'Please enter a valid email address.');
			$('#spar-add-guest-email').trigger('focus');
			return;
		}

		if (!rawPoints || !Number.isFinite(points) || points < 1) {
			setAddGuestStatus('error', strings.addGuestInvalidPoints || 'Points to grant must be at least 1.');
			$('#spar-add-guest-points').trigger('focus');
			return;
		}

		$('#spar-submit-add-guest, #spar-cancel-add-guest').prop('disabled', true);
		$('.spar-add-guest-spinner').addClass('is-active');
		setAddGuestStatus('info', strings.addGuestSaving || 'Creating guest customer...');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'spar_add_guest_customer',
				nonce: config.addGuestNonce,
				email: email,
				points: points
			},
			success: function(response) {
				if (!response || !response.success) {
					setAddGuestStatus('error', getResponseMessage(response, strings.addGuestError || 'Could not create guest customer.'));
					$('#spar-submit-add-guest, #spar-cancel-add-guest').prop('disabled', false);
					$('.spar-add-guest-spinner').removeClass('is-active');
					return;
				}

				setAddGuestStatus('success', strings.addGuestSuccess || 'Guest customer added successfully. Reloading...');
				setTimeout(function() {
					window.location.reload();
				}, 1200);
			},
			error: function() {
				setAddGuestStatus('error', strings.addGuestError || 'Could not create guest customer.');
				$('#spar-submit-add-guest, #spar-cancel-add-guest').prop('disabled', false);
				$('.spar-add-guest-spinner').removeClass('is-active');
			}
		});
	});

	$(document).on('keydown', '#spar-add-guest-email, #spar-add-guest-points', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$('#spar-submit-add-guest').trigger('click');
		} else if (e.key === 'Escape') {
			e.preventDefault();
			$('#spar-cancel-add-guest').trigger('click');
		}
	});
});
