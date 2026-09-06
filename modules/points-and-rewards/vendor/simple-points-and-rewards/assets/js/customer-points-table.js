/**
 * Customer Points Table JavaScript
 */
jQuery(document).ready(function($) {
	'use strict';

	const addPointsPanel = $('#spar-add-new-points-panel');
	const addPointsToggle = $('#spar-add-new-points-toggle');
	const addPointsTarget = $('#spar_points_target');

	function updateAddPointsFields() {
		if (!addPointsTarget.length) {
			return;
		}

		const isAllUsers = addPointsTarget.val() === 'all';
		$('.spar-add-points-specific-row').prop('hidden', isAllUsers);
		$('.spar-add-points-all-row').prop('hidden', !isAllUsers);
		$('#spar_points_username').prop('required', !isAllUsers);
	}

	addPointsToggle.on('click', function() {
		const isExpanded = addPointsToggle.attr('aria-expanded') === 'true';
		addPointsToggle.attr('aria-expanded', isExpanded ? 'false' : 'true');
		addPointsPanel.prop('hidden', isExpanded);
		if (!isExpanded) {
			updateAddPointsFields();
			addPointsTarget.trigger('focus');
		}
	});

	addPointsTarget.on('change', updateAddPointsFields);
	updateAddPointsFields();

	$('#spar-add-new-points-form').on('submit', function(e) {
		const pointsChange = parseInt($('#spar_points_change').val(), 10);
		if (!pointsChange) {
			e.preventDefault();
			alert(sparCustomerPoints.strings.invalidAmount);
			$('#spar_points_change').trigger('focus');
		}
	});

	// Copy referral URL functionality
	$(document).on('click', '.spar-copy-url', function(e) {
		e.preventDefault();
		
		const button = $(this);
		const url = button.data('url');
		const input = button.siblings('.spar-referral-url-input');
		
		// Select and copy the URL
		input.select();
		input[0].setSelectionRange(0, 99999); // For mobile devices
		
		try {
			if (navigator.clipboard && window.isSecureContext) {
				// Use modern clipboard API
				navigator.clipboard.writeText(url).then(function() {
					showNotification(sparCustomerPoints.strings.copied, 'success');
				}).catch(function() {
					fallbackCopy(input[0]);
				});
			} else {
				// Fallback for older browsers
				fallbackCopy(input[0]);
			}
		} catch (err) {
			showNotification(sparCustomerPoints.strings.copyFailed, 'error');
		}
	});

	// Fallback copy function
	function fallbackCopy(element) {
		try {
			document.execCommand('copy');
			showNotification(sparCustomerPoints.strings.copied, 'success');
		} catch (err) {
			showNotification(sparCustomerPoints.strings.copyFailed, 'error');
		}
	}

	// Add points functionality
	$(document).on('click', '.spar-add-points', function(e) {
		e.preventDefault();
		handlePointsUpdate($(this), 'add');
	});

	// Remove points functionality
	$(document).on('click', '.spar-remove-points', function(e) {
		e.preventDefault();
		
		if (!confirm(sparCustomerPoints.strings.confirmRemove)) {
			return;
		}
		
		handlePointsUpdate($(this), 'remove');
	});

	// Handle points update
	function handlePointsUpdate(button, action) {
		const container = button.closest('.spar-points-actions');
		const input = container.find('.spar-points-input');
		const reasonInput = container.find('.spar-points-reason');
		const points = parseInt(input.val());
		const userId = container.data('user-id');
		const nonce = container.data('nonce');
		const loading = container.find('.spar-points-loading');
		const controls = container.find('.spar-points-controls');
		const reason = reasonInput.length ? reasonInput.val() : '';

		// Validate points amount
		if (!points || points <= 0) {
			showInlineMessage(container, sparCustomerPoints.strings.invalidAmount, 'error');
			input.focus();
			return;
		}

		// Show loading state
		controls.hide();
		loading.show();

		// AJAX request
		$.ajax({
			url: sparCustomerPoints.ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_update_customer_points',
				user_id: userId,
				points: points,
				points_action: action,
				reason: reason,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					// Update points display
					const pointsDisplay = $(`[data-user-id="${userId}"].spar-points-display`);
					const newPoints = parseInt(response.data.new_points);
					pointsDisplay.find('.spar-points-value').text(newPoints.toLocaleString());

					if (response.data.total_earned !== undefined) {
						const totalEarnedDisplay = $(`[data-user-id="${userId}"].spar-total-earned-display`);
						const totalEarned = parseInt(response.data.total_earned);
						totalEarnedDisplay.find('.spar-total-earned-value').text(totalEarned.toLocaleString());
					}
					
					// Clear inputs
					input.val('');
					reasonInput.val('');
					
					// Show inline success and reload
					showInlineMessage(container, sparCustomerPoints.strings.success, 'success');
					setTimeout(function() {
						window.location.reload();
					}, 1000);
				} else {
					showInlineMessage(container, response.data || sparCustomerPoints.strings.error, 'error');
				}
			},
			error: function() {
				showInlineMessage(container, sparCustomerPoints.strings.error, 'error');
			},
			complete: function() {
				// Hide loading state
				loading.hide();
				controls.show();
			}
		});
	}

	function showInlineMessage(container, message, type) {
		let messageEl = container.find('.spar-points-inline-message');
		if (!messageEl.length) {
			messageEl = $('<div>', { class: 'spar-points-inline-message' });
			container.append(messageEl);
		}
		messageEl
			.removeClass('is-success is-error')
			.addClass(type === 'success' ? 'is-success' : 'is-error')
			.text(message)
			.show();
	}

	// Show notification
	function showNotification(message, type) {
		// Remove existing notifications
		$('.spar-notification').remove();
		
		// Create notification
		const notification = $('<div>', {
			class: `notice notice-${type} is-dismissible spar-notification`,
			html: `<p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>`
		});
		
		// Add to page
		$('.wrap h1').after(notification);
		
		// Auto dismiss after 3 seconds
		setTimeout(function() {
			notification.fadeOut(function() {
				$(this).remove();
			});
		}, 3000);
		
		// Manual dismiss
		notification.find('.notice-dismiss').on('click', function() {
			notification.fadeOut(function() {
				$(this).remove();
			});
		});
	}

	// Enter key support for points input
	$(document).on('keypress', '.spar-points-input', function(e) {
		if (e.which === 13) { // Enter key
			e.preventDefault();
			$(this).siblings('.spar-add-points').click();
		}
	});

	// Number input validation
	$(document).on('input', '.spar-points-input', function() {
		const value = $(this).val();
		if (value < 0) {
			$(this).val(0);
		}
	});
});