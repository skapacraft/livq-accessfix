# LivQ AccessFix – EAA & A11y AutoFix

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/livq-accessfix?label=version)](https://wordpress.org/plugins/livq-accessfix/)
[![WordPress](https://img.shields.io/wordpress/plugin/wp-version/livq-accessfix)](https://wordpress.org/plugins/livq-accessfix/)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Server-side WCAG 2.2 AA & European Accessibility Act (EAA) fixes for WordPress. Skip links, alt text, ARIA menus, heading checks, an issues log, and an Accessibility Statement generator. Zero configuration.

**[Get it on WordPress.org →](https://wordpress.org/plugins/livq-accessfix/)** · **[skapacraft.com/tools/plugins/livq-accessfix →](https://skapacraft.com/tools/plugins/livq-accessfix/)**

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

- **Bug reports / feature requests:** [GitHub Issues](https://github.com/skapacraft/livq-accessfix/issues)
- **Support forum:** [wordpress.org/support/plugin/livq-accessfix](https://wordpress.org/support/plugin/livq-accessfix/)
- **Translations:** handled via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/livq-accessfix/), contributions welcome.

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [LivQ](https://livq.it) and [SkapaCraft](https://skapacraft.com): premium WordPress plugins built for performance.
