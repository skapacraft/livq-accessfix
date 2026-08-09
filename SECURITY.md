# Security policy

## What this plugin is, and why that shapes the threat model

LivQ AccessFix intercepts the fully rendered HTML of a WordPress site through
PHP output buffering and rewrites it before it reaches the browser. That gives
it a position most plugins do not have: it sees, and can modify, every byte of
every public page.

The whole design rests on one promise:

> The plugin only adds accessibility attributes to markup the site already
> produced. It never injects content of its own into a page, and it never sends
> anything anywhere.

Everything below follows from that.

## What counts as a vulnerability here

- **Injection through the output buffer.** Anything that lets attacker
  controlled input (a post title, a filename, an option value, a query string)
  reach the rewritten HTML unescaped. The rewriter is the highest-value target
  in this codebase: an escaping mistake there is a stored XSS on every page of
  the site.
- **Privilege escalation.** Every admin action requires a valid nonce and
  `manage_options`. A handler that omits either, or a capability check that can
  be bypassed, belongs here.
- **Data leaving the site.** The plugin makes no outbound request. Any network
  call, DNS lookup or telemetry is a vulnerability, not a feature.
- **Blanking a page.** The rewriter falls back to the original HTML whenever a
  regular expression fails. An input that defeats that fallback and returns an
  empty or truncated page takes the site down, so it is treated as a security
  problem rather than a bug.
- **Scanner abuse.** The Accessibility Scanner fetches the site's own URLs. A
  path that makes it fetch an arbitrary host, or follow a redirect off-site,
  is a server-side request forgery and belongs here.
- **Statement generator injection.** The Accessibility Statement is rendered
  from stored options through a shortcode. Anything that turns a stored option
  into executed script belongs here.

A malformed page producing a PHP notice is a bug worth an issue. It is not a
vulnerability on its own.

## Out of scope

- WordPress core, the active theme, and other plugins. What is in scope is our
  interaction with them, for example a rewrite that corrupts markup a theme
  produced.
- Anything requiring an attacker who is already an administrator. At that point
  the site is theirs without going through this plugin.
- Accessibility findings. A missing WCAG fix is a feature request, not a
  security report.

## How to report

**Please do not open a public issue for a security problem.** A public report
tells everyone how to exploit it before there is a fix, and this plugin runs on
sites the reporter does not own.

Use GitHub's private reporting: the **Security** tab of this repository, then
**Report a vulnerability**. It opens a channel visible only to the maintainer.

Useful in a report, roughly in order of usefulness:

- what an attacker gains, in one sentence
- the steps to reproduce, and the markup that triggers it
- the plugin version, the WordPress version and the PHP version
- the theme and any page builder in use, since the rewriter treats them
  differently
- whether it needs the user to do something, and what

## What happens then

This is maintained by one person, so no response time is promised that could
not be kept. What is promised instead:

- a report is acknowledged when it is read, even if the answer is that it needs
  time
- a confirmed finding is fixed before anything else, and released to
  WordPress.org as a patch version
- the fix says what was wrong and since which version, in the changelog
- credit goes to whoever reported it, unless they prefer otherwise

## Supported versions

Only the latest version published on
[WordPress.org](https://wordpress.org/plugins/livq-accessfix/) is supported.
There is no back-porting to older ones: update before reporting.
