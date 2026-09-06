jQuery(document).ready(function($){
	$('#spar-modify-potential-points').on('click', function(e){
		e.preventDefault();
		$(this).hide();
		$('#spar-potential-points-display').hide();
		$('#spar-custom-points-container').show();
	});

	$('#spar-cancel-modify-points').on('click', function(e){
		e.preventDefault();
		$('#spar-custom-points-container').hide();
		$('#spar-potential-points-display').show();
		$('#spar-modify-potential-points').show();
	});
	
	$('#spar-save-potential-points').on('click', function(e){
		e.preventDefault();
		var btn = $(this);
		var container = $('#spar-custom-points-container');
		var points = $('#spar_custom_potential_points').val();
		var orderId = btn.data('order-id');
		var nonce = btn.data('nonce');
		
		container.find('.spinner').addClass('is-active');
		btn.prop('disabled', true);
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_save_custom_potential_points',
				order_id: orderId,
				points: points,
				nonce: nonce
			},
			success: function(response) {
				container.find('.spinner').removeClass('is-active');
				btn.prop('disabled', false);
				
				if ( response.success ) {
					$('#spar-potential-points-display').text(points).show();
					$('#spar-modify-potential-points').show();
					container.hide();
				} else {
					alert( response.data || 'Error saving points' );
				}
			},
			error: function() {
				container.find('.spinner').removeClass('is-active');
				btn.prop('disabled', false);
				alert('Error saving points');
			}
		});
	});

	$('#spar-save-delayed-points').on('click', function(e){
		e.preventDefault();
		var btn = $(this);
		var panel = $('#spar-delayed-order-points');
		var spinner = panel.find('.spinner');
		var points = {};

		panel.find('.spar-delayed-points-input').each(function(){
			var input = $(this);
			points[input.data('pending-id')] = input.val();
		});

		spinner.show();
		btn.prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_save_delayed_order_points',
				order_id: panel.data('order-id'),
				points: points,
				nonce: panel.data('nonce')
			},
			success: function(response) {
				if ( response.success ) {
					window.location.reload();
					return;
				}

				spinner.hide();
				btn.prop('disabled', false);
				alert( response.data || 'Error saving delayed points' );
			},
			error: function() {
				spinner.hide();
				btn.prop('disabled', false);
				alert('Error saving delayed points');
			}
		});
	});

	$('#spar-recalculate-delayed-points').on('click', function(e){
		e.preventDefault();
		var btn = $(this);
		var panel = $('#spar-delayed-order-points');
		var spinner = panel.find('.spinner');

		spinner.show();
		btn.prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_recalculate_delayed_order_points',
				order_id: panel.data('order-id'),
				nonce: panel.data('nonce')
			},
			success: function(response) {
				if ( response.success ) {
					window.location.reload();
					return;
				}

				spinner.hide();
				btn.prop('disabled', false);
				alert( response.data || 'Error recalculating delayed points' );
			},
			error: function() {
				spinner.hide();
				btn.prop('disabled', false);
				alert('Error recalculating delayed points');
			}
		});
	});

	$('#spar-grant-delayed-points').on('click', function(e){
		e.preventDefault();
		var btn = $(this);
		var panel = $('#spar-delayed-order-points');
		var spinner = panel.find('.spinner');
		var confirmMessage = btn.data('confirm') || 'Grant these delayed points now? This cannot be undone.';
		var points = {};

		if ( ! window.confirm( confirmMessage ) ) {
			return;
		}

		panel.find('.spar-delayed-points-input').each(function(){
			var input = $(this);
			points[input.data('pending-id')] = input.val();
		});

		spinner.show();
		panel.find('button').prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_grant_delayed_order_points',
				order_id: panel.data('order-id'),
				points: points,
				nonce: panel.data('nonce')
			},
			success: function(response) {
				if ( response.success ) {
					window.location.reload();
					return;
				}

				spinner.hide();
				panel.find('button').prop('disabled', false);
				alert( response.data || 'Error granting delayed points' );
			},
			error: function() {
				spinner.hide();
				panel.find('button').prop('disabled', false);
				alert('Error granting delayed points');
			}
		});
	});

	$('.spar-award-order-points').on('click', function(e){
		e.preventDefault();
		var btn = $(this);
		var orderId = btn.data('order-id');
		var nonce = btn.data('nonce');
		var wrapper = btn.closest('p');
		var spinner = btn.next('.spinner');

		if ( ! orderId || ! nonce ) {
			alert('Invalid request. Please refresh the page and try again.');
			return;
		}

		spinner.addClass('is-active');
		btn.prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'spar_award_order_points_admin',
				order_id: orderId,
				nonce: nonce
			},
			success: function(response) {
				spinner.removeClass('is-active');
				if ( response.success ) {
					if ( response.data && response.data.status === 'delayed' ) {
						window.location.reload();
						return;
					}

					var pointsText = '✅ Points awarded';
					if ( response.data && typeof response.data.points !== 'undefined' ) {
						pointsText = '✅ Points awarded: ' + response.data.points;
					}
					wrapper.removeClass('notice-error').addClass('notice-success');
					wrapper.find('strong').text(pointsText);
					btn.remove();
					spinner.remove();
				} else {
					btn.prop('disabled', false);
					alert( response.data || 'Error awarding points' );
				}
			},
			error: function() {
				spinner.removeClass('is-active');
				btn.prop('disabled', false);
				alert('Error awarding points');
			}
		});
	});
});
