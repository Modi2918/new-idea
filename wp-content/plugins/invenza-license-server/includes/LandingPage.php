<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the Invenza Pro Landing Page & Razorpay Checkout Integration.
 */
class LandingPage {

	/**
	 * Register template_redirect hook and auto-create page if missing.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'ensure_landing_page_exists' ) );
		add_action( 'template_redirect', array( __CLASS__, 'load_custom_landing_page' ) );
	}

	/**
	 * Auto-create the 'invenza-pro' WordPress page if it does not already exist.
	 */
	public static function ensure_landing_page_exists() {
		$page = get_page_by_path( 'invenza-pro' );
		if ( ! $page ) {
			wp_insert_post( array(
				'post_title'     => 'Invenza CAPTCHA Pro',
				'post_name'      => 'invenza-pro',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'post_content'   => '<!-- Invenza Pro Landing Page -->',
				'comment_status' => 'closed',
			) );
		}
	}

	/**
	 * Intercept page requests with slug 'invenza-pro' and render custom landing page.
	 */
	public static function load_custom_landing_page() {
		if ( ! is_page( 'invenza-pro' ) && ! is_single( 'invenza-pro' ) && ( ! isset( $_SERVER['REQUEST_URI'] ) || strpos( $_SERVER['REQUEST_URI'], '/invenza-pro' ) === false ) ) {
			return;
		}

		$post_id = get_the_ID();

		// --- 1. HERO SECTION ---
		$hero_badge     = get_post_meta( $post_id, 'hero_badge', true ) ?: 'Invenza CAPTCHA Pro 1.0 is Here';
		$hero_heading   = get_post_meta( $post_id, 'hero_heading', true ) ?: 'Stop Form Spam Cold. <br><span class="bg-gradient-to-r from-brand-600 to-indigo-600 gradient-text">100% Locally.</span>';
		$hero_text      = get_post_meta( $post_id, 'hero_text', true ) ?: 'Replace heavy, privacy-invading third-party scripts with a powerful, GDPR-compliant anti-spam solution that runs directly on your WordPress server. Unlock intelligent adaptive challenges with Pro.';
		$hero_btn1_text = get_post_meta( $post_id, 'hero_btn1_text', true ) ?: 'Upgrade to Pro';
		$hero_btn1_url  = get_post_meta( $post_id, 'hero_btn1_url', true ) ?: '#pricing';
		$hero_btn2_text = get_post_meta( $post_id, 'hero_btn2_text', true ) ?: 'View Free vs Pro';
		$hero_btn2_url  = get_post_meta( $post_id, 'hero_btn2_url', true ) ?: '#compare';

		// --- 2. INTEGRATIONS ---
		$integrations_title    = get_post_meta( $post_id, 'integrations_title', true ) ?: 'Deep Integration with WordPress Form Builders';
		$integrations_list_raw = get_post_meta( $post_id, 'integrations_list', true );
		$integrations          = $integrations_list_raw ? explode( ',', $integrations_list_raw ) : array( 'Contact Form 7', 'WPForms', 'Gravity Forms', 'Forminator', 'Fluent Forms', 'FormCraft' );

		// --- 3. FEATURES SECTION ---
		$features_subtitle = get_post_meta( $post_id, 'features_subtitle', true ) ?: 'Enterprise-Grade Security';
		$features_title    = get_post_meta( $post_id, 'features_title', true ) ?: 'Why upgrade to Invenza Pro?';
		$features_desc     = get_post_meta( $post_id, 'features_desc', true ) ?: 'The free version is great, but Pro gives you adaptive intelligence, better user experience, and unbreakable server-side validation.';
		
		$features_json = get_post_meta( $post_id, 'features_json', true );
		$features      = $features_json ? json_decode( $features_json, true ) : array(
			array(
				'title' => 'Auto Mode (Adaptive Risk)',
				'desc'  => 'An intelligent bot-risk mode that serves easy math challenges to normal users, but scales up to heavily distorted codes if suspicious behavior is detected.',
				'badge' => 'PRO',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
			),
			array(
				'title' => 'Math CAPTCHA',
				'desc'  => 'Require users to solve simple, visually distorted arithmetic formulas. Perfect for balancing security with high conversion rates.',
				'badge' => 'PRO',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>',
			),
			array(
				'title' => 'Text Challenge',
				'desc'  => 'Define custom Q&A pools relevant to your audience (e.g., "Is fire hot or cold?"). Confuses automated generic scraper bots easily.',
				'badge' => 'PRO',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>',
			),
			array(
				'title' => '100% GDPR Compliant',
				'desc'  => 'Runs locally using secure server-side PHP sessions. No external scripts, no tracking cookies, and no data sent to third parties.',
				'badge' => '',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>',
			),
			array(
				'title' => 'Advanced Distortion',
				'desc'  => 'Dynamic difficulty utilizing advanced GD Library techniques: Sine Wave warping, Gaussian blur, pixelation, and edge outlining to defeat OCR bots.',
				'badge' => '',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>',
			),
			array(
				'title' => 'Analytics & Rate Limiting',
				'desc'  => 'Track passed, failed, and blocked attempts. Configure strict rate limits, max retries, and automatic IP lockouts to stop brute force.',
				'badge' => '',
				'icon'  => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
			),
		);

		// --- 4. COMPARE TABLE ---
		$compare_title = get_post_meta( $post_id, 'compare_title', true ) ?: 'Compare Free vs Pro';
		$compare_desc  = get_post_meta( $post_id, 'compare_desc', true ) ?: 'See exactly what you get when you upgrade.';
		
		$compare_json = get_post_meta( $post_id, 'compare_json', true );
		$compare_rows = $compare_json ? json_decode( $compare_json, true ) : array(
			array( 'feature' => 'Standard Image CAPTCHA', 'free' => 'yes', 'pro' => 'yes', 'badge' => '' ),
			array( 'feature' => 'Form Builder Integrations', 'free' => 'yes', 'pro' => 'yes', 'badge' => '' ),
			array( 'feature' => 'Rate Limiting & IP Lockout', 'free' => 'yes', 'pro' => 'yes', 'badge' => '' ),
			array( 'feature' => 'Math CAPTCHA', 'free' => 'no', 'pro' => 'yes', 'badge' => 'Pro' ),
			array( 'feature' => 'Text Challenge (Custom Q&A)', 'free' => 'no', 'pro' => 'yes', 'badge' => 'Pro' ),
			array( 'feature' => 'Auto Mode (Adaptive Bot-Risk)', 'free' => 'no', 'pro' => 'yes', 'badge' => 'Pro' ),
			array( 'feature' => 'Premium Support', 'free' => 'Forum Only', 'pro' => 'Priority Ticket', 'badge' => '' ),
		);

		// --- 5. PRICING ---
		$pricing_title = get_post_meta( $post_id, 'pricing_title', true ) ?: 'Simple, Transparent Pricing';
		$pricing_desc  = get_post_meta( $post_id, 'pricing_desc', true ) ?: 'Choose the plan that fits your website needs. Unlock Pro features in minutes via Enterprise License Key.';
		
		$plans = array(
			'free'       => array(
				'name'     => get_post_meta( $post_id, 'plan_free_name', true ) ?: 'Free Version',
				'desc'     => get_post_meta( $post_id, 'plan_free_desc', true ) ?: 'Perfect for basic form protection.',
				'price'    => get_post_meta( $post_id, 'plan_free_price', true ) ?: '$0',
				'period'   => '/ forever',
				'features' => array( 'Standard Image CAPTCHA', 'All Form Integrations', 'Basic Rate Limiting' ),
				'btn_text' => get_post_meta( $post_id, 'plan_free_btn_text', true ) ?: 'Download Free',
				'btn_type' => 'free',
				'btn_url'  => get_post_meta( $post_id, 'plan_free_btn_url', true ) ?: 'https://wordpress.org/plugins',
				'popular'  => false,
			),
			'monthly'    => array(
				'name'     => 'Monthly Plan',
				'desc'     => 'Flexible month-to-month license.',
				'price'    => '$6',
				'period'   => '/ month',
				'plan_key' => 'monthly',
				'features' => array( '1 Site License', 'Math CAPTCHA Mode', 'Text Challenge Mode', 'Cancel Anytime' ),
				'btn_text' => 'Buy Monthly',
				'btn_type' => 'buy_server',
				'popular'  => false,
			),
			'pro'        => array(
				'name'     => get_post_meta( $post_id, 'plan_pro_name', true ) ?: 'Yearly Plan',
				'desc'     => get_post_meta( $post_id, 'plan_pro_desc', true ) ?: 'Advanced protection for 1 site.',
				'price'    => '$' . ( get_post_meta( $post_id, 'pro_price', true ) ?: '29' ),
				'period'   => '/ year',
				'plan_key' => 'yearly',
				'features' => array( '1 Site License', 'Math CAPTCHA Mode', 'Text Challenge Mode', 'Auto Mode (Adaptive)', 'Priority Support' ),
				'btn_text' => get_post_meta( $post_id, 'plan_pro_btn_text', true ) ?: 'Buy Yearly Plan',
				'btn_type' => 'buy_server',
				'popular'  => true,
			),
			'5-site'     => array(
				'name'     => '5-Site Plan',
				'desc'     => 'Great for small agencies or multi-site setups.',
				'price'    => '$59',
				'period'   => '/ year',
				'plan_key' => '5-site',
				'features' => array( '5 Site Activations', 'All Pro Features Included', 'Central Key Management', 'Priority Support' ),
				'btn_text' => 'Buy 5-Site Plan',
				'btn_type' => 'buy_server',
				'popular'  => false,
			),
			'enterprise' => array(
				'name'     => get_post_meta( $post_id, 'plan_ent_name', true ) ?: 'Unlimited Plan',
				'desc'     => get_post_meta( $post_id, 'plan_ent_desc', true ) ?: 'For high volume agencies and developers.',
				'price'    => '$' . ( get_post_meta( $post_id, 'enterprise_price', true ) ?: '89' ),
				'period'   => '/ year',
				'plan_key' => 'unlimited',
				'features' => array( 'Unlimited Sites', 'All Pro Features Included', 'Master License Key', 'Dedicated Support' ),
				'btn_text' => get_post_meta( $post_id, 'plan_ent_btn_text', true ) ?: 'Buy Unlimited Plan',
				'btn_type' => 'buy_server',
				'popular'  => false,
			),
			'lifetime'   => array(
				'name'     => 'Lifetime Plan',
				'desc'     => 'Pay once, enjoy forever with VIP updates.',
				'price'    => '$149',
				'period'   => '/ one-time',
				'plan_key' => 'lifetime',
				'features' => array( 'Lifetime Updates', 'All Premium Features', 'VIP Lifetime Support', 'Zero Annual Fees' ),
				'btn_text' => 'Buy Lifetime',
				'btn_type' => 'buy_server',
				'popular'  => false,
			),
		);

		// --- 6. FAQ ---
		$faq_title = get_post_meta( $post_id, 'faq_title', true ) ?: 'Frequently Asked Questions';
		$faq_json  = get_post_meta( $post_id, 'faq_json', true );
		$faqs      = $faq_json ? json_decode( $faq_json, true ) : array(
			array( 'q' => 'Does this plugin slow down my site?', 'a' => 'No. Invenza CAPTCHA for All Forms generates images instantly on the server using PHP\'s highly optimized GD library. It avoids loading heavy external JavaScript libraries like traditional reCAPTCHA.' ),
			array( 'q' => 'Is it really GDPR compliant?', 'a' => 'Yes! 100% GDPR compliant. The plugin uses secure server-side PHP sessions to validate codes. No user data, IPs, or cookies are sent to third-party servers.' ),
			array( 'q' => 'I am getting a "Rate Limit" error when testing my form?', 'a' => 'By default, the plugin limits generation to 50 times per minute per IP to prevent spam attacks. You can easily increase this threshold in the Security & Anti-Bot Options section of the settings page.' ),
			array( 'q' => 'How do I enter my License Key?', 'a' => 'After purchasing, go to the <strong>Enterprise License</strong> tab in the plugin settings and paste your license key (starting with <code>FCAC-ENT-</code>) to instantly unlock Math, Text, and Auto modes.' ),
		);
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?> class="scroll-smooth">
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php wp_title( '|', true, 'right' ); ?></title>
			
			<?php wp_head(); ?>

			<script src="https://cdn.tailwindcss.com"></script>
			<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
			<!-- External CSS & JS Assets -->
			<link rel="stylesheet" href="<?php echo esc_url( INVENZA_SERVER_URL . 'assets/css/landingpage.css' ); ?>">

			
			<script>
				tailwind.config = {
					theme: {
						extend: {
							fontFamily: { sans: ['Inter', 'sans-serif'] },
							colors: {
								brand: {
									50: '#eff6ff', 100: '#dbeafe', 500: '#3b82f6',
									600: '#2563eb', 700: '#1d4ed8', 900: '#1e3a8a',
								}
							}
						}
					}
				}
			</script>
		</head>
		<body <?php body_class( 'font-sans text-slate-800 bg-slate-50 antialiased selection:bg-brand-500 selection:text-white relative overflow-x-hidden' ); ?>>

			<!-- AMBIENT BACKGROUND GLOW SPHES -->
			<div class="fixed top-0 left-1/4 w-96 h-96 bg-brand-400/20 rounded-full blur-3xl pointer-events-none animate-pulse-glow -z-10"></div>
			<div class="fixed bottom-1/3 right-1/4 w-[500px] h-[500px] bg-indigo-400/20 rounded-full blur-3xl pointer-events-none animate-pulse-glow -z-10" style="animation-delay: 3s;"></div>

			<!-- NAVBAR -->
			<nav class="fixed w-full bg-white/90 backdrop-blur-md border-b border-slate-200 z-50">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
					<div class="flex justify-between h-20 items-center">
						<div class="flex items-center gap-2">
							<svg class="w-8 h-8 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
							<span class="font-bold text-xl tracking-tight text-slate-900">Invenza<span class="text-brand-600">CAPTCHA</span></span>
						</div>
						<div class="hidden md:flex space-x-8">
							<a href="#features" class="text-slate-600 hover:text-brand-600 font-medium transition">Features</a>
							<a href="#compare" class="text-slate-600 hover:text-brand-600 font-medium transition">Compare</a>
							<a href="#pricing" class="text-slate-600 hover:text-brand-600 font-medium transition">Pricing</a>
							<a href="#faq" class="text-slate-600 hover:text-brand-600 font-medium transition">FAQ</a>
						</div>
						<div class="flex items-center">
							<a href="#pricing" class="bg-brand-600 hover:bg-brand-700 text-white px-6 py-2.5 rounded-lg font-semibold shadow-lg shadow-brand-500/30 transition transform hover:-translate-y-0.5">Get Pro</a>
						</div>
					</div>
				</div>
			</nav>

			<!-- HERO -->
			<section class="pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
					<div class="grid lg:grid-cols-2 gap-12 lg:gap-8 items-center">
						<div class="max-w-2xl reveal reveal-left">
							<?php if ( $hero_badge ) : ?>
							<div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-50 border border-brand-100 text-brand-700 font-medium text-sm mb-6">
								<span class="flex h-2 w-2 relative">
									<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-500 opacity-75"></span>
									<span class="relative inline-flex rounded-full h-2 w-2 bg-brand-600"></span>
								</span>
								<?php echo esc_html( $hero_badge ); ?>
							</div>
							<?php endif; ?>
							<h1 class="text-5xl lg:text-6xl font-extrabold tracking-tight text-slate-900 leading-[1.1] mb-6">
								<?php echo wp_kses_post( $hero_heading ); ?>
							</h1>
							<p class="text-lg text-slate-600 mb-8 leading-relaxed">
								<?php echo esc_html( $hero_text ); ?>
							</p>
							<div class="flex flex-col sm:flex-row gap-4">
								<?php if ( $hero_btn1_text ) : ?>
								<a href="<?php echo esc_url( $hero_btn1_url ); ?>" class="bg-brand-600 hover:bg-brand-700 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-xl shadow-brand-500/30 transition transform hover:-translate-y-1 text-center flex items-center justify-center gap-2">
									<?php echo esc_html( $hero_btn1_text ); ?>
									<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
								</a>
								<?php endif; ?>
								<?php if ( $hero_btn2_text ) : ?>
								<a href="<?php echo esc_url( $hero_btn2_url ); ?>" class="bg-white border border-slate-200 hover:border-slate-300 text-slate-700 px-8 py-4 rounded-xl font-bold text-lg shadow-sm transition text-center hover:-translate-y-1">
									<?php echo esc_html( $hero_btn2_text ); ?>
								</a>
								<?php endif; ?>
							</div>
						</div>
						
						<div class="relative mx-auto w-full max-w-lg lg:max-w-none reveal reveal-right delay-200">
							<div class="absolute inset-0 bg-gradient-to-tr from-brand-100 to-indigo-50 rounded-[2rem] transform rotate-3 scale-105 -z-10 transition-transform duration-700 hover:rotate-6"></div>
							<div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 p-6 animate-float">
								<div class="flex items-center gap-2 mb-6 border-b border-slate-100 pb-4">
									<div class="w-3 h-3 rounded-full bg-red-400"></div>
									<div class="w-3 h-3 rounded-full bg-yellow-400"></div>
									<div class="w-3 h-3 rounded-full bg-green-400"></div>
									<div class="ml-4 text-sm font-medium text-slate-400">Invenza CAPTCHA settings</div>
								</div>
								<div class="space-y-4">
									<div class="p-4 rounded-xl border border-brand-100 bg-brand-50/50">
										<div class="flex justify-between items-center mb-2">
											<h4 class="font-bold text-slate-800">Auto Mode (Adaptive)</h4>
											<span class="bg-brand-600 text-white text-xs px-2 py-1 rounded font-bold">PRO</span>
										</div>
										<p class="text-sm text-slate-600 mb-3">Serves easy math challenges to regular users, but scales up to distorted characters if suspicious behavior is detected.</p>
										<div class="h-2 bg-slate-200 rounded-full overflow-hidden">
											<div class="w-3/4 h-full bg-brand-500"></div>
										</div>
									</div>
									<div class="grid grid-cols-2 gap-4">
										<div class="p-4 rounded-xl border border-slate-200 bg-white">
											<h4 class="font-bold text-slate-800 mb-1">Math CAPTCHA</h4>
											<p class="text-xs text-slate-500 mb-2">Visual arithmetic.</p>
											<div class="text-lg font-mono font-bold tracking-widest text-slate-700 bg-slate-100 p-2 rounded text-center strike">4 + X = 9</div>
										</div>
										<div class="p-4 rounded-xl border border-slate-200 bg-white">
											<h4 class="font-bold text-slate-800 mb-1">Text Challenge</h4>
											<p class="text-xs text-slate-500 mb-2">Custom Q&A pool.</p>
											<div class="text-xs font-medium text-slate-700 bg-slate-100 p-2 rounded text-center">"Is fire hot or cold?"</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</section>

			<!-- INTEGRATIONS LOGO BAR -->
			<div class="border-y border-slate-200 bg-white py-8 reveal reveal-up">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
					<p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-6"><?php echo esc_html( $integrations_title ); ?></p>
					<div class="flex flex-wrap justify-center gap-8 md:gap-16 opacity-60 grayscale hover:grayscale-0 transition duration-500">
						<?php foreach ( $integrations as $integration ) : ?>
						<span class="text-xl font-bold font-sans text-slate-800 hover:text-brand-600 transition-colors"><?php echo esc_html( trim( $integration ) ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- FEATURES GRID -->
			<section id="features" class="py-24 bg-slate-50">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
					<div class="text-center max-w-3xl mx-auto mb-16 reveal reveal-up">
						<h2 class="text-brand-600 font-semibold tracking-wide uppercase mb-3"><?php echo esc_html( $features_subtitle ); ?></h2>
						<h3 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4"><?php echo esc_html( $features_title ); ?></h3>
						<p class="text-lg text-slate-600"><?php echo esc_html( $features_desc ); ?></p>
					</div>

					<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
						<?php
						$delay = 100;
						foreach ( $features as $feature ) :
							?>
						<div class="bg-white rounded-2xl p-8 shadow-sm border border-slate-200 hover:shadow-xl hover:-translate-y-2 transition duration-300 relative overflow-hidden reveal reveal-up delay-<?php echo $delay; ?>">
							<?php if ( ! empty( $feature['badge'] ) ) : ?>
							<div class="absolute top-0 right-0 bg-brand-600 text-white text-xs font-bold px-3 py-1 rounded-bl-lg"><?php echo esc_html( $feature['badge'] ); ?></div>
							<?php endif; ?>
							
							<div class="w-12 h-12 <?php echo ! empty( $feature['badge'] ) ? 'bg-brand-100 text-brand-600' : 'bg-slate-100 text-slate-600'; ?> rounded-xl flex items-center justify-center mb-6">
								<?php echo $feature['icon']; ?>
							</div>
							<h4 class="text-xl font-bold text-slate-900 mb-3"><?php echo esc_html( $feature['title'] ); ?></h4>
							<p class="text-slate-600"><?php echo esc_html( $feature['desc'] ); ?></p>
						</div>
						<?php
						$delay = $delay >= 300 ? 100 : $delay + 100;
						endforeach;
						?>
					</div>
				</div>
			</section>

			<!-- COMPARE TABLE -->
			<section id="compare" class="py-24 bg-white">
				<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
					<div class="text-center mb-12 reveal reveal-up">
						<h2 class="text-3xl font-bold text-slate-900"><?php echo esc_html( $compare_title ); ?></h2>
						<p class="mt-4 text-lg text-slate-600"><?php echo esc_html( $compare_desc ); ?></p>
					</div>

					<div class="compare-table-wrapper shadow-sm reveal reveal-scale delay-200">
						<table class="w-full text-left min-w-[600px]">
							<thead>
								<tr>
									<th class="p-6 bg-slate-50 font-semibold text-slate-900 w-1/2">Feature</th>
									<th class="p-6 bg-slate-50 font-semibold text-slate-900 text-center w-1/4">Free</th>
									<th class="p-6 bg-brand-50 font-bold text-brand-700 text-center w-1/4">Invenza Pro</th>
								</tr>
							</thead>
							<tbody class="bg-white">
								<?php
								foreach ( $compare_rows as $row ) :
									$check_icon     = '<svg class="w-5 h-5 mx-auto text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
									$check_icon_pro = '<svg class="w-5 h-5 mx-auto text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
									$dash_icon       = '<svg class="w-5 h-5 mx-auto text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>';
									?>
								<tr>
									<td class="p-4 pl-6 <?php echo ! empty( $row['badge'] ) ? 'font-semibold text-slate-900 flex items-center gap-2' : 'text-slate-700'; ?>">
										<?php echo esc_html( $row['feature'] ); ?>
										<?php if ( ! empty( $row['badge'] ) ) : ?>
											<span class="bg-brand-100 text-brand-700 text-[10px] px-2 py-0.5 rounded uppercase font-bold"><?php echo esc_html( $row['badge'] ); ?></span>
										<?php endif; ?>
									</td>
									
									<td class="p-4 text-center">
										<?php
										if ( strtolower( $row['free'] ) === 'yes' ) {
											echo $check_icon;
										} elseif ( strtolower( $row['free'] ) === 'no' ) {
											echo $dash_icon;
										} else {
											echo '<span class="text-sm text-slate-500">' . esc_html( $row['free'] ) . '</span>';
										}
										?>
									</td>
									
									<td class="p-4 text-center bg-brand-50/30">
										<?php
										if ( strtolower( $row['pro'] ) === 'yes' ) {
											echo $check_icon_pro;
										} elseif ( strtolower( $row['pro'] ) === 'no' ) {
											echo $dash_icon;
										} else {
											echo '<span class="text-sm font-semibold text-brand-700">' . esc_html( $row['pro'] ) . '</span>';
										}
										?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<!-- PRICING SECTION -->
			<section id="pricing" class="py-24 bg-slate-900 text-white relative">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
					<div class="text-center max-w-3xl mx-auto mb-16 reveal reveal-up">
						<h2 class="text-3xl md:text-5xl font-bold mb-4"><?php echo esc_html( $pricing_title ); ?></h2>
						<p class="text-xl text-slate-300"><?php echo esc_html( $pricing_desc ); ?></p>
					</div>

					<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-6xl mx-auto items-stretch">
						<?php
						foreach ( $plans as $plan_key => $plan ) :
							$is_popular = $plan['popular'];
							?>
						<div class="<?php echo $is_popular ? 'bg-gradient-to-b from-brand-600 to-indigo-700 rounded-2xl p-8 border border-brand-500 shadow-2xl relative' : 'bg-slate-800 rounded-2xl p-8 border border-slate-700'; ?>">
							
							<?php if ( $is_popular ) : ?>
							<div class="absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-gradient-to-r from-amber-400 to-orange-500 text-white px-4 py-1 rounded-full text-xs font-bold uppercase tracking-wider shadow-lg">Most Popular</div>
							<?php endif; ?>
							
							<h3 class="text-xl font-bold text-white mb-2"><?php echo esc_html( $plan['name'] ); ?></h3>
							<p class="text-slate-300 text-sm mb-6"><?php echo esc_html( $plan['desc'] ); ?></p>
							<div class="mb-6">
								<span class="text-4xl font-extrabold"><?php echo esc_html( $plan['price'] ); ?></span>
								<span class="text-slate-400"><?php echo esc_html( $plan['period'] ); ?></span>
							</div>
							
							<ul class="space-y-4 mb-8 text-sm text-slate-200">
								<?php foreach ( $plan['features'] as $plan_feature ) : ?>
								<li class="flex items-center gap-3">
									<svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> 
									<?php echo esc_html( $plan_feature ); ?>
								</li>
								<?php endforeach; ?>
							</ul>
							
							<?php if ( 'free' === $plan['btn_type'] ) : ?>
								<a href="<?php echo esc_url( $plan['btn_url'] ); ?>" target="_blank" class="block w-full py-3 px-4 text-center rounded-xl font-bold btn-free transition">
									<?php echo esc_html( $plan['btn_text'] ); ?>
								</a>
							<?php else : ?>
								<button onclick="openCheckoutModal('<?php echo esc_js( $plan['plan_key'] ); ?>', '<?php echo esc_js( $plan['name'] ); ?>', '<?php echo esc_js( $plan['price'] ); ?>')" class="block w-full py-3 px-4 text-center rounded-xl font-bold transition shadow-lg <?php echo $is_popular ? 'btn-popular' : 'btn-standard'; ?>">
									<?php echo esc_html( $plan['btn_text'] ); ?>
								</button>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<!-- FAQ SECTION -->
			<section id="faq" class="py-24 bg-slate-50">
				<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
					<div class="text-center mb-16 reveal reveal-up">
						<h2 class="text-3xl font-bold text-slate-900"><?php echo esc_html( $faq_title ); ?></h2>
					</div>
					
					<div class="space-y-6">
						<?php
						$delay = 100;
						foreach ( $faqs as $faq ) :
							?>
						<div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm reveal reveal-up delay-<?php echo $delay; ?> hover:shadow-md transition">
							<h4 class="text-lg font-bold text-slate-900 mb-2"><?php echo esc_html( $faq['q'] ); ?></h4>
							<p class="text-slate-600"><?php echo wp_kses_post( $faq['a'] ); ?></p>
						</div>
						<?php
						$delay += 100;
						endforeach;
						?>
					</div>
				</div>
			</section>

			<!-- BEAUTIFUL MODERN CHECKOUT MODAL FORM -->
			<div id="invenzaCheckoutModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[99999] hidden flex items-center justify-center p-4 transition-all duration-300">
				<div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl relative border border-slate-100">
					<button onclick="closeCheckoutModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 text-2xl font-bold leading-none">&times;</button>
					
					<div class="flex items-center gap-3 mb-6">
						<div class="w-10 h-10 rounded-xl bg-brand-100 text-brand-600 flex items-center justify-center font-bold">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
						</div>
						<div>
							<h3 id="modalPlanTitle" class="text-xl font-bold text-slate-900">Pro License</h3>
							<p id="modalPlanPrice" class="text-sm font-semibold text-brand-600">$29 / year</p>
						</div>
					</div>

					<form id="invenzaModalForm" onsubmit="submitInvenzaCheckout(event)" class="space-y-4">
						<input type="hidden" id="modalPlanKey" value="" />
						
						<div>
							<label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Full Name</label>
							<input type="text" id="modalCustomerName" required placeholder="John Doe" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 outline-none transition text-slate-900 font-medium" />
						</div>

						<div>
							<label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Email Address (Key Delivered Here)</label>
							<input type="email" id="modalCustomerEmail" required placeholder="john@example.com" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 outline-none transition text-slate-900 font-medium" />
						</div>

						<div>
							<label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Have a Coupon Code?</label>
							<div class="flex gap-2">
								<input type="text" id="modalCouponCode" placeholder="e.g. SAVE20" class="uppercase w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 outline-none transition text-slate-900 font-medium tracking-wider" />
								<button type="button" id="modalApplyCouponBtn" onclick="verifyInvenzaCoupon()" class="px-5 py-3 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-xs uppercase tracking-wider transition">Apply</button>
							</div>
						</div>

							<div id="modalCouponSuccess" class="hidden" style="
							background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
							border: 1.5px solid #86efac;
							border-radius: 14px;
							padding: 12px 16px;
							animation: couponPopIn 0.4s cubic-bezier(0.34,1.56,0.64,1) both;
						">
							<div style="display:flex;align-items:center;gap:10px;">
								<div id="modalCouponCheckIcon" style="
									width:32px;height:32px;min-width:32px;
									background:#22c55e;border-radius:50%;
									display:flex;align-items:center;justify-content:center;
									box-shadow:0 4px 12px rgba(34,197,94,0.35);
									animation: checkBounce 0.5s 0.15s cubic-bezier(0.34,1.56,0.64,1) both;
								">
									<svg width="16" height="16" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
								</div>
								<div style="flex:1;min-width:0;">
									<div id="modalCouponSuccessTitle" style="font-size:13px;font-weight:700;color:#15803d;line-height:1.3;">Coupon Applied!</div>
									<div id="modalCouponSuccessMsg" style="font-size:11px;color:#166534;margin-top:2px;line-height:1.4;"></div>
								</div>
								<div id="modalCouponBadge" style="
									background:#22c55e;color:white;
									font-size:12px;font-weight:800;
									padding:4px 10px;border-radius:99px;
									white-space:nowrap;letter-spacing:0.5px;
									box-shadow:0 2px 8px rgba(34,197,94,0.4);
								"></div>
							</div>
						</div>
						<div id="modalFormError" class="hidden text-xs text-red-600 bg-red-50 p-3 rounded-xl border border-red-100 font-medium"></div>

						<button type="submit" id="modalSubmitBtn" class="w-full py-4 bg-brand-600 hover:bg-brand-700 text-white rounded-xl font-bold text-base shadow-xl shadow-brand-500/30 transition transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
							<span>Proceed to Payment</span>
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
						</button>
					</form>
				</div>
			</div>

			<!-- FOOTER -->
			<footer class="bg-white border-t border-slate-200 py-12 reveal reveal-up">
				<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-6">
					<div class="flex items-center gap-2">
						<svg class="w-6 h-6 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
						<span class="font-bold text-lg tracking-tight text-slate-900"><?php bloginfo( 'name' ); ?></span>
					</div>
					<p class="text-slate-500 text-sm">&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. Licensed under GPLv2.</p>
					<div class="flex gap-4">
						<a href="#" class="text-sm text-slate-500 hover:text-brand-600">Terms</a>
						<a href="#" class="text-sm text-slate-500 hover:text-brand-600">Privacy</a>
						<a href="#" class="text-sm text-slate-500 hover:text-brand-600">Contact Support</a>
					</div>
				</div>
			</footer>

			<?php wp_footer(); ?>
			<!-- Landing Page Scripts: loaded here so DOM is ready and functions are global -->
			<script src="<?php echo esc_url( INVENZA_SERVER_URL . 'assets/js/landingpage.js' ); ?>"></script>
		</body>
		</html>
		<?php
		exit;
	}
}
