(function ($) {
	'use strict';

	var settings = window.sparMigration || {};

	if (!settings.ajaxUrl) {
		return;
	}

	$(function () {
		var $form = $('#spar-migration-form');
		if (!$form.length) {
			return;
		}

		var $pluginSelect = $('#spar_migration_plugin');
		var $modeSelect = $('#spar_migration_mode');
		var $dataCheckboxes = $('input[name="spar_migration_data[]"]');
		var $birthdayCheckbox = $dataCheckboxes.filter('[value="birthday"]');
		var $referralCheckbox = $dataCheckboxes.filter('[value="referral_code"]');
		var $userStatusCheckbox = $dataCheckboxes.filter('[value="user_status"]');
		var $startButton = $('#spar-migration-start');
		var $spinner = $form.find('.spinner');
		var $log = $('#spar-migration-log');
		var $entries = $log.find('.spar-migration-log__entries');
		var supports = settings.supports || {};
		var userStatusSupported = Array.isArray(supports.userStatus) ? supports.userStatus : [];

		var state = {
			running: false,
			offset: 0,
			plugin: '',
			mode: 'override',
			migrateData: []
		};

		function appendEntry(message, type) {
			var entry = document.createElement('div');
			entry.className = 'spar-migration-log__entry';
			if (type) {
				entry.className += ' spar-migration-log__entry--' + type;
			}
			entry.appendChild(document.createTextNode(message));
			$entries.append(entry);

			if ($entries.length) {
				$entries.scrollTop($entries[0].scrollHeight);
			}
		}

		function toggleRunning(running) {
			state.running = running;
			$startButton.prop('disabled', running);
			if ($spinner.length) {
				$spinner[running ? 'addClass' : 'removeClass']('is-active');
			}
		}

		function pluginSupportsUserStatus(plugin) {
			if (!plugin) {
				return false;
			}
			return userStatusSupported.indexOf(plugin) !== -1;
		}

		function updateFieldVisibility() {
			var plugin = $pluginSelect.val();
			var targets = [
				{ $input: $birthdayCheckbox, disableFor: ['myrewards'] },
				{ $input: $referralCheckbox, disableFor: ['myrewards', 'woo_points_rewards', 'yith_points_rewards'] },
				{ $input: $userStatusCheckbox, allow: userStatusSupported }
			];

			targets.forEach(function(target){
				if (!target.$input || !target.$input.length) {
					return;
				}
				var $label = target.$input.closest('label');
				var shouldDisable = false;
				if (target.allow && Array.isArray(target.allow)) {
					shouldDisable = target.allow.indexOf(plugin) === -1;
				} else if (target.disableFor && Array.isArray(target.disableFor)) {
					shouldDisable = target.disableFor.indexOf(plugin) !== -1;
				}

				if (shouldDisable) {
					// Hide and disable when not supported for the selected plugin
					if (plugin) {
						target.$input.prop('checked', false);
					}
					target.$input.prop('disabled', true);
					if ($label && $label.length) {
						$label.hide();
					}
				} else {
					// Show and enable for supported plugins
					target.$input.prop('disabled', false);
					if ($label && $label.length) {
						$label.show();
					}
				}
			});
		}

		function modeLabel(mode) {
			if (mode === 'add') {
				return settings.strings && settings.strings.modeAdd ? settings.strings.modeAdd : 'add';
			}
			return settings.strings && settings.strings.modeOverride ? settings.strings.modeOverride : 'override';
		}

		function processBatch() {
			if (!state.running) {
				return;
			}

			$.post(settings.ajaxUrl, {
				action: 'spar_migration_process_batch',
				nonce: settings.nonce || '',
				plugin: state.plugin,
				mode: state.mode,
				offset: state.offset,
				migrate_data: state.migrateData
			}).done(function (response) {
				if (!response || !response.success) {
					var errorMessage = settings.strings && settings.strings.genericError ? settings.strings.genericError : 'Unexpected response from the server.';
					if (response && response.data && response.data.message) {
						errorMessage = response.data.message;
					}
					appendEntry(errorMessage, 'error');
					toggleRunning(false);
					return;
				}

				var data = response.data || {};
				if (Array.isArray(data.entries)) {
					data.entries.forEach(function (message) {
						appendEntry(message, 'log');
					});
				}

				var nextOffset = parseInt(data.next_offset, 10);
				if (isNaN(nextOffset)) {
					nextOffset = state.offset + (settings.batchSize || 10);
				}
				state.offset = nextOffset;

				if (data.complete) {
					var completeMessage = settings.strings && settings.strings.completed ? settings.strings.completed : 'Migration completed. Please review and verify the amounts.';
					appendEntry(completeMessage, 'success');
					toggleRunning(false);
					return;
				}

				window.setTimeout(processBatch, 150);
			}).fail(function (jqXHR) {
				var fallback = 'Request failed.';
				if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
					fallback = jqXHR.responseJSON.data.message;
				} else if (jqXHR && jqXHR.statusText) {
					fallback = jqXHR.statusText;
				}

				var message = settings.strings && settings.strings.failed ? settings.strings.failed.replace('%s', fallback) : ('Migration request failed: ' + fallback);
				appendEntry(message, 'error');
				toggleRunning(false);
			});
		}

		$form.on('submit', function (event) {
			event.preventDefault();

			if (state.running) {
				return;
			}

			var plugin = $pluginSelect.val();
			if (!plugin) {
				var warning = settings.strings && settings.strings.selectPlugin ? settings.strings.selectPlugin : 'Please select a plugin before starting the migration.';
				appendEntry(warning, 'warning');
				return;
			}

			// Get selected data fields
			var selectedData = [];
			$dataCheckboxes.filter(':checked').each(function () {
				selectedData.push($(this).val());
			});

			// Enforce that MyRewards does not send birthday/referral
			if (plugin === 'myrewards') {
				selectedData = selectedData.filter(function (val) { return val !== 'birthday' && val !== 'referral_code'; });
			}

			// WooCommerce Points and Rewards does not support referral codes
			if (plugin === 'woo_points_rewards') {
				selectedData = selectedData.filter(function (val) { return val !== 'referral_code'; });
			}

			if (!pluginSupportsUserStatus(plugin)) {
				selectedData = selectedData.filter(function (val) { return val !== 'user_status'; });
			}

			if (selectedData.length === 0) {
				var dataWarning = 'Please select at least one data field to migrate.';
				appendEntry(dataWarning, 'warning');
				return;
			}

			state.plugin = plugin;
			state.mode = $modeSelect.val();
			state.offset = 0;
			state.migrateData = selectedData;

			var pluginLabel = settings.plugins && settings.plugins[plugin] ? settings.plugins[plugin] : plugin;
			var humanMode = modeLabel(state.mode);
			var intro = settings.strings && settings.strings.starting ? settings.strings.starting.replace('%1$s', pluginLabel).replace('%2$s', humanMode) : ('Starting migration from ' + pluginLabel + ' using ' + state.mode + ' mode.');
			appendEntry('\u2014 ' + intro, 'info');

			toggleRunning(true);
			processBatch();
		});

		// Initialize field visibility and react to plugin change
		updateFieldVisibility();
		$pluginSelect.on('change', updateFieldVisibility);
	});
})(jQuery);
