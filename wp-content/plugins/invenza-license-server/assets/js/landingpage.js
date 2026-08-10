/**
 * Invenza Pro Landing Page JavaScript & Native CSS Intersection Observer
 */

let activePlanKey = '';
let activePlanName = '';
let appliedCouponCode = '';   // Stores validated coupon code
let appliedDiscountPct = 0;   // Stores validated discount %

function openCheckoutModal(planKey, planName, planPrice) {
	activePlanKey = planKey;
	activePlanName = planName;
	appliedCouponCode = '';
	appliedDiscountPct = 0;
	document.getElementById('modalPlanKey').value = planKey;
	document.getElementById('modalPlanTitle').innerText = planName;
	document.getElementById('modalPlanPrice').innerText = planPrice;
	if (document.getElementById('modalCouponCode')) {
		document.getElementById('modalCouponCode').value = '';
	}
	if (document.getElementById('modalCouponSuccess')) {
		document.getElementById('modalCouponSuccess').classList.add('hidden');
	}
	document.getElementById('modalFormError').classList.add('hidden');
	document.getElementById('invenzaCheckoutModal').classList.remove('hidden');
}

function closeCheckoutModal() {
	document.getElementById('invenzaCheckoutModal').classList.add('hidden');
}

/**
 * Dynamically detects visitor's local ISO 4217 currency code for any country worldwide.
 * Uses browser Intl API locale formatting and timezone lookup.
 *
 * @return {string} ISO Currency Code (e.g. 'INR', 'EUR', 'GBP', 'USD', 'BRL', 'MXN', 'JPY', 'CAD', 'AUD', 'AED', etc.)
 */
function getUserLocalCurrency() {
	try {
		// 1. Try to extract currency from browser's locale default Intl formatter
		if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
			const locale = navigator.language || (navigator.languages && navigator.languages[0]) || 'en-US';
			const fmt = new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD' });
			const resolved = fmt.resolvedOptions();
			if (resolved && resolved.currency && resolved.currency !== 'USD') {
				return resolved.currency.toUpperCase();
			}
		}

		// 2. Fallback timezone lookup map for major global regions
		const tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || '').toLowerCase();

		if (tz.includes('kolkata') || tz.includes('calcutta') || tz.includes('india')) return 'INR';
		if (tz.includes('london') || tz.includes('belfast')) return 'GBP';
		if (tz.includes('paris') || tz.includes('berlin') || tz.includes('rome') || tz.includes('madrid') || tz.includes('amsterdam') || tz.includes('brussels') || tz.includes('vienna') || tz.includes('europe') || tz.includes('zurich')) return 'EUR';
		if (tz.includes('toronto') || tz.includes('vancouver') || tz.includes('montreal') || tz.includes('edmonton')) return 'CAD';
		if (tz.includes('sydney') || tz.includes('melbourne') || tz.includes('brisbane') || tz.includes('perth') || tz.includes('australia')) return 'AUD';
		if (tz.includes('dubai') || tz.includes('muscat') || tz.includes('abu_dhabi')) return 'AED';
		if (tz.includes('tokyo')) return 'JPY';
		if (tz.includes('saopaulo') || tz.includes('rio')) return 'BRL';
		if (tz.includes('mexico')) return 'MXN';
		if (tz.includes('singapore')) return 'SGD';
		if (tz.includes('bangkok')) return 'THB';
	} catch (e) {}

	return 'USD';
}

