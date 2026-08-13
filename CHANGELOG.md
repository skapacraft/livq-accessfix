# Changelog

All notable changes to LivQ AccessFix are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [semantic versioning](https://semver.org/).

## [1.1.0] - 2026-08-13

Two new remediation modules, both covering criteria no module reached before,
and the compatibility work for WordPress 7.1.

### Added
- **Nameless button fix (WCAG 4.1.2).** New HTML output remediation, the
  counterpart of the nameless-link fix for `<button>`. Hamburger toggles, search
  and close controls, carousel arrows and back-to-top buttons are almost always
  an icon font or an inline SVG with no text, which screen readers announce as
  just "button". The label is derived from the purpose words in the button's own
  class or id and in its icon child's class - `menu-toggle`, `fa fa-search`,
  `swiper-button-next`. A button whose purpose cannot be recognised is left
  untouched: a wrong name is worse than none. The vocabulary is filterable via
  `livqacea_button_label_map`. The Scanner already reported these buttons but
  had no module to point at, so they were all marked "Manual fix".
- **Identify Input Purpose (WCAG 1.3.5).** New HTML output remediation adding
  the `autocomplete` attribute to fields that collect information about the user
  - name, email, telephone, address, postcode, country, username, password.
  1.3.5 is a level AA criterion no module covered until now. Names are matched
  after normalisation, so WooCommerce's `billing_`/`shipping_` prefixes and the
  bracket notation of form builders (`fields[last_name]`) resolve to the same
  purpose. Fields that already declare an `autocomplete` value, `off` included,
  are never touched, and passwords are only labelled when the field name says
  which one it is. The map is filterable via `livqacea_autocomplete_map`.
- Scanner: reports fields collecting user information that carry no
  `autocomplete` attribute, using the same derivation as the fixer so it never
  flags a field the module could not have fixed anyway.

- `LICENSE` with the full GPL-2.0 text. The plugin had always declared
  GPL-2.0-or-later in its header, its `readme.txt` and its README, but without
  the file GitHub reported the repository as carrying no licence at all.
- `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, and issue and pull
  request templates.
- Continuous integration: PHPCS against the WordPress Coding Standards, plus a
  syntax check on PHP 7.4 through 8.3, on every push.
- `composer.json` and `phpcs.xml.dist`, so the coding standard is reproducible
  rather than a local habit.
- Dependabot configuration for the development dependencies and the CI actions.

### Fixed
- Scanner counted a button holding an image with meaningful alt text - or an
  SVG carrying its own `aria-label` - as having no accessible name.
  `strip_tags()` cannot see an attribute, so those were false positives.
- The fast-path guards that let a fixer skip a page with nothing to fix were
  case-sensitive, while the regex that follows them is not: on markup written
  with uppercase tags (`<INPUT`, `<IFRAME`, `<A`) every buffer fixer returned
  the page untouched. The iframe src and social domain lookups had the same
  mismatch. All now compare case-insensitively.
- Whitespace and doc-comment issues reported by PHPCS across the `includes/`
  classes. No behaviour changes.
- One Italian comment left in `class-livqacea-main.php`.
- The Gutenberg pre-publish panel read `PluginPrePublishPanel` from
  `wp.editPost`, deprecated since WordPress 6.6 in favour of `wp.editor`, and
  bailed out of the whole script when `wp.editPost` was absent. That is the road
  `@wordpress/nux` reached in WordPress 7.1, where the package became a no-op:
  the panel would have disappeared without an error to explain it. The new slot
  is read first, the old one remains as the fallback for WordPress 6.0 to 6.5,
  and the guard now tests for the panel itself rather than the package around
  it.

### Changed
- Tested up to WordPress 7.1. The pre-publish panel reads block state through
  `useSelect` and renders in the editor sidebar, so the post editor becoming
  unconditionally iframed in 7.1 does not affect it: nothing in this plugin
  reaches into the editor canvas through the global `document`.

## [1.0.1] - 2026-07-29

Maintenance release. No new modules - every entry below is a correctness,
compatibility or safety fix found during a full audit of the 1.0.0 codebase.

### Fixed

**Blocking**
- Fatal error on PHP 7.4: the Accessibility Statement called `str_starts_with()`, a PHP 8.0+ function, while the plugin declares `Requires PHP: 7.4`.
- The output buffer started on `template_redirect`, which core fires *before* branching to feeds, `robots.txt`, favicons and XML sitemaps - the HTML fixers rewrote those payloads and produced invalid feeds. Request-level guards plus a content sniff now skip every non-HTML response.
- `preg_replace_callback()` returns `null` when PCRE hits its backtrack limit on very large pages; the result was cast to string, blanking the entire page. All buffer replacements now fall back to the untouched HTML.
- Nameless-link and external-link fixers ran in the wrong order: the notice span was injected first, so icon and image links opening in a new tab ended up with `(opens in a new tab)` as their *only* accessible name. Order swapped, and the notice is now merged into an existing `aria-label` instead of being appended where it would never be announced.
- The `.screen-reader-text` utility shipped only with the focus-CSS module. Disabling focus CSS on a theme that does not define the class printed the hidden notice as visible text across the site.
- WooCommerce HTML fixes ran through the shared buffer filter and silently stopped working whenever all four buffer modules were disabled.

**Correctness**
- Form fields wrapped in a `<label>` were not detected (only `for=` was), so `aria-label` overrode the visible label - a WCAG 2.5.3 (Label in Name) failure introduced by the plugin itself.
- `aria-label` and `title` injected into self-closing tags produced `<input … / aria-label="…">`, leaving the slash as a bogus attribute.
- WordPress search fields (`name="s"`) were labelled `"S"`. Short cryptic names now map to meaningful labels or are skipped entirely.
- WooCommerce quantity labels matched `\bminus\b` / `\bplus\b` on every page, labelling unrelated theme buttons "Increase quantity". Now scoped to WooCommerce pages with strict class-token boundaries.
- "Settings saved" never appeared: the notice checked for a `settings_page_` screen id on what is a top-level menu.
- Saving the settings while WooCommerce was deactivated permanently disabled the WooCommerce module.
- Heading hierarchy check bailed out on userless saves (scheduled posts, WP-CLI, cron), leaving stale issue meta behind.
- The admin settings CSS used an `#livq-accessfix` ID selector matching no element; it now scopes on the admin body class.
- Background scan could run 15 loopback fetches at 20s each; it now has a per-fetch timeout and a wall-clock budget.
- Output buffer is closed by recorded nesting level instead of flushing whatever sits on top of the stack.
- Relaxed strict type hints on filter callbacks that page builders and mega-menu plugins call with their own objects.

**JavaScript**
- Gutenberg pre-publish panel used the `eaa-developer-guard` text domain, so none of its strings were ever translated.
- `input.qty` handler crashed on WooCommerce "sold individually" products, where the hidden quantity input has a `null` `labels` collection.
- Scanner deleted another template's detail row when re-scanning a row that had no cached result.
- Menu helper swallowed <kbd>Enter</kbd> on parent menu links, making them unreachable by keyboard while mouse users could still open them. `aria-expanded` is now read back from the sub-menu's computed visibility, so CSS-driven menus no longer announce a false state, and the handlers are scoped to menu items instead of every `[aria-haspopup]` element on the page.

### Changed
- Scanner issue status replaces the contradictory "Auto-fixed" badge. Because the scanner fetches the already-remediated live HTML, an issue that still appears has *not* been fixed. Three honest states now: **Manual fix**, **Enable the matching module**, **Module active - still detected**. The results cache key moves to `v2` accordingly.

### Security
- CSV exports (Issues Log and Scanner) escape leading `=`, `+`, `-`, `@` to prevent spreadsheet formula injection.
- Loopback requests use `apply_filters( 'https_local_ssl_verify', false )` - the same hook core uses - instead of disabling TLS verification outright.
- Added the missing capability check on the Scanner admin page.
- Uninstall now removes the statement configuration, the confirmation record and both scanner cache keys.

## [1.0.0] - 2026-06-29

### Added

**Frontend Modules**
- External link labelling - appends a screen-reader notice to every `target="_blank"` link and adds `rel="noopener noreferrer"` automatically. WCAG 2.4.4.
- Skip navigation link - injects a skip link as the first focusable element in `<body>` via `wp_body_open`. WCAG 2.4.1.
- Decorative image alt fix - ensures images with no meaningful alt text receive `alt=""` instead of nothing. Works on `wp_get_attachment_image_attributes` and on Core Image blocks in FSE / block themes via `render_block_core/image`. WCAG 1.1.1.
- High-contrast focus CSS - injects a `focus-visible` rule (3px solid #0056b3 + glow) that overrides theme resets without touching any theme file. WCAG 2.4.11.
- Menu accessibility helper - adds `aria-haspopup` and `aria-expanded` to nav items with sub-menus; Vanilla JS toggles open/close state on click, Enter, Space, and Escape. WCAG 4.1.2.

**PHP Output Buffer**
- Global output buffer on `template_redirect` / `shutdown` intercepts the entire rendered page HTML, fixing `target="_blank"` links regardless of their origin (theme PHP, social share plugins, widget areas, shortcodes).
- Page Builder safety guards: buffer is skipped automatically for Divi (`et_fb`, `et_bfb`), Elementor (`elementor-preview`), Beaver Builder (`fl_builder`), Bricks (`bricks=run`), Oxygen / Breakdance (`ct_builder`, `breakdance_editor`).
- Non-HTML request guards: REST API, AJAX, XML-RPC, WP-Cron responses are excluded from buffering.
- Idempotency: double-injection is prevented by checking for an existing `.screen-reader-text` span before modifying a link.

**HTML Output Remediations (EAA)**
- Nameless link fix - detects icon/image links without accessible text and derives `aria-label` from img title, link title, recognised social domain (Facebook, Instagram, YouTube, LinkedIn, and more), or the capitalised hostname as a fallback. WCAG 2.4.4 / 4.1.2.
- Iframe title fix - adds a `title` attribute to every untitled `<iframe>`, matching src domain to a descriptive title (YouTube, Vimeo, Google Maps, Calendly, iubenda, Spotify, PayPal, Google Forms, and others). WCAG 4.1.2.
- Form input label fix - adds `aria-label` to `<input>`, `<textarea>`, and `<select>` elements without an associated `<label>`, deriving the label from placeholder or name attribute. WCAG 1.3.1 / 3.3.2.

**WooCommerce Accessibility**
- ARIA labels on quantity increment/decrement buttons (`Decrease quantity` / `Increase quantity` with product name).
- Accessible label on "Add to cart" buttons with product name interpolation.
- `aria-label` on product gallery open trigger.
- Live region (`role="status"`, `aria-live="polite"`) for cart update announcements. WCAG 4.1.2 / 4.1.3.

**Content Analysis**
- Heading hierarchy checker - on `save_post`, scans H1–H6 structure and persists detected level-skips as `_livqacea_a11y_issues` post meta (JSON with type, WCAG criterion, timestamp). Shows a dismissible admin notice on the edit screen. WCAG 1.3.1.
- Gutenberg pre-publish panel - real-time checklist in the block editor pre-publish drawer: (A) Core Image blocks missing alt text, (B) links using a raw URL as visible text. No AJAX required. WCAG 1.1.1 / 2.4.4.

**Accessibility Scanner**
- Scans key page types on demand: Homepage, Blog Index, Single Post, Page, Category Archive, Search Results, 404.
- WooCommerce page types auto-detected when active: Shop, Product, Cart, Checkout, My Account, Product Category.
- Each issue scored by severity: Critical, High, Warning.
- Results cached per page type; cleared with a single button.
- Checks include: missing skip link, missing page language (`lang` attribute), multiple H1 elements, heading hierarchy skips, and more.

**Contrast Checker**
- Real-time WCAG 2.1 contrast ratio calculation from hex/rgb foreground and background colour inputs.
- Evaluates three criteria: Normal text AA (4.5:1), Large text AA (3:1), UI components & graphics WCAG 1.4.11 (3:1).
- Live text preview with selected colours.
- Quick-test palette of common colour pairs; click any pair to load it.
- Foreground and background colour pickers with manual hex input.

**Accessibility Issues Log**
- Admin page (Settings → A11y Issues Log) listing all posts with a persisted accessibility issue.
- CSV export (nonce-protected AJAX) with BOM for Excel UTF-8 compatibility - suitable as an EAA compliance audit trail.

**Accessibility Statement Generator**
- Admin page (Settings → A11y Statement) to configure: organisation name, accessibility contact email, contact form URL, date of last evaluation, conformance status (full / partial / non), known limitations.
- Statement text is auto-populated from live plugin data: all active modules and their WCAG criteria are listed automatically (including HTML Output Remediations, WooCommerce enhancements, and the Gutenberg pre-publish panel).
- Confirmation checkbox stores a timestamped declaration record (`livqacea_statement_confirmed` option) with user name, email, date, plugin version, and site URL - legal evidence of operator responsibility under EAA 2025.
- Public statement footer shows plugin name, version, and confirmation attribution.
- Shortcode `[livqacea_accessibility_statement]` embeds the statement on any page.
- One-click "Create Statement Page" helper auto-creates a draft WordPress page with the shortcode pre-inserted.
- Schema.org `itemscope itemtype="WebPage"` markup for search engines.
- Locale-aware enforcement authority section (Italy: AgID / Difensore Civico per il Digitale by default; generic EU fallback for other locales).
- Satisfies the EAA 2025 / Web Accessibility Directive (2016/2102) disclosure requirement.

**Settings & Architecture**
- Settings page (Settings → LivQ AccessFix) with per-module toggles using the native WordPress Settings API - CSRF-safe, no manual nonce handling.
- Skip link target auto-detect via AJAX (server-side homepage fetch with candidate ID matching).
- Singleton bootstrap (`LIVQACEA_Main`) with options loaded once and passed to sub-modules - zero extra DB queries.
- Translation loading via `load_textdomain()` on `init` priority 0 - works on local installs without WP.org auto-download, no-op when WP.org loads community translations from translate.wordpress.org.
- Zero external dependencies: no CDN, no SaaS, no account required.
- Review card (banner) in admin with yellow ★★★★★ rating prompt.
