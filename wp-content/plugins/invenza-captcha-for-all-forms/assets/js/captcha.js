/**
 * Invcaf CAPTCHA JS
 * Performs dynamic field injection, AJAX refresh handling, and network interception.
 */

(function ($) {
	'use strict';

	var vars = window.invcaf_vars || window.invenza_captcha_vars || {};

	// Helper to sanitize and validate URLs to prevent DOM XSS.
	function sanitizeUrl(url) {
		if (typeof url !== 'string') {
			return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'; // transparent 1x1 GIF
		}
		var cleanUrl = url.trim();
		var lowerUrl = cleanUrl.toLowerCase();
		if (lowerUrl.startsWith( 'javascript:' ) || lowerUrl.startsWith( 'data:' ) || lowerUrl.startsWith( 'vbscript:' )) {
			return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
		}
		return cleanUrl;
	}

	// Helper to construct the CAPTCHA HTML block.
	function buildCaptchaHtml(formId, imageUrl, sessionKey) {
		var theme = vars.theme || 'light';

		var $wrapper = $(
			'<div>',
			{
				'class': 'fc-captcha-wrapper invcaf-captcha-wrapper invcaf-theme-' + theme,
				'data-form-id': formId
			}
		);

		var $label = $(
			'<label>',
			{
				'for': 'invcaf_code_' + formId,
				'class': 'invcaf-screen-reader-text',
				text: 'Security Code (CAPTCHA)'
			}
		);

		var $imageWrapper = $( '<div>', { 'class': 'invcaf-image-wrapper' } );

		var $image = $(
			'<img>',
			{
				'class': 'invcaf-captcha-image',
				'src': sanitizeUrl( imageUrl ),
				'alt': 'CAPTCHA Image Challenge',
				'aria-describedby': 'invcaf_desc_' + formId
			}
		);

		var $desc = $(
			'<span>',
			{
				'id': 'invcaf_desc_' + formId,
				'class': 'invcaf-screen-reader-text',
				text: 'Visual verification code. Enter these characters into the input box below. If you cannot read them, click the refresh button next to the image to generate a new one.'
			}
		);

		var $refresh = $(
			'<button>',
			{
				'type': 'button',
				'class': 'invcaf-captcha-refresh',
				'aria-label': 'Refresh CAPTCHA Image',
				'title': 'Refresh CAPTCHA Image',
				html: '&#8635;'
			}
		);

		var $imageBox = $( '<div>', { 'class': 'invcaf-image-box' } );
		$imageBox.append( $image );

		// Accessible Status Container for screen readers
		var $liveStatus = $(
			'<div>',
			{
				'class': 'invcaf-screen-reader-text invcaf-live-status',
				'aria-live': 'polite'
			}
		);

		$imageWrapper.append( $desc, $imageBox, $refresh, $liveStatus );

		var $inputWrapper = $( '<div>', { 'class': 'invcaf-input-wrapper' } );

		var $input = $(
			'<input>',
			{
				'type': 'text',
				'id': 'invcaf_code_' + formId,
				'class': 'invcaf-captcha-input',
				'name': 'invcaf_code',
				'placeholder': 'Enter Code',
				'aria-required': 'true',
				'required': true,
				'autocomplete': 'off'
			}
		);

		var $hidden = $(
			'<input>',
			{
				'type': 'hidden',
				'class': 'invcaf-captcha-id',
				'name': 'invcaf_session_key',
				'val': sessionKey
			}
		);

		var $honeypot = $(
			'<input>',
			{
				'type': 'text',
				'name': 'invcaf_honeypot',
				'class': 'invcaf-honeypot',
				'tabindex': '-1',
				'autocomplete': 'off',
				'aria-hidden': 'true'
			}
		);

		$inputWrapper.append( $input, $hidden, $honeypot );

		// Input first, image+refresh second (correct visual order via CSS order property)
		$wrapper.append( $label, $inputWrapper, $imageWrapper );

		// Wrap in outer container for ResizeObserver-based stacking
		var $outer = $( '<div>', { 'class': 'invcaf-outer-container' } );
		$outer.append( $wrapper );

		return $outer;
	}

	// Helper checks if payload matches FormCraft submit actions.
	function checkIsFormCraft(data) {
		if ( ! data) {
			return false;
		}
		if (typeof data === 'string') {
			var str = data.toLowerCase();
			return str.indexOf( 'formcraft_submit' ) !== -1 || str.indexOf( 'fc_submit' ) !== -1 || str.indexOf( 'action' ) !== -1;
		} else if (data instanceof FormData) {
			if (data.has( 'action' )) {
				var action = data.get( 'action' );
				return action === 'formcraft_submit' || action === 'fc_submit';
			}
		}
		return false;
	}

	// Helper extracts FormCraft Form ID from request payload.
	function extractFormId(data) {
		if ( ! data) {
			return 0;
		}
		if (typeof data === 'string') {
			var trimmed = data.trim();
			if (trimmed.startsWith( '{' ) || trimmed.startsWith( '[' )) {
				try {
					var jsonObj = JSON.parse( data );
					return parseInt( jsonObj.id || jsonObj.form_id || (jsonObj.form_info && jsonObj.form_info.id), 10 ) || 0;
				} catch (e) {
				}
			}
			var match = data.match( /(?:^|&)id=(\d+)/ ) || data.match( /(?:^|&)form_id=(\d+)/ );
			if (match) {
				return parseInt( match[1], 10 );
			}
		} else if (data instanceof FormData) {
			return parseInt( data.get( 'id' ) || data.get( 'form_id' ), 10 ) || 0;
		}
		return 0;
	}

	// Helper injects CAPTCHA security fields into the request payload.
	function injectCaptchaData(data, code, sessionKey, honeypot) {
		if ( ! data) {
			return data;
		}
		if (typeof data === 'string') {
			var trimmed = data.trim();
			if (trimmed.startsWith( '{' ) || trimmed.startsWith( '[' )) {
				try {
					var jsonObj                         = JSON.parse( data );
					jsonObj.invcaf_code                 = code;
					jsonObj.invcaf_session_key          = sessionKey;
					jsonObj.invcaf_honeypot             = honeypot;
					jsonObj.invenza_captcha_code        = code;
					jsonObj.invenza_captcha_session_key = sessionKey;
					jsonObj.invenza_captcha_honeypot    = honeypot;
					return JSON.stringify( jsonObj );
				} catch (e) {
				}
			}
			return data + '&invcaf_code=' + encodeURIComponent( code ) +
							'&invcaf_session_key=' + encodeURIComponent( sessionKey ) +
							'&invcaf_honeypot=' + encodeURIComponent( honeypot ) +
							'&invenza_captcha_code=' + encodeURIComponent( code ) +
							'&invenza_captcha_session_key=' + encodeURIComponent( sessionKey ) +
							'&invenza_captcha_honeypot=' + encodeURIComponent( honeypot );
		} else if (data instanceof FormData) {
			data.set( 'invcaf_code', code );
			data.set( 'invcaf_session_key', sessionKey );
			data.set( 'invcaf_honeypot', honeypot );
			data.set( 'invenza_captcha_code', code );
			data.set( 'invenza_captcha_session_key', sessionKey );
			data.set( 'invenza_captcha_honeypot', honeypot );
			return data;
		} else if (typeof data === 'object') {
			data.invcaf_code                 = code;
			data.invcaf_session_key          = sessionKey;
			data.invcaf_honeypot             = honeypot;
			data.invenza_captcha_code        = code;
			data.invenza_captcha_session_key = sessionKey;
			data.invenza_captcha_honeypot    = honeypot;
			return data;
		}
		return data;
	}

	// 1. Global Network Hooks: Intercept all FormCraft form submissions to inject captcha data.
	// Intercept native XMLHttpRequest.
	(function (open, send) {
		XMLHttpRequest.prototype.open = function (method, url) {
			this._url    = url;
			this._method = method;
			open.apply( this, arguments );
		};

		XMLHttpRequest.prototype.send = function (data) {
			if (checkIsFormCraft( data )) {
				var formId   = extractFormId( data );
				var $form    = formId ? $( '.fc-form-' + formId + ', [data-id="' + formId + '"]' ) : $( '.fc-form' );
				var $captcha = $form.find( '.invcaf-captcha-wrapper' );
				if ($captcha.length > 0) {
					var code       = $captcha.find( '.invcaf-captcha-input' ).val();
					var sessionKey = $captcha.find( '.invcaf-captcha-id' ).val();
					var honeypot   = $captcha.find( '.invcaf-honeypot' ).val();
					data           = injectCaptchaData( data, code, sessionKey, honeypot );
				}
			}
			send.call( this, data );
		};
	})( XMLHttpRequest.prototype.open, XMLHttpRequest.prototype.send );

	// Intercept native Fetch API.
	if (window.fetch) {
		(function (originalFetch) {
			window.fetch = function (input, init) {
				if (init && init.body && checkIsFormCraft( init.body )) {
					var data     = init.body;
					var formId   = extractFormId( data );
					var $form    = formId ? $( '.fc-form-' + formId + ', [data-id="' + formId + '"]' ) : $( '.fc-form' );
					var $captcha = $form.find( '.invcaf-captcha-wrapper' );
					if ($captcha.length > 0) {
						var code       = $captcha.find( '.invcaf-captcha-input' ).val();
						var sessionKey = $captcha.find( '.invcaf-captcha-id' ).val();
						var honeypot   = $captcha.find( '.invcaf-honeypot' ).val();
						init.body      = injectCaptchaData( data, code, sessionKey, honeypot );
					}
				}
				return originalFetch.apply( this, arguments );
			};
		})( window.fetch );
	}

	// 2. Click Handler for Refresh Action.
	$( document ).on(
		'click',
		'.invcaf-captcha-refresh',
		function (e) {
			e.preventDefault();
			var $btn     = $( this );
			var $wrapper = $btn.closest( '.invcaf-captcha-wrapper' );
			var formId   = parseInt( $wrapper.attr( 'data-form-id' ), 10 );

			if ($btn.hasClass( 'invcaf-spinning' ) || $btn.prop( 'disabled' )) {
				return;
			}

			$btn.addClass( 'invcaf-spinning' );
			$btn.prop( 'disabled', true ).css( 'opacity', 0.5 );
			$wrapper.find( '.invcaf-live-status' ).text( 'Generating new security code image... Please wait.' );

			$.post(
				vars.ajaxurl,
				{
					action: 'invcaf_refresh_captcha',
					form_id: formId,
					security: vars.nonce
				},
				function (response) {
					$btn.prop( 'disabled', false ).css( 'opacity', 1 );
					$btn.removeClass( 'invcaf-spinning' );
					if (response.success) {
						var data = response.data;
						$wrapper.find( '.invcaf-captcha-image' ).attr( 'src', sanitizeUrl( data.image_url ) );
						$wrapper.find( '.invcaf-captcha-id' ).val( data.session_key );
						$wrapper.find( '.invcaf-captcha-input' ).val( '' ); // Clear input box
						$wrapper.find( '.invcaf-live-status' ).text( 'Security code image refreshed. Check the new challenge.' );
					} else {
						$wrapper.find( '.invcaf-live-status' ).text( 'Failed to refresh challenge. Please try again.' );
					}
				}
			).fail(
				function () {
					$btn.prop( 'disabled', false ).css( 'opacity', 1 );
					$btn.removeClass( 'invcaf-spinning' );
					$wrapper.find( '.invcaf-live-status' ).text( 'Connection error. Failed to refresh challenge.' );
				}
			);
		}
	);

	// 3. Periodic DOM Scanner. Scan page for uninitialized FormCraft forms.
	var cachedCaptchas = {};

	$( document ).ready(
		function () {
			if (vars.enabled !== '1' || vars.fc_enabled !== '1') {
				console.log( '[INVCAF Debug] Plugin is disabled globally or disabled for FormCraft. Exiting.' );
				return;
			}

			setInterval(
				function () {
					$( '.fc-form, .formcraft-css form, .fc-pagination-cover form' ).each(
						function () {
							var $form = $( this );

							if ($form.find( '.invcaf-captcha-wrapper, .fc-captcha-wrapper, .invcaf-captcha-placeholder' ).length > 0) {
								// Already injected
								return;
							}

							// Extract form ID from class.
							var formId    = 0;
							var classList = ($form.attr( 'class' ) || '').split( /\s+/ );
							$.each(
								classList,
								function (index, item) {
									if (item.indexOf( 'fc-form-' ) === 0) {
										formId = parseInt( item.replace( 'fc-form-', '' ), 10 );
									}
								}
							);

							if ( ! formId) {
								formId = parseInt( $form.find( 'input[name="form_id"], input[name="id"]' ).val() || $form.attr( 'data-id' ), 10 );
							}

							if ( ! formId) {
								// Check wrapper ID (FormCraft free version sometimes uses id="formcraft-X")
								var wrapperId = $form.closest( '[id^="formcraft-"]' ).attr( 'id' );
								if (wrapperId) {
									formId = parseInt( wrapperId.replace( 'formcraft-', '' ), 10 );
								}
							}

							if ( ! formId) {
								console.log( '[INVCAF Debug] Form found, but unable to extract formId. Aborting.' );
								return;
							}

							var $reCaptchaField = $form.find( '.form-element-type-reCaptcha' ).first();
							var $submit         = $form.find( '.form-element-type-submit, .submit-button-cover, .fc-submit-cover, .submit-cover, .submit-button, [type="submit"]' ).first();

							// Wait for AngularJS to finish rendering the form fields.
							if ($reCaptchaField.length === 0 && $submit.length === 0) {
								console.log( '[INVCAF Debug] Form ' + formId + ' found, but no submit button or recaptcha field found yet. Waiting for Angular...' );
								return;
							}

							// DOM is ready. We proceed to validations.
							console.log( '[INVCAF Debug] Form ' + formId + ' DOM is ready. Validating settings...' );

							// Validate form targeted condition.
							if (vars.fc_all_forms !== '1') {
								var selectedForms = vars.fc_forms.split( /[\s,]+/ ).map(
									function (id) {
										return parseInt( id, 10 );
									}
								);
								if ($.inArray( formId, selectedForms ) === -1) {
									console.log( '[INVCAF Debug] Form ' + formId + ' is NOT in the selected forms list. Aborting.' );
									return;
								}
							}

							// Skip injection if configured for Custom Shortcode only.
							if (vars.position === 'custom_shortcode') {
								console.log( '[INVCAF Debug] Position is custom_shortcode. Skipping automatic injection.' );
								return;
							}

							console.log( '[INVCAF Debug] Settings validated. Injecting CAPTCHA into form ' + formId );

							// Use cached CAPTCHA if available to prevent AJAX loops when Angular destroys the DOM.
							if (cachedCaptchas[formId]) {
								console.log( '[INVCAF Debug] Re-injecting cached CAPTCHA for form ' + formId + ' (Angular digest cycle detected)' );
								var $cachedHtml = cachedCaptchas[formId];
								if ($reCaptchaField.length > 0) {
									var $htmlContainer = $reCaptchaField.find( '.form-element-html' );
									if ($htmlContainer.length > 0) {
										$htmlContainer.empty().append( $cachedHtml );
									} else {
										$reCaptchaField.empty().append( $cachedHtml );
									}
								} else {
									if ($submit.length > 0) {
										$submit.before( $cachedHtml );
									} else {
										$form.append( $cachedHtml );
									}
								}
								return;
							}

							// Render temporary placeholder to block layout shifts.
							var $placeholder = $(
								'<div>',
								{
									'class': 'invcaf-captcha-wrapper fc-captcha-wrapper invcaf-captcha-placeholder',
									'data-form-id': formId
								}
							);
							var $loadingSpan = $(
								'<span>',
								{
									'class': 'invcaf-loading-text',
									text: 'Loading CAPTCHA...'
								}
							);
							$placeholder.append( $loadingSpan );

							// Inject based on settings.
							if ($reCaptchaField.length > 0) {
								// User explicitly placed a reCaptcha element in the builder. Replace it!
								var $htmlContainer = $reCaptchaField.find( '.form-element-html' );
								if ($htmlContainer.length > 0) {
									$htmlContainer.empty().append( $placeholder );
								} else {
									$reCaptchaField.empty().append( $placeholder );
								}
							} else {
								// Fallback: inject before the submit button wrapper or submit button
								if ($submit.length > 0) {
									$submit.before( $placeholder );
								} else {
									$form.append( $placeholder );
								}
							}

							// Retrieve CAPTCHA elements via AJAX.
							$.post(
								vars.ajaxurl,
								{
									action: 'invcaf_refresh_captcha',
									form_id: formId,
									security: vars.nonce
								},
								function (response) {
									if (response.success) {
										var data               = response.data;
										var $html              = buildCaptchaHtml( formId, data.image_url, data.session_key );
										cachedCaptchas[formId] = $html; // Cache it!
										$placeholder.replaceWith( $html );
									} else {
										var $errorSpan = $(
											'<span>',
											{
												'class': 'invcaf-error-text',
												text: response.data && response.data.message ? response.data.message : 'Error loading CAPTCHA'
											}
										);
										$placeholder.empty().append( $errorSpan );
									}
								}
							).fail(
								function () {
									var $failSpan = $(
										'<span>',
										{
											'class': 'invcaf-error-text',
											text: 'Failed to load CAPTCHA'
										}
									);
									$placeholder.empty().append( $failSpan );
								}
							);
						}
					);
				},
				1000
			);
		}
	);

	// --- ResizeObserver: add .invcaf-stacked when the outer container is narrow ---
	function initStackObserver() {
		var BREAKPOINT = 380;

		function applyStack(outer) {
			var w = outer.getBoundingClientRect().width;
			if (w > 0 && w < BREAKPOINT) {
				outer.classList.add( 'invcaf-stacked' );
			} else if (w >= BREAKPOINT) {
				outer.classList.remove( 'invcaf-stacked' );
			}
		}

		if (typeof ResizeObserver !== 'undefined') {
			var ro = new ResizeObserver(
				function (entries) {
					entries.forEach(
						function (entry) {
							applyStack( entry.target );
						}
					);
				}
			);

			$( document ).on(
				'invcaf:ready invcaf:injected',
				function () {
					$( '.invcaf-outer-container' ).each(
						function () {
							applyStack( this );
							ro.observe( this );
						}
					);
				}
			);

			// Also observe any containers already in DOM on load
			$(
				function () {
					$( '.invcaf-outer-container' ).each(
						function () {
							applyStack( this );
							ro.observe( this );
						}
					);
				}
			);
		} else {
			// Fallback: check on load and resize
			function checkAll() {
				$( '.invcaf-outer-container' ).each(
					function () {
						applyStack( this );
					}
				);
			}
			$( window ).on( 'load resize', checkAll );
			$(
				function () {
					checkAll(); }
			);
		}
	}

	initStackObserver();

})( jQuery );