async function verifyInvenzaCoupon() {
	const couponInput = document.getElementById('modalCouponCode');
	const coupon = couponInput ? couponInput.value.trim() : '';
	const planKey = document.getElementById('modalPlanKey').value;
	const errorDiv = document.getElementById('modalFormError');
	const successDiv = document.getElementById('modalCouponSuccess');
	const email = document.getElementById('modalCustomerEmail') ? document.getElementById('modalCustomerEmail').value.trim() : '';
	const currency = getUserLocalCurrency();

	errorDiv.classList.add('hidden');
	successDiv.classList.add('hidden');

	if (!coupon) {
		errorDiv.innerText = 'Please enter a coupon code first.';
		errorDiv.classList.remove('hidden');
		return;
	}

	try {
		const applyBtn = document.getElementById('modalApplyCouponBtn');
		if (applyBtn) { applyBtn.disabled = true; applyBtn.innerText = 'Checking...'; }

		const res = await fetch('/wp-json/fcac-server/v1/checkout/apply-coupon', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ coupon: coupon, plan: planKey, email: email, currency: currency })
		});
		const data = await res.json();

		if (applyBtn) { applyBtn.disabled = false; applyBtn.innerText = 'Apply'; }

		if (data.success) {
			// Store validated coupon for use in checkout submission
			appliedCouponCode = data.coupon;
			appliedDiscountPct = data.discount_percent;

			// Populate the rich success banner
			const banner = document.getElementById('modalCouponSuccess');
			const titleEl = document.getElementById('modalCouponSuccessTitle');
			const msgEl   = document.getElementById('modalCouponSuccessMsg');
			const badge   = document.getElementById('modalCouponBadge');

			if (titleEl) titleEl.textContent = '🎉 Coupon "' + data.coupon + '" Applied!';
			if (msgEl)   msgEl.textContent   = 'You save ' + data.discount_percent + '% — ' + data.saved_amount + ' off!';
			if (badge)   badge.textContent   = '-' + data.discount_percent + '%';

			// Re-trigger CSS animation by forcing a reflow
			if (banner) {
				banner.style.animation = 'none';
				banner.offsetHeight; // trigger reflow
				banner.style.animation = '';
				banner.classList.remove('hidden');
			}

			// Update price display: strikethrough original → bold discounted + badge
			const priceEl = document.getElementById('modalPlanPrice');
			if (priceEl) {
				priceEl.innerHTML =
					'<span style="text-decoration:line-through;opacity:0.45;margin-right:8px;font-size:0.9em">' + data.original_price + '</span>' +
					'<span style="color:#16a34a;font-weight:800">' + data.discounted_price + '</span>' +
					'<span style="background:#22c55e;color:#fff;font-size:11px;padding:2px 8px;border-radius:99px;margin-left:8px;font-weight:700;box-shadow:0 2px 6px rgba(34,197,94,0.4)">SAVE ' + data.discount_percent + '%</span>';
			}
		} else {
			appliedCouponCode = '';
			appliedDiscountPct = 0;
			errorDiv.innerText = data.message || 'Invalid coupon code.';
			errorDiv.classList.remove('hidden');
		}
	} catch (err) {
		const applyBtn = document.getElementById('modalApplyCouponBtn');
		if (applyBtn) { applyBtn.disabled = false; applyBtn.innerText = 'Apply'; }
		errorDiv.innerText = 'Network error: Could not validate coupon.';
		errorDiv.classList.remove('hidden');
	}
}

async function submitInvenzaCheckout(e) {
	e.preventDefault();
	const name = document.getElementById('modalCustomerName').value.trim();
	const email = document.getElementById('modalCustomerEmail').value.trim();
	const planKey = document.getElementById('modalPlanKey').value;
	const errorDiv = document.getElementById('modalFormError');
	const submitBtn = document.getElementById('modalSubmitBtn');

	// Use server-validated coupon (stored in memory) or fall back to input value
	const coupon = appliedCouponCode || (document.getElementById('modalCouponCode') ? document.getElementById('modalCouponCode').value.trim().toUpperCase() : '');

	if (!email || !email.includes('@')) {
		errorDiv.innerText = 'Please enter a valid email address.';
		errorDiv.classList.remove('hidden');
		return;
	}

	errorDiv.classList.add('hidden');
	submitBtn.disabled = true;
	submitBtn.innerText = 'Initializing Checkout...';

	try {
		const currency = getUserLocalCurrency();

		// 1. Request Payment Intent from Invenza License Server
		const response = await fetch('/wp-json/fcac-server/v1/checkout/create-intent', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ email: email, name: name, plan: planKey, coupon: coupon, currency: currency, domain: window.location.hostname })
		});
		const data = await response.json();

		if (!data.success) {
			errorDiv.innerText = data.message || 'Failed to initialize payment gateway.';
			errorDiv.classList.remove('hidden');
			submitBtn.disabled = false;
			submitBtn.innerText = 'Proceed to Payment';
			return;
		}

		closeCheckoutModal();
		submitBtn.disabled = false;
		submitBtn.innerText = 'Proceed to Payment';

		// 2. Open Razorpay Gateway Modal with customer prefill
		const options = {
			"key": data.key_id,
			"amount": data.amount,
			"currency": data.currency,
			"name": "Invenza CAPTCHA Pro",
			"description": activePlanName + " Subscription",
			"order_id": data.order_id,
			"prefill": { "name": name, "email": email },
			"handler": async function (razorpayRes) {
				// 3. Confirm payment & fulfill key
				const confirmRes = await fetch('/wp-json/fcac-server/v1/webhook/payment-success', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						razorpay_payment_id: razorpayRes.razorpay_payment_id,
						razorpay_order_id: razorpayRes.razorpay_order_id,
						razorpay_signature: razorpayRes.razorpay_signature,
						customer_email: email,
						customer_name: name,
						plan: planKey
					})
				});
				const confirmData = await confirmRes.json();
				if (confirmData.status === 'success' || confirmData.success) {
					const licKey = confirmData.license_key || '';
					const dlUrl  = confirmData.download_url || '';

					// 4. Auto-trigger download in background (iframe avoids popup blockers)
					if (dlUrl) {
						const dlFrame = document.createElement('iframe');
						dlFrame.style.cssText = 'display:none;position:fixed;width:0;height:0;border:0;';
						dlFrame.src = dlUrl;
						document.body.appendChild(dlFrame);
						setTimeout(() => { if (dlFrame.parentNode) dlFrame.parentNode.removeChild(dlFrame); }, 30000);
					}

					// 5. Show premium success modal
					showPaymentSuccessModal(licKey, email, dlUrl);
				} else {
					showPaymentSuccessModal('', email, '');
				}
			},
			"theme": { "color": "#2563eb" }
		};
		const rzp = new Razorpay(options);
		rzp.open();

	} catch (err) {
		errorDiv.innerText = 'Network Error: Could not connect to Invenza License Server.';
		errorDiv.classList.remove('hidden');
		submitBtn.disabled = false;
		submitBtn.innerText = 'Proceed to Payment';
	}
}

