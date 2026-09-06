(function($) {
	'use strict';
	
	window.sparPreviewEmail = function(type) {
		if (typeof sparEmail === 'undefined') {
			alert('Email configuration not loaded');
			return;
		}
		
		const data = new FormData();
		data.append('action', 'spar_preview_email');
		data.append('email_type', type);
		data.append('nonce', sparEmail.nonce);
		
		fetch(sparEmail.ajaxUrl, {
			method: 'POST',
			body: data
		})
		.then(response => response.json())
		.then(result => {
			if (result.success) {
				alert(result.data);
			} else {
				alert('Error: ' + result.data);
			}
		})
		.catch(error => {
			alert('Error sending preview email: ' + error);
		});
	};
	
})(jQuery);
