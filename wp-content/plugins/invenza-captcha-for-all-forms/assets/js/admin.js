/**
 * Invcaf CAPTCHA Admin Settings Dashboard Handler
 */
jQuery( document ).ready(
	function ($) {
		// --- Dark Mode Logic ---
		var rootEl      = $( '#invcaf_app_root, #invenza_captcha_app_root' );
		var themeToggle = $( '#invcaf_theme_toggle, #invenza_captcha_theme_toggle' );

		// Check localStorage or system preference
		var savedTheme = localStorage.getItem( 'invcaf_theme' ) || localStorage.getItem( 'invenza_captcha_theme' );
		if (savedTheme) {
			rootEl.attr( 'data-theme', savedTheme );
		} else if (window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches) {
			rootEl.attr( 'data-theme', 'dark' );
		}

		themeToggle.on(
			'click',
			function () {
				var currentTheme = rootEl.attr( 'data-theme' );
				var newTheme     = currentTheme === 'dark' ? 'light' : 'dark';
				rootEl.attr( 'data-theme', newTheme );
				localStorage.setItem( 'invcaf_theme', newTheme );
				localStorage.setItem( 'invenza_captcha_theme', newTheme );
			}
		);
		// -----------------------

		// --- Copy System Info ---
		$( '#invcaf_btn_copy_sysinfo' ).on(
			'click',
			function () {
				var sysText = $( '#invcaf_sysinfo_text' );
				sysText.select();
				document.execCommand( 'copy' );
				var btn          = $( this );
				var originalText = btn.html();
				btn.html( '✅ Copied!' );
				setTimeout(
					function () {
						btn.html( originalText );
					},
					2000
				);
			}
		);

		// --- Test CAPTCHA Diagnostic Button ---
		$( '#invcaf_btn_test_captcha' ).on(
			'click',
			function () {
				var btn    = $( this );
				var resDiv = $( '#invcaf_test_captcha_result' );
				btn.prop( 'disabled', true ).text( 'Generating...' );
				resDiv.html( '<span style="color:#64748b;">Contacting GD image generator...</span>' );

				var adminVars = window.invcafAdmin || window.invenzaCaptchaAdmin || {};
				var nonce     = adminVars.nonce || '';

				$.ajax(
					{
						url: adminVars.ajax_url || ajaxurl,
						type: 'POST',
						data: {
							action: 'invcaf_test_captcha',
							nonce: nonce
						},
						success: function ( response ) {
							btn.prop( 'disabled', false ).text( '⚡ Test Engine' );
							if ( response.success ) {
								resDiv.html( '<span style="color:#10b981;font-weight:600;">✅ Success! Test image updated.</span>' );
								$( '#invcaf_live_preview_img' ).attr( 'src', response.data.image_url + '&t=' + new Date().getTime() );
							} else {
								var err = (response.data && response.data.message) ? response.data.message : 'Diagnostic check failed.';
								resDiv.html( '<span style="color:#ef4444; font-weight:bold;">❌ ' + err + '</span>' );
							}
						},
						error: function () {
							btn.prop( 'disabled', false ).html( '🔄 Refresh Test CAPTCHA' );
							resDiv.html( '<span style="color:#ef4444; font-weight:bold;">❌ Connection error.</span>' );
						}
					}
				);
			}
		);

		// --- Verify License Button ---
		$( '#invcaf_btn_verify_license' ).on(
			'click',
			function () {
				var btn        = $( this );
				var resDiv     = $( '#invcaf_verify_license_result' );
				var licenseKey = $( '#invcaf_license_key_input' ).val();
				btn.prop( 'disabled', true ).text( 'Verifying...' );
				resDiv.html( '<span style="color:#64748b;">Connecting to license server (license.isweb.in)...</span>' );

				var adminVars = window.invcafAdmin || window.invenzaCaptchaAdmin || {};
				var nonce     = adminVars.nonce || '';

				$.ajax(
					{
						url: adminVars.ajax_url || ajaxurl,
						type: 'POST',
						data: {
							action: 'invcaf_verify_license',
							nonce: nonce,
							license_key: licenseKey
						},
						success: function ( response ) {
							btn.prop( 'disabled', false ).text( '🔑 Activate License' );
							if ( response.success ) {
								resDiv.html( '<span style="color:#10b981; font-weight:600;">✅ ' + response.data.message + '</span>' );
								setTimeout( function() { location.reload(); }, 1500 );
							} else {
								var err = (response.data && response.data.message) ? response.data.message : 'License verification failed.';
								resDiv.html( '<span style="color:#ef4444; font-weight:bold;">❌ ' + err + '</span>' );
							}
						},
						error: function () {
							btn.prop( 'disabled', false ).text( '🔑 Activate License' );
							resDiv.html( '<span style="color:#ef4444; font-weight:bold;">❌ Connection error to license server.</span>' );
						}
					}
				);
			}
		);
	}
);
