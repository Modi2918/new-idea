=== Invenza CAPTCHA for All Forms ===
Contributors: modi2918
Tags: captcha, spam, security, forms, contact-form
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://pages.razorpay.com/pl_T8YBejEfQgvpif/view

A highly secure, GDPR-compliant local CAPTCHA plugin. Protects forms using customizable image CAPTCHA challenges.

== Description ==

**Invenza CAPTCHA for All Forms** is a powerful, locally-hosted anti-spam solution that completely replaces the need for third-party tracking like reCAPTCHA. Generate highly secure, GDPR-compliant CAPTCHAs directly on your server.

It is built with deep integration for popular WordPress form builders, ensuring zero spam submissions while keeping your users' data private.

### Fully Compatible With:
* Contact Form 7
* WPForms
* Forminator
* Gravity Forms
* Fluent Forms
* FormCraft

### Core Features
* **Standard Image CAPTCHA:** Generate secure character codes to block automated spam bots.
* **Customizable Character Pool:** Choose uppercase letters, lowercase letters, or numbers.
* **Noise Controls:** Toggle background dots and line noise.
* **Advanced Security Policies:** Configure strict rate limits, max retry attempts, and automatic IP lockouts to prevent brute-force attacks.
* **Event Logging Analytics:** Track passed, failed, and blocked CAPTCHA attempts directly from your dashboard.

### Why Choose Invenza CAPTCHA?
Unlike Google reCAPTCHA or Cloudflare Turnstile, this plugin runs 100% locally on your WordPress server. No external scripts are loaded, no user tracking occurs, and it is fully GDPR-compliant out of the box.

== Installation ==

1. Upload the `invenza-captcha-for-all-forms` folder to the `/wp-content/plugins/` directory, or install directly via the WordPress Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the `Invenza CAPTCHA` menu in your WordPress admin sidebar.
4. Configure your preferred settings.
5. Scroll down to enable CAPTCHA for your specific form builder (e.g., WPForms, Contact Form 7).

== Frequently Asked Questions ==

= Does this plugin slow down my site? =
No. Invenza CAPTCHA for All Forms generates CAPTCHA images instantly on the server using PHP's highly optimized GD library. It does not load heavy external JavaScript libraries like traditional reCAPTCHA.

= Is it GDPR compliant? =
Yes! 100% GDPR compliant. The plugin uses secure server-side PHP sessions to validate codes. No user data, IPs, or cookies are sent to third-party servers.

= I am getting a "Rate Limit" error when testing my form? =
By default, the plugin limits CAPTCHA generation to 50 times per minute per IP to prevent spam attacks. You can increase this threshold in the **Security & Anti-Bot Options** section of the settings page.

== Changelog ==

= 1.0.0 =
* Initial Release.
* Added support for standard Image CAPTCHA.
* Added direct integrations for FormCraft, CF7, WPForms, Gravity Forms, Fluent Forms, and Forminator.
* Introduced Enterprise UI redesign.

== Screenshots ==

1. The main settings dashboard for configuration.
2. Compatibility dashboard showing logs and protection stats.

== Upgrade Notice ==

= 1.0.0 =
Initial production release of Invenza CAPTCHA for All Forms.

