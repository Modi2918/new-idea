/**
 * FormCraft License Server Admin JS
 */
jQuery(document).ready(function($) {
	// --- Dark Mode Logic ---
	var rootEl = $('#fcac_server_app_root');
	var themeToggle = $('#fcac_server_theme_toggle');
	
	// Check localStorage or system preference
	var savedTheme = localStorage.getItem('fcac_server_theme');
	if (savedTheme) {
		rootEl.attr('data-theme', savedTheme);
	} else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
		rootEl.attr('data-theme', 'dark');
	}

	themeToggle.on('click', function() {
		var currentTheme = rootEl.attr('data-theme');
		var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
		rootEl.attr('data-theme', newTheme);
		localStorage.setItem('fcac_server_theme', newTheme);
	});
	// -----------------------

	// --- Auto Set Expiry Date based on Plan ---
	var planSelect = $('select[name="plan_type"]');
	var expiryInput = $('input[name="expires_at"]');
	var activationsInput = $('input[name="max_activations"]');

	if (planSelect.length && expiryInput.length && activationsInput.length) {
		planSelect.on('change', function() {
			var plan = $(this).val();
			var date = new Date();

			// Auto set expiry date
			if (plan === 'yearly') {
				date.setFullYear(date.getFullYear() + 1);
				expiryInput.val(date.toISOString().split('T')[0]);
			} else if (plan === 'monthly') {
				date.setMonth(date.getMonth() + 1);
				expiryInput.val(date.toISOString().split('T')[0]);
			} else {
				expiryInput.val('');
			}

			// Auto set max activations
			if (plan === '5-site') {
				activationsInput.val(5);
			} else if (plan === 'unlimited') {
				activationsInput.val(9999);
			} else {
				activationsInput.val(1);
			}
		});

		// Trigger on load to set initial expiry if empty
		if (!expiryInput.val()) {
			planSelect.trigger('change');
		}
	}
	// -----------------------
});