document.addEventListener('DOMContentLoaded', () => {
	// Standard, clean CSS Intersection Observer for scroll entrances
	const observer = new IntersectionObserver((entries, observer) => {
		entries.forEach(entry => {
			if (entry.isIntersecting) {
				entry.target.classList.add('active');
				observer.unobserve(entry.target);
			}
		});
	}, { threshold: 0.15 });

	document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
});

/**
 * Shows a premium animated success modal after a successful payment.
 *
 * @param {string} licenseKey  - The license key issued.
 * @param {string} email       - The customer email.
 * @param {string} downloadUrl - The one-time secure download URL (or empty string).
 */
function showPaymentSuccessModal(licenseKey, email, downloadUrl) {
	// Remove any existing success modal
	const existing = document.getElementById('invenzaSuccessModal');
	if (existing) existing.remove();

	const hasKey = licenseKey && licenseKey.length > 4;
	const hasDl  = !!downloadUrl;

	const modal = document.createElement('div');
	modal.id = 'invenzaSuccessModal';
	modal.style.cssText = [
		'position:fixed;inset:0;z-index:999999;',
		'background:rgba(15,23,42,0.85);backdrop-filter:blur(8px);',
		'display:flex;align-items:center;justify-content:center;padding:16px;',
		'animation:fadeInBg 0.3s ease both;'
	].join('');

	modal.innerHTML = `
		<style>
			@keyframes fadeInBg  { from{opacity:0} to{opacity:1} }
			@keyframes slideUpIn { from{opacity:0;transform:translateY(40px) scale(0.97)} to{opacity:1;transform:translateY(0) scale(1)} }
			@keyframes popIn     { 0%{transform:scale(0.5);opacity:0} 70%{transform:scale(1.12)} 100%{transform:scale(1);opacity:1} }
			@keyframes confettiDrop { from{transform:translateY(-20px) rotate(0);opacity:1} to{transform:translateY(60px) rotate(360deg);opacity:0} }
			#invenzaSuccessCard { animation: slideUpIn 0.45s cubic-bezier(0.34,1.4,0.64,1) both; }
			#invenzaSuccessIcon { animation: popIn 0.6s 0.15s cubic-bezier(0.34,1.56,0.64,1) both; }
			.invenza-copy-btn:hover { background:#1d4ed8!important; transform:translateY(-1px); }
			.invenza-dl-btn:hover   { background:#15803d!important; transform:translateY(-1px); box-shadow:0 6px 20px rgba(22,163,74,0.4)!important; }
		</style>
		<div id="invenzaSuccessCard" style="
			background:#fff;border-radius:28px;max-width:480px;width:100%;
			padding:40px 36px 32px;position:relative;
			box-shadow:0 32px 80px rgba(0,0,0,0.35);
			text-align:center;overflow:hidden;
		">
			<!-- Close button -->
			<button onclick="document.getElementById('invenzaSuccessModal').remove()"
				style="position:absolute;top:18px;right:20px;background:none;border:none;
				       font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;padding:4px;"
				aria-label="Close">&#x2715;</button>

			<!-- Animated checkmark -->
			<div id="invenzaSuccessIcon" style="
				width:72px;height:72px;border-radius:50%;
				background:linear-gradient(135deg,#22c55e,#16a34a);
				display:flex;align-items:center;justify-content:center;
				margin:0 auto 20px;
				box-shadow:0 12px 32px rgba(34,197,94,0.45);
			">
				<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<polyline points="20 6 9 17 4 12"/>
				</svg>
			</div>

			<h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;">🎉 Payment Successful!</h2>
			<p style="color:#64748b;font-size:14px;margin:0 0 24px;line-height:1.6;">
				Thank you! Your Pro license is now active.<br>
				A confirmation email has been sent to <strong style="color:#1e40af;">${escHtml(email)}</strong>.
			</p>

			${hasKey ? `
			<!-- License Key Display -->
			<div style="
				background:linear-gradient(135deg,#eff6ff,#dbeafe);
				border:1.5px solid #bfdbfe;border-radius:14px;
				padding:14px 16px;margin-bottom:20px;text-align:left;
			">
				<div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px;">
					Your License Key
				</div>
				<div style="display:flex;align-items:center;gap:10px;">
					<code id="invenzaLicKey" style="
						font-size:13px;font-weight:700;color:#1e3a8a;
						letter-spacing:1px;flex:1;word-break:break-all;
					">${escHtml(licenseKey)}</code>
					<button class="invenza-copy-btn" onclick="copyInvenzaLicKey()" style="
						background:#2563eb;color:#fff;border:none;border-radius:8px;
						padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;
						white-space:nowrap;transition:all 0.2s;
					" id="invenzaCopyBtn">Copy</button>
				</div>
			</div>` : ''}

			${hasDl ? `
			<!-- Download triggered notice -->
			<div style="
				background:linear-gradient(135deg,#f0fdf4,#dcfce7);
				border:1.5px solid #86efac;border-radius:12px;
				padding:12px 16px;margin-bottom:20px;
				display:flex;align-items:center;gap:12px;text-align:left;
			">
				<div style="font-size:22px;flex-shrink:0;">⬇️</div>
				<div>
					<div style="font-size:13px;font-weight:700;color:#15803d;">Download Started Automatically!</div>
					<div style="font-size:12px;color:#166534;margin-top:2px;">
						Your Pro plugin ZIP is downloading. If it didn't start,
						<a href="${escHtml(downloadUrl)}" download style="color:#15803d;font-weight:700;text-decoration:underline;">click here to download manually</a>.
					</div>
				</div>
			</div>` : `
			<div style="
				background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;
				padding:12px 16px;margin-bottom:20px;text-align:left;
			">
				<div style="font-size:13px;font-weight:700;color:#854d0e;">📧 Plugin Delivery via Email</div>
				<div style="font-size:12px;color:#92400e;margin-top:2px;">
					Check your inbox at <strong>${escHtml(email)}</strong> for your license key and plugin download link.
				</div>
			</div>`}

			<!-- Action buttons -->
			<div style="display:flex;flex-direction:column;gap:10px;">
				${hasDl ? `<a href="${escHtml(downloadUrl)}" download class="invenza-dl-btn" style="
					display:flex;align-items:center;justify-content:center;gap:8px;
					background:#16a34a;color:#fff;border-radius:14px;
					padding:14px 20px;font-size:15px;font-weight:700;
					text-decoration:none;box-shadow:0 4px 16px rgba(22,163,74,0.35);
					transition:all 0.2s;
				">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Download Invenza CAPTCHA Pro
				</a>` : ''}
				<button onclick="document.getElementById('invenzaSuccessModal').remove()" style="
					background:#f1f5f9;color:#475569;border:none;border-radius:14px;
					padding:12px 20px;font-size:14px;font-weight:600;cursor:pointer;
					transition:all 0.2s;
				">Close &amp; Continue</button>
			</div>
		</div>`;

	document.body.appendChild(modal);
	modal.addEventListener('click', function(e) {
		if (e.target === modal) modal.remove();
	});
}

function escHtml(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

function copyInvenzaLicKey() {
	const keyEl = document.getElementById('invenzaLicKey');
	const btn   = document.getElementById('invenzaCopyBtn');
	if (!keyEl || !btn) return;
	navigator.clipboard.writeText(keyEl.textContent.trim()).then(() => {
		btn.textContent = '✓ Copied!';
		btn.style.background = '#16a34a';
		setTimeout(() => { btn.textContent = 'Copy'; btn.style.background = '#2563eb'; }, 2500);
	}).catch(() => {
		const range = document.createRange();
		range.selectNodeContents(keyEl);
		window.getSelection().removeAllRanges();
		window.getSelection().addRange(range);
	});
}

// Expose functions to global scope so inline onclick="" attributes work with defer
window.openCheckoutModal     = openCheckoutModal;
window.closeCheckoutModal    = closeCheckoutModal;
window.verifyInvenzaCoupon   = verifyInvenzaCoupon;
window.submitInvenzaCheckout = submitInvenzaCheckout;
window.showPaymentSuccessModal = showPaymentSuccessModal;
window.copyInvenzaLicKey     = copyInvenzaLicKey;

