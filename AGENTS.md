# Repository Guidelines & Architecture

## Overview
This repository contains WordPress security and license management plugins, primarily focusing on **Invenza CAPTCHA for All Forms** (`invenza-captcha-for-all-forms`), **Invenza CAPTCHA Pro** (`invenza-captcha-pro`), and **Invenza License Server** (`invenza-license-server`).

---

## Coding Standards & Conventions

### 1. Naming & Prefixes
- **Prefixes**: All functions, global variables, option keys, transient keys, hooks, filters, REST routes, and AJAX actions must use the plugin prefix `invcaf_` or `Invcaf\`.
- **Text Domain**: `invenza-captcha-for-all-forms` (must match plugin slug).
- **Namespaces**: Use PSR-4 PSR compliant namespaces rooted under `Invcaf\`.

### 2. WordPress Security & VIP Standards
- **Input Sanitization**: Always sanitize input variables (`$_POST`, `$_GET`, `$_REQUEST`) using appropriate functions (`sanitize_text_field`, `sanitize_key`, `absint`, `sanitize_textarea_field`, `wp_unslash`).
- **Output Escaping**: Every dynamic variable outputted in HTML must be escaped using `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_json_encode()`.
- **Nonce & Capability Checks**: Every AJAX endpoint and admin POST request must verify nonces (`check_ajax_referer` / `check_admin_referer`) and user permissions (`current_user_can('manage_options')`).
- **Database Safety**: All custom SQL queries must use `$wpdb->prepare()`. Table names must be sanitized with `esc_sql()`.

### 3. Architecture & Patterns
- **No Third-Party External Scripts**: Image generation is handled locally using server-side PHP GD (`imagecreatetruecolor`, `imagettftext`). No third-party tracking scripts or external captcha APIs are loaded.
- **Privacy & GDPR Compliance**: Hashing of user identifiers (IP addresses and user agents) using salted HMAC SHA-256 (`hash_hmac('sha256')`) prior to storage.
- **Form Plugin Integrations**: Guard third-party integrations with class/function existence checks before instantiation (e.g. `function_exists('wpcf7')`, `class_exists('GFForms')`).

---

## Workspace Structure

```
new idea/
├── AGENTS.md                                   # Workspace developer guide & Copilot instructions
├── wp-content/plugins/
│   ├── invenza-captcha-for-all-forms/          # Free core WordPress CAPTCHA plugin
│   │   ├── invenza-captcha-for-all-forms.php   # Main entry point
│   │   ├── admin/                              # Admin dashboard and views
│   │   ├── includes/                           # Core logic, integrations, and storage
│   │   ├── frontend/                           # Rendering & shortcode handling
│   │   ├── assets/                             # CSS, JS, and font resources
│   │   └── readme.txt                          # WordPress.org plugin directory readme
│   ├── invenza-captcha-pro/                    # Pro addon plugin
│   └── invenza-license-server/                 # Licensing server integration
```

---

## Git & Submission Workflow
- Do not commit large development binaries or `vendor/` dependency folders into WordPress.org upload packages.
- Always run automated verification and verify ZIP archives before submitting updates.
