/**
 * Countdown Timer for Referral Offers
 */
(function($) {
	'use strict';
	
	function updateCountdown() {
		if (typeof sparCountdown === 'undefined' || !sparCountdown.expiresAt) {
			return;
		}
		
		const now = Math.floor(Date.now() / 1000);
		const timeLeft = sparCountdown.expiresAt - now;
		
		if (timeLeft <= 0) {
			$('.countdown-text').text('Expired');
			return;
		}
		
		const hours = Math.floor(timeLeft / 3600);
		const minutes = Math.floor((timeLeft % 3600) / 60);
		const seconds = timeLeft % 60;
		
		let timeString = '';
		if (hours > 0) {
			timeString = hours + 'h ' + minutes + 'm ' + seconds + 's';
		} else if (minutes > 0) {
			timeString = minutes + 'm ' + seconds + 's';
		} else {
			timeString = seconds + 's';
		}
		
		$('.countdown-text').text('Expires in ' + timeString);
	}
	
	$(document).ready(function() {
		if (typeof sparCountdown !== 'undefined' && sparCountdown.expiresAt) {
			// Update immediately
			updateCountdown();
			
			// Update every second
			setInterval(updateCountdown, 1000);
		}
	});
	
})(jQuery);
