document.addEventListener('DOMContentLoaded', function () {
	// Basic admin functionality only - settings moved to admin-settings.js
	// If we're on the settings page, do not bind accordion behavior here.
	if (document.getElementById('spar-settings-tabs')) {
		return;
	}
	
	// Accordion toggle for non-settings pages
	const headers = document.querySelectorAll('.spar-accordion-header');

	headers.forEach(header => {
		header.addEventListener('click', function () {
			const body = this.nextElementSibling;
			body.style.display = body.style.display === 'block' ? 'none' : 'block';
			this.classList.toggle('active');
		});
	});
});
