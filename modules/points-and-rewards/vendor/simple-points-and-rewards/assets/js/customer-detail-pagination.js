/**
 * Customer detail admin pagination
 */
jQuery(function($) {
	'use strict';

	// Core table configs. Premium features can extend via sparPaginationConfigs.
	var coreConfigs = {
		points: {
			action: 'spar_admin_load_points_history',
			target: '#spar-points-log-table tbody'
		},
		referrals: {
			action: 'spar_admin_load_referral_clicks',
			target: '#spar-referrals-table tbody'
		},
		orders: {
			action: 'spar_admin_load_customer_orders',
			target: '.spar-orders-table tbody'
		}
	};

	// Expose a global registry so premium scripts can register additional tables.
	window.sparPaginationConfigs = window.sparPaginationConfigs || {};

	function getTableConfig(tableKey) {
		if (coreConfigs[tableKey]) {
			return coreConfigs[tableKey];
		}
		if (window.sparPaginationConfigs[tableKey]) {
			return window.sparPaginationConfigs[tableKey];
		}
		return null;
	}

	function setLoading($container, isLoading) {
		$container.toggleClass('is-loading', !!isLoading);
		$container.find('.spar-pagination-loading').toggle(!!isLoading);
	}

	function loadPage(tableKey, page) {
		var config = getTableConfig(tableKey);
		if (!config) {
			return;
		}

		var $pagination = $('.spar-pagination[data-table="' + tableKey + '"]');
		setLoading($pagination, true);

		$.ajax({
			url: sparCustomerDetail.ajaxurl,
			type: 'POST',
			data: {
				action: config.action,
				user_id: sparCustomerDetail.userId,
				nonce: sparCustomerDetail.nonce,
				page: page
			}
		}).done(function(response) {
			if (response && response.success) {
				$(config.target).html(response.data.table_html);
				$pagination.html(response.data.pagination_html);
			} else {
				alert(sparCustomerDetail.i18n.error);
			}
		}).fail(function() {
			alert(sparCustomerDetail.i18n.error);
		}).always(function() {
			setLoading($pagination, false);
		});
	}

	$(document).on('click', '.spar-pagination-btn', function(e) {
		e.preventDefault();
		if ($(this).is('[disabled]')) {
			return;
		}
		var page = parseInt($(this).data('page'), 10) || 1;
		var tableKey = $(this).closest('.spar-pagination').data('table') || $(this).closest('.spar-pagination-controls').data('table');
		if (!tableKey) {
			return;
		}
		loadPage(tableKey, page);
	});
});
