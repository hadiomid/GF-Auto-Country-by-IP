# GF Auto Country by IP

Automatically pre-selects a visitor's country in any Gravity Forms Drop Down field, based on IP geolocation. Works per-field via a simple toggle in the form editor — no global overrides, no code required after setup.

## Features

- Adds an "Auto-select country based on visitor IP address" checkbox to the Advanced settings tab of every Drop Down field in Gravity Forms
- Detects country via CDN headers (e.g. Cloudflare `CF-IPCountry`) first, falling back to a free geolocation API
- Caches lookups per-IP for 12 hours using WordPress transients to minimize external API calls
- Matches dropdown choices by ISO 3166-1 alpha-2 code (e.g. `NL`) or full country name (e.g. `Netherlands`)
- Does not override a value the visitor has already manually selected

## Requirements

- WordPress 6.6+
- PHP 8.0+
- Gravity Forms 2.10+

## Installation

1. Download or clone this repository into `wp-content/plugins/gf-auto-country-ip`
2. Activate **GF Auto Country by IP** from the WordPress Plugins screen
3. Edit your form and add (or open) a **Drop Down** field for country selection
4. In the field's **Choices** section, click **Bulk Add / Bulk Edit**
5. Open [`gravity-forms-country-choices.txt`](gravity-forms-country-choices.txt) from this repo, copy its full contents, and paste them into the Bulk Add box
6. Click **Save** to apply the 195 pre-formatted country choices (`Label:Value` pairs, e.g. `Netherlands:NL`)
7. Go to the field's **Advanced** tab and check **Auto-select country based on visitor IP address**
8. Save the form

> The bundled `gravity-forms-country-choices.txt` list uses ISO 3166-1 alpha-2 codes as values, which matches exactly what the plugin's IP detection logic looks for — no extra configuration needed.

## How It Works

The plugin hooks into Gravity Forms' `gform_pre_render` filter to inspect field choices before the form renders. If a Drop Down field has the auto-select setting enabled, the plugin detects the visitor's country and marks the matching choice as selected, using the same `isSelected` mechanism Gravity Forms uses internally for pre-population.

Country detection priority:

1. CDN-provided header (e.g. Cloudflare's `CF-IPCountry`) — instant, no external request
2. Cached result from a previous lookup for the same IP (12-hour transient)
3. External geolocation API lookup (ip-api.com, free tier, no key required)

## Important Notes

- **Third-party data sharing**: When no CDN header is available, this plugin sends the visitor's IP address to [ip-api.com](https://ip-api.com) to determine their country. Review their [privacy policy](https://ip-api.com/docs) before deploying on GDPR-covered sites, and disclose this in your site's privacy policy.
- **Accuracy**: IP-based geolocation is approximately 95-98% accurate at the country level and can be inaccurate for VPN, proxy, or mobile carrier NAT traffic. Always leave the dropdown editable so visitors can correct it.
- **Choice matching**: Ensure your Drop Down field's choice values use either full country names or ISO 3166-1 alpha-2 codes consistently. See `iso-country-map.php` for the reference list used for code-to-name fallback.
