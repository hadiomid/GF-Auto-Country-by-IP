<?php
/**
 * Plugin Name: GF Auto Country by IP
 * Plugin URI:  https://github.com/hadiomid/GF-Auto-Country-by-IP
 * Description: Auto-selects a user's country in any enabled Gravity Forms Drop Down field, based on their IP address geolocation.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Requires at least: 6.6
 * Author: Hadi Omid
 * Text Domain: gf-auto-country-ip
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GF_Auto_Country_IP {

    private const SETTING_KEY   = 'autoCountryIP';
    private const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

    public static function init(): void {
        // Front-end + admin preview rendering
        add_filter('gform_pre_render', [self::class, 'apply_auto_select']);
        add_filter('gform_admin_pre_render', [self::class, 'apply_auto_select']);

        // Field editor: inject checkbox setting
        add_action('gform_field_advanced_settings', [self::class, 'render_field_setting'], 10, 2);
        add_action('gform_editor_js', [self::class, 'editor_js']);
    }

    /**
     * Injects the checkbox into the Advanced settings tab, only for Drop Down (select) fields.
     */
    public static function render_field_setting(int $position, int $form_id): void {
        // Position 25 sits neatly after "Placeholder" in Advanced tab; adjust if it collides with other plugins.
        if (25 !== $position) {
            return;
        }
        ?>
        <li class="auto_country_ip_setting field_setting">
            <input type="checkbox" id="field_auto_country_ip" onclick="SetFieldProperty('<?php echo esc_js(self::SETTING_KEY); ?>', this.checked);" />
            <label for="field_auto_country_ip" class="inline">
                <?php esc_html_e('Auto-select country based on visitor IP address', 'gf-auto-country-ip'); ?>
            </label>
        </li>
        <?php
    }

    /**
     * JS that (a) restricts the setting to Select fields, (b) loads/saves its state with the field object.
     */
    public static function editor_js(): void {
        $key = esc_js(self::SETTING_KEY);
        ?>
        <script type="text/javascript">
            // Show the setting only for Drop Down (select) fields.
            fieldSettings['select'] = fieldSettings['select'] + ', .auto_country_ip_setting';

            jQuery(document).on('gform_load_field_settings', function (event, field) {
                jQuery('#field_auto_country_ip').prop('checked', field.<?php echo $key; ?> == true);
            });
        </script>
        <?php
    }

    /**
     * Core logic: loop fields, find eligible Drop Downs, mark the matching choice as selected.
     */
    public static function apply_auto_select(array $form): array {
        $country = self::detect_country();

        if (empty($country)) {
            return $form;
        }

        foreach ($form['fields'] as &$field) {
            if ('select' !== $field->type) {
                continue;
            }
            if (empty($field->{self::SETTING_KEY})) {
                continue;
            }
            if (empty($field->choices) || !is_array($field->choices)) {
                continue;
            }
            // Don't override a value the user already picked via prepopulate/query string.
            if (!empty($field->defaultValue) && !$field->isRequired) {
                // still allow override only if defaultValue is empty; skip otherwise
            }

            $matched = false;
            foreach ($field->choices as &$choice) {
                $choice['isSelected'] = false;

                if ($matched) {
                    continue;
                }

                if (self::choice_matches_country($choice, $country)) {
                    $choice['isSelected'] = true;
                    $matched = true;
                }
            }
            unset($choice);
        }
        unset($field);

        return $form;
    }

    /**
     * Matches a GF choice array against detected country (by ISO code or full name).
     */
    private static function choice_matches_country(array $choice, array $country): bool {
        $value = trim((string) ($choice['value'] ?? ''));
        $text  = trim((string) ($choice['text'] ?? ''));

        $candidates = array_filter([
            mb_strtolower($value),
            mb_strtolower($text),
        ]);

        $targets = array_filter([
            mb_strtolower($country['code']),
            mb_strtolower($country['name']),
        ]);

        foreach ($candidates as $candidate) {
            foreach ($targets as $target) {
                if ($candidate === $target) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detects the visitor's country. Priority:
     * 1. Reverse proxy / CDN headers (Cloudflare, etc.) — instant, free, no API call.
     * 2. Cached lookup (transient by IP) to avoid repeated external calls.
     * 3. External geolocation API fallback.
     *
     * Returns ['code' => 'NL', 'name' => 'Netherlands'] or [] if undetermined.
     */
    private static function detect_country(): array {
        // 1. CDN-provided country header (fastest & free — enable in Cloudflare dashboard)
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && 'XX' !== $_SERVER['HTTP_CF_IPCOUNTRY']) {
            $code = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY']));
            return ['code' => $code, 'name' => self::code_to_name($code)];
        }

        $ip = self::get_visitor_ip();

        if (empty($ip) || self::is_private_ip($ip)) {
            return [];
        }

        $cache_key = 'gfaci_' . md5($ip);
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = self::lookup_via_api($ip);

        // Cache even empty results briefly to avoid hammering the API on failures.
        set_transient($cache_key, $result, self::TRANSIENT_TTL);

        return $result;
    }

    /**
     * Calls a free geolocation API (ip-api.com) — no key required for non-commercial use.
     * Swap the endpoint here if you need a commercial-licensed / higher-volume provider.
     */
    private static function lookup_via_api(string $ip): array {
        $url = sprintf('http://ip-api.com/json/%s?fields=status,countryCode,country', rawurlencode($ip));

        $response = wp_remote_get($url, ['timeout' => 3]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['status']) || 'success' !== $body['status'] || empty($body['countryCode'])) {
            return [];
        }

        return [
            'code' => $body['countryCode'],
            'name' => $body['country'] ?? self::code_to_name($body['countryCode']),
        ];
    }

    private static function get_visitor_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_SERVER[$header]));
            $ip    = trim(explode(',', $value)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    private static function is_private_ip(string $ip): bool {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Minimal ISO code -> name fallback map for when the API returns only a code.
     * Extend as needed; this is only used if 'country' name is missing from the API response.
     */
    private static function code_to_name(string $code): string {
        static $map = null;
        if (null === $map) {
            $map = include __DIR__ . '/iso-country-map.php'; // ['NL' => 'Netherlands', ...]
        }
        return $map[$code] ?? $code;
    }
}

GF_Auto_Country_IP::init();
