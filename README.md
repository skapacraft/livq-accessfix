# LivQ AccessFix – EAA & A11y AutoFix

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/livq-accessfix?label=version)](https://wordpress.org/plugins/livq-accessfix/)
[![WordPress](https://img.shields.io/wordpress/plugin/wp-version/livq-accessfix)](https://wordpress.org/plugins/livq-accessfix/)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Server-side WCAG 2.2 AA & European Accessibility Act (EAA) fixes for WordPress. Skip links, alt text, ARIA menus, heading checks, an issues log, and an Accessibility Statement generator. Zero configuration.

> **No longer maintained.** 1.1.1 is the last release, and the plugin has been
> closed on WordPress.org. Sites that have it installed keep working as they do
> today, but there will be no further updates, compatibility fixes or security
> fixes. If your site relies on it for its accessibility obligations, plan a
> replacement. The source stays here, under the same licence, for anyone who
> wants to carry it on.

## Why server-side

JS-based accessibility overlays patch the DOM after the browser renders it. The original HTML (the one Google indexes, axe-core audits, and screen readers without JS see) remains unmodified. LivQ AccessFix intercepts the full rendered HTML via PHP output buffering and fixes the source itself, before it reaches the browser.

## What it fixes

**Frontend**
- External link labelling (`target="_blank"` + `rel="noopener noreferrer"`) (WCAG 2.4.4)
- Skip navigation link, auto-detectable target (WCAG 2.4.1)
- Decorative image alt fix, including Full Site Editor / block themes (WCAG 1.1.1)
- High-contrast focus-visible CSS (WCAG 2.4.11)
- Menu accessibility helper (ARIA + keyboard toggle) (WCAG 4.1.2)

**HTML output remediations (EAA)**
- Nameless link fix (icon/image links without accessible text) (WCAG 2.4.4 / 4.1.2)
- Iframe title fix, matched from src domain (WCAG 4.1.2)
- Form input label fix (WCAG 1.3.1 / 3.3.2)

**Content analysis**
- Heading hierarchy checker on save (WCAG 1.3.1)
- Gutenberg pre-publish accessibility checklist (WCAG 1.1.1 / 2.4.4)

**Compliance tools**
- Accessibility Scanner: severity-scored scan across key page types, incl. WooCommerce
- Accessibility Issues Log with CSV export
- Contrast Checker: real-time WCAG 2.1 analysis
- Accessibility Statement Generator: legally-compliant, auto-populated, shortcode `[livqacea_accessibility_statement]`

**WooCommerce**
- ARIA labels on quantity controls, gallery triggers, and cart live regions (WCAG 4.1.2 / 4.1.3)

Every module can be independently enabled or disabled from **Settings → LivQ AccessFix**. Page builder editors (Divi, Elementor, Beaver Builder, Bricks, Oxygen, Breakdance) are automatically detected and excluded from output buffering.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

Install directly from your WordPress admin: **Plugins → Add New → search "LivQ AccessFix"**, or download from [WordPress.org](https://wordpress.org/plugins/livq-accessfix/).

```bash
wp plugin install livq-accessfix --activate
```

No configuration required: all modules are enabled by default.

## Privacy

This plugin does not collect, store, or transmit any personal data. No third-party services, no CDN, no external API calls.

## Support

None: the project is no longer maintained, and issues and the support forum are
no longer answered.

## Reporting a security problem

Not in a public issue: use the **Security** tab, then **Report a vulnerability**.
The project is no longer maintained, so there will be no fix: a confirmed
vulnerability is published as an advisory, so that sites still running the
plugin know to remove it. Details in [SECURITY.md](SECURITY.md).

## Contributing

How to report, propose and build is in [CONTRIBUTING.md](CONTRIBUTING.md).
Development runs on PHPCS with the WordPress Coding Standards:

```bash
composer install
composer phpcs
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history.

## Author

Developed by SkapaCraft, now retired.

## Licence

Copyright (C) 2026 SkapaCraft. GPL-2.0-or-later, see [LICENSE](LICENSE).

WordPress requires the GPL, and the choice suits a plugin that sits in the
render path of somebody else's site: whatever it does to their HTML, they can
read it and change it.
