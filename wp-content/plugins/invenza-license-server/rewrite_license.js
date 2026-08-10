const fs = require('fs');
const path = require('path');

const targetFile = path.resolve('c:/xampp/htdocs/plugin/wp-content/plugins/advanced-captcha-for-all-forms-license-server/includes/AdminPanel.php');
let content = fs.readFileSync(targetFile, 'utf8');

// 1. Replace the container, header, and inject the sidebar start
const newHeader = `<div class="wrap fcac-server-admin-wrap" id="fcac_server_app_root" data-theme="light">
			<div class="fcac-server-header">
				<h1 class="fcac-server-header-title">
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--fcac-server-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
						<polyline points="9 12 11 14 15 10"></polyline>
					</svg>
					<?php esc_html_e( 'License Server Enterprise', 'fcac-license-server' ); ?>
				</h1>
				<div class="fcac-server-header-actions">
					<button class="fcac-server-theme-toggle" id="fcac_server_theme_toggle" type="button" aria-label="Toggle Dark Mode">
						<svg viewBox="0 0 24 24"><path d="M12 21C16.9706 21 21 16.9706 21 12C21 7.02944 16.9706 3 12 3C7.02944 3 3 7.02944 3 12C3 16.9706 7.02944 21 12 21ZM12 19C15.866 19 19 15.866 19 12C19 8.13401 15.866 5 12 5V19Z"/></svg>
					</button>
				</div>
			</div>
			<div class="fcac-server-body">
				<div class="fcac-server-sidebar">
					<a href="?page=fcac-license-server&tab=licenses" class="fcac-server-nav-item <?php echo 'licenses' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'License Keys', 'fcac-license-server' ); ?></a>
					<a href="?page=fcac-license-server&tab=customers" class="fcac-server-nav-item <?php echo 'customers' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Customers', 'fcac-license-server' ); ?></a>
					<a href="?page=fcac-license-server&tab=logs" class="fcac-server-nav-item <?php echo 'logs' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Audit Logs', 'fcac-license-server' ); ?></a>
					<a href="?page=fcac-license-server&tab=payment-settings" class="fcac-server-nav-item <?php echo 'payment-settings' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Payment Settings', 'fcac-license-server' ); ?></a>
				</div>
				<div class="fcac-server-content">`;

// Be very careful about matching
const containerRegex = /<div class="fcac-server-container">\s*<div class="fcac-server-header">\s*<h1>.*?<\/h1>\s*<p>.*?<\/p>\s*<\/div>/g;
content = content.replace(containerRegex, newHeader);

// 2. Remove the old Tab Navigation block (it's now in the sidebar)
const tabsRegex = /<!-- Tab Navigation -->\s*<div class="fcac-tabs-nav">[\s\S]*?<\/div>/g;
content = content.replace(tabsRegex, '');

// 3. Replace Metrics Dashboard Classes
content = content.replace(/fcac-metrics-row/g, 'fcac-server-metrics-row');
content = content.replace(/fcac-metric-card/g, 'fcac-server-metric-card');
content = content.replace(/fcac-metric-title/g, 'fcac-server-metric-title');
content = content.replace(/fcac-metric-value/g, 'fcac-server-metric-value');

// 4. Update the layout of "licenses" tab (remove inline grid styles, use simple stacked cards)
content = content.replace(/<div style="display: grid; grid-template-columns: 1fr 3fr; gap: 20px;">/g, '<div>');

// 5. Replace card classes
content = content.replace(/<div class="fcac-card"/g, '<div class="fcac-server-card"');
content = content.replace(/<h2>/g, '<div class="fcac-server-card-header"><h2 class="fcac-server-card-title">');
content = content.replace(/<\/h2>/g, '</h2></div>');

// 6. Fix Form Inputs
content = content.replace(/class="regular-text"/g, 'class="fcac-server-input"');
content = content.replace(/class="fcac-btn"/g, 'class="fcac-server-btn fcac-server-btn-primary"');
content = content.replace(/<label style=".*?">/g, '<label class="fcac-server-label">');
content = content.replace(/<input type="(email|text|number|date|password)" name="(.*?)" (.*?) style=".*?"(.*?)>/g, '<input type="$1" name="$2" class="fcac-server-input" $3 $4>');
content = content.replace(/<select name="(.*?)" style=".*?">/g, '<select name="$1" class="fcac-server-select">');
content = content.replace(/<p style="margin-bottom: 15px;">|<p>/g, '<div class="fcac-server-form-row">');
content = content.replace(/<\/p>/g, '</div>');
content = content.replace(/<p style="margin-top:20px;">/g, '<div class="fcac-server-form-row" style="margin-top:20px;">');

// 7. Fix Tables
content = content.replace(/<table class="wp-list-table widefat fixed striped table-view-list" style=".*?">/g, '<div class="fcac-server-table-container"><table class="fcac-server-table">');
content = content.replace(/<\/table>/g, '</table></div>');

// 8. Fix Badges & Statuses
content = content.replace(/fcac-status-badge/g, 'fcac-server-badge');
content = content.replace(/fcac-status-active/g, 'fcac-server-badge-active');
content = content.replace(/fcac-status-suspended/g, 'fcac-server-badge-suspended');
content = content.replace(/fcac-status-refunded/g, 'fcac-server-badge-neutral');
content = content.replace(/fcac-badge-type/g, 'fcac-server-badge fcac-server-badge-neutral');
content = content.replace(/fcac-badge-enterprise/g, 'fcac-server-badge-info');

// 9. Fix Links
content = content.replace(/class="fcac-action-link"/g, 'class="fcac-server-action-link"');

// 10. Add closing divs at the bottom for content, body, wrap
content = content.replace(/		<\/div>\s*<\?php\s*}\s*}/g, '			</div>\n		</div>\n		</div>\n		<?php\n	}\n}');

fs.writeFileSync(targetFile, content, 'utf8');
console.log('Successfully rewrote AdminPanel.php structure!');
