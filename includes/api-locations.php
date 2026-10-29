<?php
/** Server-side API import, review and durable event history. */
if (! defined('ABSPATH')) { exit; }

define('HTR_API_CONFIG', 'hometown_announcement_api_config');
define('HTR_API_RULES', 'hometown_announcement_api_rules');
define('HTR_API_SYNC', 'hometown_announcement_api_sync');
define('HTR_API_CRON', 'hometown_announcement_api_hourly');

function hometown_api_table() {
    global $wpdb;
    return $wpdb->prefix . 'hometown_announcement_events';
}

function hometown_api_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = hometown_api_table();
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        rule_key varchar(64) NOT NULL,
        event_type varchar(24) NOT NULL,
        status varchar(24) NOT NULL,
        location varchar(255) NOT NULL,
        match_type varchar(12) NOT NULL,
        message text NOT NULL,
        schedule text NOT NULL,
        first_detected datetime NOT NULL,
        last_seen datetime NOT NULL,
        recorded_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY rule_key (rule_key),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('hometown_announcement_api_schema', '1', false);
}

register_activation_hook(dirname(__DIR__) . '/hometown-location-announcement-bar.php', 'hometown_api_install');
register_deactivation_hook(dirname(__DIR__) . '/hometown-location-announcement-bar.php', function () {
    wp_clear_scheduled_hook(HTR_API_CRON);
});
add_action('admin_init', function () {
    if (get_option('hometown_announcement_api_schema') !== '1') { hometown_api_install(); }
});
add_action('init', function () {
    $config = get_option(HTR_API_CONFIG, []);
    if (! empty($config['username']) && ! empty($config['password']) && ! wp_next_scheduled(HTR_API_CRON)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', HTR_API_CRON);
    }
});
add_action(HTR_API_CRON, 'hometown_api_fetch');

/** Atomic option lock shared by sync and review to avoid lost approvals. */
function hometown_api_lock() {
    $until = (int) get_option('hometown_announcement_api_lock', 0);
    if ($until && $until < time()) { delete_option('hometown_announcement_api_lock'); }
    return add_option('hometown_announcement_api_lock', time() + 120, '', false);
}
function hometown_api_unlock() { delete_option('hometown_announcement_api_lock'); }

function hometown_api_save_config($input) {
    foreach (['url', 'username', 'password'] as $field) {
        if (! isset($input[$field]) || ! is_string($input[$field])) { return false; }
    }
    $url = esc_url_raw($input['url'], ['https']);
    $username = sanitize_text_field($input['username']);
    $parts = wp_parse_url($url);
    if (strpos($url, 'https://') !== 0 || ! wp_http_validate_url($url) || isset($parts['user']) || isset($parts['pass']) || strpos($username, ':') !== false) { return false; }
    $old = get_option(HTR_API_CONFIG, []);
    update_option(HTR_API_CONFIG, ['url' => $url, 'username' => $username, 'password' => $input['password'] !== '' ? $input['password'] : ($old['password'] ?? '')], false);
    return true;
}

/** Isolated mapping boundary for the assumed API schema. */
function hometown_api_map_location($input) {
    if (! is_array($input)) { return new WP_Error('invalid', 'Location must be an object.'); }
    $use_global = ! isset($input['match_type']) && array_key_exists('name', $input);
    if ($use_global) {
        if (! is_string($input['name']) || trim($input['name']) === '') { return new WP_Error('invalid', 'Location name is required.'); }
        $global = hometown_announcement_get_settings()['default'];
        $input = array_merge($input, [
            'match_type' => 'city', 'city' => $input['name'], 'state' => 'TX',
            'message' => $global['message'] ?? '', 'cta_label' => '', 'cta_url' => '',
            'schedule_type' => 'always', 'start_date' => '', 'end_date' => '',
        ]);
    }
    foreach (['match_type', 'state', 'city', 'url_slug', 'message', 'cta_label', 'cta_url', 'schedule_type', 'start_date', 'end_date', 'id'] as $field) {
        if (isset($input[$field]) && ! is_scalar($input[$field])) { return new WP_Error('invalid', 'Invalid location field.'); }
    }
    $type = $input['match_type'] ?? '';
    if (! in_array($type, ['city', 'state', 'url'], true)) { return new WP_Error('invalid', 'Invalid match type.'); }
    $input['enabled'] = 1;
    $clean = hometown_announcement_sanitize(['locations' => [$input]]);
    $rule = $clean['locations'][0] ?? null;
    if (! $rule || $rule['message'] === '') { return new WP_Error('invalid', 'Location target and message are required.'); }
    if ($type !== 'url' && ! isset(hometown_get_states()[$rule['state']])) { return new WP_Error('invalid', 'A valid state code is required.'); }
    foreach (['start_date', 'end_date'] as $field) {
        if (! empty($input[$field]) && $rule[$field] === '') { return new WP_Error('invalid', 'Invalid schedule date.'); }
    }
    if (! empty($input['schedule_type']) && ! in_array($input['schedule_type'], ['always', 'dates'], true)) {
        return new WP_Error('invalid', 'Invalid schedule type.');
    }
    if ($rule['schedule_type'] === 'dates' && $rule['start_date'] && $rule['end_date'] && $rule['start_date'] > $rule['end_date']) {
        return new WP_Error('invalid', 'Schedule end must follow start.');
    }
    if ($use_global) { $rule['use_global_content'] = true; }
    return $rule;
}

function hometown_api_identity($input, $rule) {
    $identity = isset($input['id']) && (string) $input['id'] !== ''
        ? 'id:' . (string) $input['id']
        : 'target:' . $rule['match_type'] . ':' . ($rule['match_type'] === 'url' ? $rule['url_slug'] : $rule['state'] . ':' . ($rule['match_type'] === 'city' ? strtolower(trim($rule['city'])) : ''));
    return hash('sha256', $identity);
}

function hometown_api_expires_at($entry) {
    return $entry['expires_at'] ?? (strtotime($entry['first_detected'] . ' UTC') + 14 * 86400);
}

function hometown_api_is_expired($entry) {
    return strtotime(current_time('mysql', true) . ' UTC') >= hometown_api_expires_at($entry);
}

function hometown_api_expire_rules() {
    $entries = get_option(HTR_API_RULES, []);
    $changed = false;
    foreach ($entries as $key => &$entry) {
        if ($entry['status'] !== 'expired' && hometown_api_is_expired($entry)) {
            $entry['status'] = 'expired';
            $entry['revision'] = hash('sha256', $entry['revision'] . ':expired');
            hometown_api_record($key, $entry, 'expired');
            $changed = true;
        }
    }
    unset($entry);
    if ($changed) { update_option(HTR_API_RULES, $entries, false); }
}

function hometown_api_record($key, $entry, $event) {
    global $wpdb;
    $rule = $entry['rule'];
    $wpdb->insert(hometown_api_table(), [
        'rule_key' => $key, 'event_type' => $event, 'status' => $entry['status'],
        'location' => hometown_get_rule_title($rule), 'match_type' => $rule['match_type'],
        'message' => $rule['message'],
        'schedule' => ($rule['schedule_type'] === 'dates' ? ($rule['start_date'] ?: 'Open') . ' to ' . ($rule['end_date'] ?: 'Open') : 'Always active') . ' | API expires ' . gmdate('Y-m-d H:i:s', hometown_api_expires_at($entry)) . ' UTC',
        'first_detected' => $entry['first_detected'], 'last_seen' => $entry['last_seen'],
        'recorded_at' => current_time('mysql', true),
    ]);
}

/** Validate the whole snapshot before modifying rules; partial malformed data cannot deactivate rules. */
function hometown_api_import($data) {
    if (is_array($data) && array_values($data) === $data) { $data = ['ok' => true, 'locations' => $data]; }
    if (! is_array($data) || ($data['ok'] ?? null) !== true || ! isset($data['locations']) || ! is_array($data['locations']) || array_values($data['locations']) !== $data['locations']) {
        return new WP_Error('snapshot', 'API returned an invalid snapshot.');
    }
    $incoming = [];
    foreach ($data['locations'] as $input) {
        $rule = hometown_api_map_location($input);
        if (is_wp_error($rule)) { return $rule; }
        $key = hometown_api_identity($input, $rule);
        $source_rule = $rule;
        // Global copy changes must not reset local decisions or create new source events.
        if (! empty($source_rule['use_global_content'])) { $source_rule['message'] = ''; }
        $fingerprint = hash('sha256', wp_json_encode($source_rule));
        if (! isset($incoming[$key])) { $incoming[$key] = ['rule' => $rule, 'variants' => []]; }
        $incoming[$key]['variants'][$fingerprint] = $rule;
    }
    $entries = get_option(HTR_API_RULES, []);
    $now = current_time('mysql', true);
    foreach ($incoming as $key => $item) {
        ksort($item['variants']);
        $source_variants = $item['variants'];
        foreach ($source_variants as &$variant) {
            if (! empty($variant['use_global_content'])) { $variant['message'] = ''; }
        }
        unset($variant);
        $fingerprint = hash('sha256', wp_json_encode($source_variants));
        $conflict = count($item['variants']) > 1;
        $old = $entries[$key] ?? null;
        $changed = ! $old || $old['fingerprint'] !== $fingerprint;
        $returned = $old && empty($old['present']);
        $entry = $old ?: ['first_detected' => $now];
        if ($changed || $returned) {
            $entry['rule'] = reset($item['variants']);
            $entry['status'] = $conflict ? 'conflict' : 'approved';
        }
        // Existing pending imports adopt automatic approval on their next sync.
        if (! $conflict && $entry['status'] === 'pending') { $entry['status'] = 'approved'; }
        $entry['expires_at'] = hometown_api_expires_at($entry);
        if (hometown_api_is_expired($entry)) { $entry['status'] = 'expired'; }
        $entry['source'] = reset($item['variants']);
        $entry['variants'] = array_values($item['variants']);
        $entry['fingerprint'] = $fingerprint;
        $entry['present'] = true;
        $entry['last_seen'] = $now;
        $entry['revision'] = hash('sha256', $fingerprint . wp_json_encode($entry['rule']) . $entry['status']);
        $entries[$key] = $entry;
        if ($changed || $returned) { hometown_api_record($key, $entry, ! $old ? 'imported' : ($returned ? 'returned' : 'changed')); }
        // Refresh observation timestamps without adding unchanged hourly history rows.
        global $wpdb;
        $wpdb->update(hometown_api_table(), ['last_seen' => $now], ['rule_key' => $key]);
    }
    foreach ($entries as $key => &$entry) {
        if (! isset($incoming[$key]) && ! empty($entry['present'])) {
            $entry['present'] = false;
            $entry['status'] = 'inactive';
            $entry['revision'] = hash('sha256', $entry['revision'] . ':inactive');
            hometown_api_record($key, $entry, 'disappeared');
        }
    }
    unset($entry);
    update_option(HTR_API_RULES, $entries, false);
    return count($incoming);
}

function hometown_api_fetch() {
    if (! hometown_api_lock()) { return new WP_Error('busy', 'A sync or review is in progress. Please try again.'); }
    try {
        hometown_api_expire_rules();
        $config = get_option(HTR_API_CONFIG, []);
        $result = new WP_Error('credentials', 'Save an HTTPS endpoint, username and password first.');
        if (! empty($config['username']) && ! empty($config['password']) && strpos($config['url'] ?? '', 'https://') === 0) {
            $response = wp_safe_remote_get($config['url'], [
                'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
                'headers' => ['Authorization' => 'Basic ' . base64_encode($config['username'] . ':' . $config['password']), 'Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                $result = new WP_Error('network', 'API request failed or timed out.');
            } elseif (wp_remote_retrieve_response_code($response) !== 200) {
                $result = new WP_Error('http', 'API returned HTTP ' . (int) wp_remote_retrieve_response_code($response) . '.');
            } else {
                $body = wp_remote_retrieve_body($response);
                $decoded = json_decode($body);
                $result = is_array($decoded) || (is_object($decoded) && isset($decoded->locations) && is_array($decoded->locations))
                    ? hometown_api_import(json_decode($body, true))
                    : new WP_Error('snapshot', 'API returned an invalid snapshot.');
            }
        }
        $sync = get_option(HTR_API_SYNC, []);
        $sync['attempted_at'] = current_time('mysql', true);
        $sync['error'] = is_wp_error($result) ? $result->get_error_message() : '';
        if (! is_wp_error($result)) { $sync['succeeded_at'] = $sync['attempted_at']; $sync['count'] = $result; }
        update_option(HTR_API_SYNC, $sync, false);
        return $result;
    } finally { hometown_api_unlock(); }
}

function hometown_announcement_api_active_rules() {
    $rules = [];
    $global = hometown_announcement_get_settings()['default'];
    foreach (get_option(HTR_API_RULES, []) as $entry) {
        if (! empty($entry['present']) && $entry['status'] === 'approved' && ! hometown_api_is_expired($entry)) {
            $rule = $entry['rule'];
            if (! empty($rule['use_global_content'])) {
                $rule['message'] = $global['message'] ?? '';
                $rule['cta_label'] = '';
                $rule['cta_url'] = '';
                if ($rule['message'] === '') { continue; }
            }
            $rules[] = $rule;
        }
    }
    return $rules;
}

function hometown_api_review($key, $revision, $decision, $input) {
    $entries = get_option(HTR_API_RULES, []);
    if (! isset($entries[$key]) || ! hash_equals($entries[$key]['revision'], $revision)) {
        return new WP_Error('stale', 'This rule changed since the page was opened.');
    }
    if (! in_array($decision, ['approved', 'disabled', 'rejected', 'pending'], true)) {
        return new WP_Error('invalid', 'Invalid review action.');
    }
    $entry = $entries[$key];
    if (! empty($entry['rule']['use_global_content']) && is_array($input)) {
        $global = hometown_announcement_get_settings()['default'];
        $input['message'] = $global['message'] ?? '';
        $input['cta_label'] = '';
        $input['cta_url'] = '';
    }
    $rule = hometown_api_map_location($input);
    if (is_wp_error($rule) || ($decision === 'approved' && (empty($entry['present']) || count($entry['variants']) > 1 || hometown_api_is_expired($entry)))) {
        return new WP_Error('invalid', 'Invalid rule or unresolved API conflict.');
    }
    if (! empty($entry['rule']['use_global_content'])) { $rule['use_global_content'] = true; }
    $entry['rule'] = $rule;
    $entry['status'] = hometown_api_is_expired($entry) ? 'expired' : (empty($entry['present']) ? 'inactive' : $decision);
    $entry['revision'] = hash('sha256', $entry['fingerprint'] . wp_json_encode($rule) . $entry['status']);
    $entries[$key] = $entry;
    update_option(HTR_API_RULES, $entries, false);
    hometown_api_record($key, $entry, $decision === 'pending' ? 'edited' : $decision);
    return true;
}

add_action('admin_menu', function () {
    add_submenu_page('hometown-announcements', 'API Location Rules', 'API Location Rules', 'manage_options', 'hometown-announcement-api', 'hometown_api_admin_page');
    add_submenu_page('hometown-announcements', 'Announcement Reports', 'Reports', 'manage_options', 'hometown-announcement-reports', 'hometown_api_reports_page');
}, 20); // The parent menu registers at priority 10 in the main plugin file.

function hometown_api_form_start($operation) {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="hometown_announcement_api"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    wp_nonce_field('hometown_announcement_api');
}

add_action('admin_post_hometown_announcement_api', function () {
    if (! current_user_can('manage_options')) { wp_die('Not authorized.', '', ['response' => 403]); }
    check_admin_referer('hometown_announcement_api');
    $op = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $notice = 'saved';
    $page = 'hometown-announcement-api';
    if ($op === 'fetch') {
        $result = hometown_api_fetch();
        $notice = is_wp_error($result) ? 'fetch_failed' : 'fetched';
    } elseif ($op === 'config') {
        if (! hometown_api_save_config(wp_unslash($_POST))) { $notice = 'invalid'; }
    } elseif ($op === 'delete_history') {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . hometown_api_table());
        $page = 'hometown-announcement-reports';
    } elseif ($op === 'review') {
        if (! hometown_api_lock()) { $notice = 'busy'; }
        else {
            try {
                $key = sanitize_key(wp_unslash($_POST['rule_key'] ?? ''));
                $decision = sanitize_key(wp_unslash($_POST['decision'] ?? ''));
                $result = hometown_api_review($key, (string) wp_unslash($_POST['revision'] ?? ''), $decision, wp_unslash($_POST[HOMETOWN_ANNOUNCEMENT_OPTION]['locations'][0] ?? []));
                if (is_wp_error($result)) { $notice = $result->get_error_code(); }
            } finally { hometown_api_unlock(); }
        }
    } else { $notice = 'invalid'; }
    wp_safe_redirect(add_query_arg(['page' => $page, 'api_notice' => $notice], admin_url('admin.php')));
    exit;
});

function hometown_api_notice() {
    $messages = ['saved' => 'Saved.', 'fetched' => 'API locations refreshed.', 'fetch_failed' => 'Fetch failed or another operation is running. See sync status below; previous rules were preserved.', 'invalid' => 'Unable to save: check the endpoint, required fields, schedule, or conflicting API entries.', 'stale' => 'This rule changed since you opened the page. Review the current values and try again.', 'busy' => 'Another sync or review is running. Try again.'];
    $key = sanitize_key(wp_unslash($_GET['api_notice'] ?? ''));
    if (isset($messages[$key])) { echo '<div class="notice notice-info"><p>' . esc_html($messages[$key]) . '</p></div>'; }
}

/** Local fixture only: preview mapping without importing or replacing saved API events. */
function hometown_api_example_response() {
    return ['ok' => true, 'read_only' => true, 'locations_count' => 5, 'locations' => [
        ['name' => 'San Antonio', 'lat' => 29.4241, 'lng' => -98.4936, 'radius' => 50],
        ['name' => 'Boerne', 'lat' => 29.7947, 'lng' => -98.7319, 'radius' => 40],
        ['name' => 'Austin', 'lat' => 30.2672, 'lng' => -97.7431, 'radius' => 50],
        ['name' => 'Marble Falls', 'lat' => 30.5782, 'lng' => -98.2728, 'radius' => 40],
        ['name' => 'Aransas Pass', 'lat' => 27.9095, 'lng' => -97.1499, 'radius' => 40],
    ]];
}

/** Signed, administrator-only request preview; no saved rules or visitor geo changes. */
function hometown_api_example_index() {
    if (! isset($_GET['htr_announcement_example']) || ! current_user_can('manage_options')) { return null; }
    $index = wp_unslash($_GET['htr_announcement_example']);
    $nonce = wp_unslash($_GET['_htr_preview_nonce'] ?? '');
    if (! is_string($index) || ! ctype_digit($index) || ! is_string($nonce)
        || ! wp_verify_nonce($nonce, 'hometown_announcement_example')
        || ! isset(hometown_api_example_response()['locations'][(int) $index])) { return null; }
    return (int) $index;
}

function hometown_api_frontend_example() {
    $manual = hometown_manual_preview_index();
    if ($manual !== null) {
        $settings = hometown_announcement_get_settings();
        return hometown_build_announcement($settings['locations'][$manual], $settings['default'], 'manual-preview');
    }
    $index = hometown_api_example_index();
    if ($index === null) { return null; }
    $rule = hometown_api_map_location(hometown_api_example_response()['locations'][$index]);
    if (is_wp_error($rule)) { return null; }
    $global = hometown_announcement_get_settings()['default'];
    return hometown_build_announcement($rule, $global, 'api-example');
}

function hometown_manual_preview_index() {
    if (! isset($_GET['htr_announcement_rule']) || ! current_user_can('manage_options')) { return null; }
    $index = wp_unslash($_GET['htr_announcement_rule']);
    $nonce = wp_unslash($_GET['_htr_preview_nonce'] ?? '');
    if (! is_string($index) || ! ctype_digit($index) || ! is_string($nonce)
        || ! wp_verify_nonce($nonce, 'hometown_announcement_rule_preview')
        || ! isset(hometown_announcement_get_settings()['locations'][(int) $index])) { return null; }
    return (int) $index;
}

function hometown_manual_preview_links() {
    $links = [];
    foreach (hometown_announcement_get_settings()['locations'] ?? [] as $index => $rule) {
        $links[$index] = html_entity_decode(wp_nonce_url(add_query_arg(['htr_announcement_rule' => $index], home_url('/')), 'hometown_announcement_rule_preview', '_htr_preview_nonce'), ENT_QUOTES, 'UTF-8');
    }
    return $links;
}

add_action('init', function () {
    if ((hometown_api_example_index() !== null || hometown_manual_preview_index() !== null) && ! defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
});
add_action('template_redirect', function () {
    if (hometown_api_example_index() !== null || hometown_manual_preview_index() !== null) { nocache_headers(); }
});
add_action('wp_footer', function () {
    $index = hometown_api_example_index();
    $manual = hometown_manual_preview_index();
    if ($index === null && $manual === null) { return; }
    $label = $manual !== null ? hometown_get_rule_title(hometown_announcement_get_settings()['locations'][$manual]) : hometown_api_example_response()['locations'][$index]['name'] . ', TX';
    echo '<div style="position:fixed;bottom:16px;left:16px;right:16px;z-index:999999;background:#fff;border:2px solid #2271b1;padding:12px;color:#1d2327" role="status">Announcement preview: ' . esc_html($label) . '. Only visible to you; saved rules are unchanged. <a href="' . esc_url(home_url('/')) . '">Exit preview</a></div>';
});

function hometown_api_example_preview() {
    $response = hometown_api_example_response();
    $global = hometown_announcement_get_settings()['default'];
    echo '<details class="htr-api-example"><summary class="htr-example-toggle">Preview example fetch <span>5 sample cities</span></summary><div class="htr-example-content">';
    echo '<div class="htr-example-heading"><div><h2>Test the announcement bar</h2><p>Choose a city to open a website preview in a new tab.</p></div><span class="htr-example-badge">Admin preview only</span></div>';
    echo '<div class="htr-example-message"><span class="htr-example-eyebrow">Global announcement</span><strong>' . esc_html($global['title'] ?? '') . '</strong><p>' . esc_html($global['message'] ?? '') . '</p>';
    if (! empty($global['cta_label'])) { echo '<span class="htr-example-cta">' . esc_html($global['cta_label']) . ' &rarr;</span>'; }
    echo '</div><p class="htr-example-note">Examples use your global message and CTA. Saved rules and reports stay unchanged.</p><div class="htr-example-grid">';
    foreach ($response['locations'] as $index => $location) {
        $rule = hometown_api_map_location($location);
        echo '<article class="htr-example-city"><span class="htr-example-eyebrow">Texas &middot; City match</span><h3>' . esc_html($location['name']) . ', TX</h3>';
        if (is_wp_error($rule)) {
            echo '<p role="alert">' . esc_html($rule->get_error_message()) . '</p>';
        } else {
            echo '<p class="htr-example-ready"><span aria-hidden="true">&#10003;</span> Mapping successful</p><p class="htr-example-lifetime">Auto-approved on import &middot; Expires in 14 days</p>';
            echo '<a class="button htr-example-link" target="_blank" rel="noopener" href="' . esc_url(wp_nonce_url(add_query_arg(['htr_announcement_example' => $index], home_url('/')), 'hometown_announcement_example', '_htr_preview_nonce')) . '">Preview announcement <span aria-hidden="true">&nearr;</span><span class="screen-reader-text"> for ' . esc_html($location['name']) . ' (opens in a new tab)</span></a>';
        }
        echo '</article>';
    }
    echo '</div><details class="htr-example-json"><summary>View example API response <span>JSON</span></summary><pre>' . esc_html(wp_json_encode($response, JSON_PRETTY_PRINT)) . '</pre></details></div></details>';
    echo <<<'HTML'
<style>
.htr-api-example{max-width:1000px;margin:24px 0;border:1px solid #dcdcde;border-radius:12px;background:#fff;overflow:hidden}
.htr-example-toggle{padding:18px 22px;cursor:pointer;font-size:14px;font-weight:600;color:#1d2327}.htr-example-toggle>span{margin-left:10px;font-size:12px;font-weight:400;color:#646970}.htr-api-example[open]>.htr-example-toggle{border-bottom:1px solid #e8eaed;background:#fafbfc}
.htr-example-content{padding:22px}.htr-example-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}.htr-example-heading h2{margin:0 0 6px;font-size:18px}.htr-example-heading p{margin:0;color:#646970}.htr-example-badge{flex-shrink:0;padding:5px 10px;border:1px solid #d4e4f4;border-radius:20px;color:#205b8d;background:#f0f6fc;font-size:11px;font-weight:600}
.htr-example-message{padding:18px 20px;border-left:3px solid #2271b1;border-radius:0 8px 8px 0;background:#f5f8fc}.htr-example-eyebrow{display:block;margin-bottom:8px;color:#646970;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase}.htr-example-message strong{display:block;font-size:14px}.htr-example-message p{margin:7px 0;line-height:1.6}.htr-example-cta{display:inline-block;margin-top:4px;color:#2271b1;font-size:12px;font-weight:600}.htr-example-note{margin:12px 0 20px;color:#646970;font-size:12px}
.htr-example-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.htr-example-city{display:flex;flex-direction:column;align-items:flex-start;padding:18px;border:1px solid #e1e5e9;border-radius:9px;background:#fff}.htr-example-city h3{margin:0 0 12px;font-size:16px;line-height:1.4}.htr-example-ready{margin:0;color:#16703a;font-size:12px}.htr-example-ready>span{margin-right:4px}.htr-example-lifetime{margin:6px 0 18px;color:#646970;font-size:11px;line-height:1.6}.htr-example-city .htr-example-link{display:flex;align-items:center;justify-content:space-between;gap:12px;box-sizing:border-box;width:100%;min-height:36px;margin-top:auto;padding:3px 12px;border-radius:5px;text-align:left}
.htr-example-json{margin-top:20px;border-top:1px solid #e8eaed;padding-top:16px}.htr-example-json>summary{cursor:pointer;color:#50575e;font-size:12px;font-weight:600}.htr-example-json>summary>span{margin-left:8px;font-family:monospace;font-size:10px;color:#646970}.htr-example-json pre{box-sizing:border-box;max-height:320px;overflow:auto;margin:14px 0 0;padding:18px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;color:#2c3338;font-size:12px;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
@media(max-width:600px){.htr-example-content{padding:16px}.htr-example-toggle{padding:16px}.htr-example-heading{flex-direction:column;gap:10px}.htr-example-grid{grid-template-columns:1fr}.htr-example-toggle>span{display:block;margin:6px 0 0}}
</style>
HTML;
}

function hometown_api_admin_page() {
    if (! current_user_can('manage_options')) { return; }
    $config = get_option(HTR_API_CONFIG, []);
    $sync = get_option(HTR_API_SYNC, []);
    echo '<div class="wrap hometown-announcement-admin"><h1>API Location Rules</h1>';
    hometown_api_notice();
    echo '<p>Valid imported locations are automatically approved and expire 14 days after first detection. Manual rules take priority within the same match type. All timestamps below are UTC.</p>';
    hometown_api_form_start('config');
    echo '<h2>API connection</h2><table class="form-table">';
    foreach (['url' => ['Endpoint URL', $config['url'] ?? 'https://automation.hometownroofingtx.com/webhook/htr-announcement-api'], 'username' => ['Username', $config['username'] ?? ''], 'password' => ['Password', '']] as $name => $field) {
        echo '<tr><th><label for="htr-api-' . esc_attr($name) . '">' . esc_html($field[0]) . '</label></th><td><input class="regular-text" id="htr-api-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . ($name === 'password' ? 'password' : ($name === 'url' ? 'url' : 'text')) . '" value="' . esc_attr($field[1]) . '" autocomplete="' . ($name === 'password' ? 'new-password' : 'off') . '">';
        if ($name === 'password') { echo '<p class="description">' . (! empty($config['password']) ? 'Password saved. ' : '') . 'Leave blank to preserve the saved password.</p>'; }
        echo '</td></tr>';
    }
    echo '</table>';
    submit_button('Save API settings'); echo '</form>';
    echo '<p>Last attempt: ' . esc_html($sync['attempted_at'] ?? 'Never') . ' | Last success: ' . esc_html($sync['succeeded_at'] ?? 'Never') . ' | Locations: ' . esc_html($sync['count'] ?? '—') . '</p>';
    if (! empty($sync['error'])) { echo '<p role="alert">' . esc_html($sync['error']) . '</p>'; }
    echo '<p>Refreshes hourly through WordPress cron after credentials are saved. Cron timing depends on site traffic.</p>';
    echo '<div class="htr-fetch-panel"><div class="htr-fetch-panel__heading"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><div><h2>Refresh API locations</h2><p>Check for the latest detected storm locations.</p></div></div>';
    hometown_api_form_start('fetch');
    echo '<button type="submit" class="button button-primary htr-fetch-button"><svg class="htr-fetch-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.55-1.6L20 8M4 16l2.35 2.6A7 7 0 0 0 17.9 17"/></svg><span class="htr-fetch-button__label">Fetch now</span></button><span class="htr-fetch-hint">Updates automatically every hour</span>';
    echo '<div class="htr-fetch-status" role="status" aria-live="polite" aria-atomic="true"><div class="htr-fetch-status__body" hidden><span class="htr-fetch-spinner" aria-hidden="true"></span><div><strong>Fetching the latest locations</strong><p>Connecting to the API and checking for updates. This may take up to 30 seconds.</p></div></div></div><div class="htr-fetch-progress" hidden aria-hidden="true"><span></span></div></form></div>';
    echo <<<'HTML'
<style>
.htr-fetch-panel{max-width:760px;margin:20px 0 24px;padding:22px;border:1px solid #dcdcde;border-radius:12px;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.03)}
.htr-fetch-panel__heading{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.htr-fetch-panel__heading>.dashicons{display:grid;place-items:center;flex-shrink:0;width:44px;height:44px;border-radius:10px;background:#eef5fc;color:#2271b1;font-size:25px}
.htr-fetch-panel__heading h2{margin:0 0 5px;font-size:16px}.htr-fetch-panel__heading p{margin:0;color:#646970}
.htr-fetch-panel .htr-fetch-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 18px;border-radius:6px;font-weight:600;vertical-align:middle}
.htr-fetch-panel .htr-fetch-icon{display:block;flex:0 0 18px;width:18px;height:18px;margin:0;align-self:center}.htr-fetch-panel .htr-fetch-button__label{display:block;line-height:20px;margin:0}.htr-fetch-panel .htr-fetch-button:disabled{background:#eef2f6!important;border-color:#c3cdd7!important;color:#526577!important;cursor:wait}
.htr-fetch-hint{display:inline-block;margin-left:12px;color:#646970;font-size:12px;vertical-align:middle}
.htr-fetch-status__body{display:flex;align-items:flex-start;gap:12px;margin-top:18px;padding:16px;background:#f0f6fc;border:1px solid #d4e4f4;border-radius:8px;color:#1d3f5e}
.htr-fetch-panel [hidden]{display:none!important}.htr-fetch-status strong{display:block;font-size:13px;line-height:1.5}.htr-fetch-status p{margin:4px 0 0;font-size:12px;line-height:1.6;color:#46617a}
.htr-fetch-spinner{flex-shrink:0;width:18px;height:18px;margin-top:2px;border:2px solid #c3d8ec;border-top-color:#2271b1;border-radius:50%;animation:htr-fetch-spin .8s linear infinite}
.htr-fetch-progress{height:3px;overflow:hidden;margin-top:14px;border-radius:3px;background:#e8eff6}.htr-fetch-progress span{display:block;width:35%;height:100%;border-radius:3px;background:#2271b1;animation:htr-fetch-slide 1.6s ease-in-out infinite}
.htr-fetch-panel form[aria-busy="true"] .htr-fetch-button .htr-fetch-icon{animation:htr-fetch-spin 1.2s linear infinite}
@keyframes htr-fetch-spin{to{transform:rotate(360deg)}}@keyframes htr-fetch-slide{from{transform:translateX(-110%)}to{transform:translateX(390%)}}
@media(prefers-reduced-motion:reduce){.htr-fetch-panel *{animation:none!important}.htr-fetch-progress span{width:100%;opacity:.5}}
@media(max-width:600px){.htr-fetch-panel{padding:16px}.htr-fetch-hint{display:block;margin:10px 0 0}.htr-fetch-panel .htr-fetch-button{width:100%}}
</style>
HTML;
    hometown_api_example_preview();
    echo '<h2>Imported location rules</h2>';
    $entries = get_option(HTR_API_RULES, []);
    if (! $entries) { echo '<p>No API locations imported yet.</p>'; }
    foreach ($entries as $key => $entry) {
        echo '<section id="rule-' . esc_attr($key) . '" class="htr-api-entry"><h3>' . esc_html(hometown_get_rule_title($entry['rule'])) . ' — ' . esc_html(ucfirst($entry['status'])) . '</h3>';
        echo '<p>First detected: ' . esc_html($entry['first_detected']) . ' | Last seen: ' . esc_html($entry['last_seen']) . '</p>';
        echo '<p><strong>Expires: ' . esc_html(gmdate('Y-m-d H:i:s', hometown_api_expires_at($entry))) . ' UTC' . (hometown_api_is_expired($entry) ? ' (Expired)' : '') . '</strong></p>';
        if (! empty($entry['rule']['use_global_content'])) { echo '<p>Uses the current global announcement message and CTA. Change that copy in Announcement Bar Settings.</p>'; }
        if (count($entry['variants']) > 1) {
            echo '<p><strong>Conflicting entries share the same API identity. Approval is blocked until the API resolves the conflict.</strong></p><pre>' . esc_html(wp_json_encode($entry['variants'], JSON_PRETTY_PRINT)) . '</pre>';
        }
        hometown_api_form_start('review');
        echo '<input type="hidden" name="rule_key" value="' . esc_attr($key) . '"><input type="hidden" name="revision" value="' . esc_attr($entry['revision']) . '">';
        $display_rule = $entry['rule'];
        if (! empty($display_rule['use_global_content'])) {
            $global = hometown_announcement_get_settings()['default'];
            $display_rule['message'] = $global['message'] ?? '';
            $display_rule['cta_label'] = $global['cta_label'] ?? '';
            $display_rule['cta_url'] = $global['cta_url'] ?? '';
        }
        hometown_render_announcement_rule(0, $display_rule, ! empty($display_rule['use_global_content']));
        echo '<p>';
        foreach (['approved' => 'Save and enable', 'disabled' => 'Disable', 'rejected' => 'Reject'] as $value => $label) {
            echo '<button class="button ' . ($value === 'approved' ? 'button-primary' : '') . '" name="decision" value="' . esc_attr($value) . '"' . ($value === 'approved' && (empty($entry['present']) || count($entry['variants']) > 1 || hometown_api_is_expired($entry)) ? ' disabled' : '') . '>' . esc_html($label) . '</button> ';
        }
        echo '</p></form></section>';
    }
    echo '</div><style>.htr-api-entry{background:#fff;padding:20px;margin:20px 0;scroll-margin-top:40px}.htr-api-entry .hometown-announcement-rule__header{display:none}.htr-api-entry .hometown-announcement-rule__body{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.htr-api-entry [hidden]{display:none!important}.htr-api-entry pre{white-space:pre-wrap;overflow-wrap:anywhere}.htr-api-entry input,.htr-api-entry select,.htr-api-entry textarea{max-width:100%}@media(max-width:600px){.htr-api-entry .hometown-announcement-rule__body{grid-template-columns:1fr}}</style>';
    echo '<script>document.querySelectorAll(".htr-api-entry").forEach(function(entry){function update(){var type=entry.querySelector(".hometown-match-type").value;entry.querySelector(".hometown-city-field").hidden=type!=="city";entry.querySelector(".hometown-state-field").hidden=type==="url";entry.querySelector(".hometown-url-field").hidden=type!=="url";entry.querySelectorAll(".hometown-schedule-date").forEach(function(el){el.hidden=entry.querySelector(".hometown-schedule-type").value!=="dates";});}entry.addEventListener("change",update);update();});</script>';
    echo <<<'HTML'
<script>
document.querySelectorAll('.htr-fetch-panel form').forEach(function(form){
    var button=form.querySelector('.htr-fetch-button');
    var label=button.querySelector('.htr-fetch-button__label');
    var status=form.querySelector('.htr-fetch-status__body');
    var progress=form.querySelector('.htr-fetch-progress');
    form.addEventListener('submit',function(event){
        if(form.dataset.fetching){event.preventDefault();return;}
        form.dataset.fetching='1';
        form.setAttribute('aria-busy','true');
        button.disabled=true;
        label.textContent='Fetching…';
        status.hidden=false;
        progress.hidden=false;
    });
    window.addEventListener('pageshow',function(){
        delete form.dataset.fetching;
        form.removeAttribute('aria-busy');
        button.disabled=false;
        label.textContent='Fetch now';
        status.hidden=true;
        progress.hidden=true;
    });
});
</script>
HTML;
}

function hometown_api_report_where($status, $location) {
    global $wpdb;
    $where = 'WHERE 1=1';
    if (in_array($status, ['pending', 'approved', 'disabled', 'rejected', 'inactive', 'conflict', 'expired'], true)) {
        $where .= $wpdb->prepare(' AND status = %s', $status);
    }
    if ($location !== '') { $where .= $wpdb->prepare(' AND location LIKE %s', '%' . $wpdb->esc_like($location) . '%'); }
    return $where;
}

function hometown_api_csv_columns() {
    return ['id' => 'Event ID', 'location' => 'Location', 'match_type' => 'Match type', 'message' => 'Announcement message', 'schedule' => 'Schedule and expiration', 'event_type' => 'Event', 'status' => 'Status', 'first_detected' => 'First detected (UTC)', 'last_seen' => 'Last seen (UTC)', 'recorded_at' => 'Recorded (UTC)'];
}

function hometown_api_csv_row($row) {
    $cells = [];
    foreach (hometown_api_csv_columns() as $field => $label) {
        $value = (string) ($row[$field] ?? '');
        // Keep spreadsheet applications from interpreting untrusted text as formulas.
        if (preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value)) { $value = "'" . $value; }
        $cells[] = $value;
    }
    return $cells;
}

add_action('admin_post_hometown_announcement_export', function () {
    if (! current_user_can('manage_options')) { wp_die('Not authorized.', '', ['response' => 403]); }
    check_admin_referer('hometown_announcement_export');
    global $wpdb;
    $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
    $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
    $table = hometown_api_table();
    $where = hometown_api_report_where($status, $location);
    // Bound the export to events existing at the start, and stream in small batches.
    $last_id = (int) $wpdb->get_var("SELECT MAX(id) FROM $table $where");
    $cursor = 0;
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="announcement-report-' . gmdate('Y-m-d-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values(hometown_api_csv_columns()), ',', '"', '');
    do {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where AND id > %d AND id <= %d ORDER BY id ASC LIMIT 500", $cursor, $last_id), ARRAY_A);
        foreach ($rows as $row) {
            fputcsv($output, hometown_api_csv_row($row), ',', '"', '');
            $cursor = (int) $row['id'];
        }
    } while (count($rows) === 500 && $cursor < $last_id);
    fclose($output);
    exit;
});

function hometown_api_reports_page() {
    if (! current_user_can('manage_options')) { return; }
    global $wpdb;
    $table = hometown_api_table();
    $statuses = ['pending', 'approved', 'disabled', 'rejected', 'inactive', 'conflict', 'expired'];
    $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
    if (! in_array($status, $statuses, true)) { $status = ''; }
    $location = sanitize_text_field(wp_unslash($_GET['location'] ?? ''));
    $paged = max(1, absint($_GET['paged'] ?? 1));
    $where = hometown_api_report_where($status, $location);
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $paged = min($paged, max(1, (int) ceil($total / 25)));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d", 25, ($paged - 1) * 25), ARRAY_A);
    $entries = get_option(HTR_API_RULES, []);
    $counts = ['Tracked locations' => count($entries), 'Approved rules' => 0, 'Expired rules' => 0, 'Needs attention' => 0];
    foreach ($entries as $entry) {
        if (hometown_api_is_expired($entry)) { $counts['Expired rules']++; }
        elseif (! empty($entry['present']) && $entry['status'] === 'approved') { $counts['Approved rules']++; }
        elseif (in_array($entry['status'], ['pending', 'conflict'], true)) { $counts['Needs attention']++; }
    }
    $sync = get_option(HTR_API_SYNC, []);
    echo '<div class="wrap htr-reports"><div class="htr-reports-heading"><div><h1>Announcement Reports</h1><p>Monitor imported locations and follow announcement activity.</p></div><a class="button" href="' . esc_url(admin_url('admin.php?page=hometown-announcement-api')) . '">Manage API rules &rarr;</a></div>';
    hometown_api_notice();
    echo '<div class="htr-reports-stats">';
    foreach ($counts as $label => $count) { echo '<div class="htr-reports-stat"><span>' . esc_html($label) . '</span><strong>' . esc_html($count) . '</strong></div>'; }
    echo '</div><p class="htr-reports-context">Current saved rules &middot; Independent of history filters &middot; Approved rules still depend on schedule and location matching.</p>';
    echo '<div class="htr-reports-sync"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Last successful sync: <strong>' . esc_html($sync['succeeded_at'] ?? 'Not synced yet') . '</strong> <span class="htr-reports-muted">(UTC)</span></span>';
    if (! empty($sync['error'])) { echo '<span class="htr-reports-sync__error">' . esc_html($sync['error']) . '</span>'; }
    echo '</div><section class="htr-reports-panel"><div class="htr-reports-panel__heading"><h2>Event history <span>' . esc_html($total) . '</span></h2><p>Newest first. Status reflects the recorded event; all times are UTC.</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="htr-reports-export"><input type="hidden" name="action" value="hometown_announcement_export"><input type="hidden" name="status" value="' . esc_attr($status) . '"><input type="hidden" name="location" value="' . esc_attr($location) . '">';
    wp_nonce_field('hometown_announcement_export');
    echo '<button type="submit" class="button">Export CSV &darr;</button><span>All events matching the applied filters, across every page.</span></form>';
    echo '<form method="get" class="htr-reports-filters"><input type="hidden" name="page" value="hometown-announcement-reports"><label for="htr-report-location">Location<input id="htr-report-location" type="search" name="location" placeholder="Search city, state or URL" value="' . esc_attr($location) . '"></label><label for="htr-report-status">Event status<select id="htr-report-status" name="status"><option value="">All statuses</option>';
    foreach ($statuses as $value) { echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html(ucfirst($value)) . '</option>'; }
    echo '</select></label><button class="button button-primary">Apply filters</button>';
    if ($status || $location) { echo '<a class="htr-reports-reset" href="' . esc_url(admin_url('admin.php?page=hometown-announcement-reports')) . '">Clear filters</a>'; }
    echo '</form><div class="htr-reports-table-wrap"><table class="widefat htr-reports-table"><thead><tr>';
    foreach (['Location', 'Announcement', 'Event', 'Status', 'Observed', 'Recorded'] as $heading) { echo '<th scope="col">' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td><a class="htr-reports-location" href="' . esc_url(admin_url('admin.php?page=hometown-announcement-api#rule-' . $row['rule_key'])) . '">' . esc_html($row['location']) . '</a><span class="htr-reports-type">' . esc_html(ucfirst($row['match_type'])) . ' match</span></td>';
        echo '<td class="htr-reports-message"><p>' . esc_html($row['message']) . '</p><details><summary>Schedule &amp; expiration</summary><p>' . esc_html($row['schedule']) . '</p></details></td>';
        echo '<td><span class="htr-reports-event">' . esc_html(ucfirst(str_replace('_', ' ', $row['event_type']))) . '</span></td>';
        $badge = in_array($row['status'], $statuses, true) ? $row['status'] : 'inactive';
        echo '<td><span class="htr-reports-badge htr-reports-badge--' . esc_attr($badge) . '">' . esc_html(ucfirst($row['status'])) . '</span></td>';
        echo '<td class="htr-reports-time"><span>First detected</span>' . esc_html($row['first_detected']) . '<span>Last seen</span>' . esc_html($row['last_seen']) . '</td><td class="htr-reports-time">' . esc_html($row['recorded_at']) . '</td></tr>';
    }
    if (! $rows) {
        echo '<tr><td colspan="6"><div class="htr-reports-empty"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><h3>' . ($status || $location ? 'No matching events' : 'No announcement activity yet') . '</h3><p>' . ($status || $location ? 'Try another location or clear your filters.' : 'Fetch API locations to start building your event history.') . '</p></div></td></tr>';
    }
    echo '</tbody></table></div><div class="htr-reports-pagination"><span>' . ($total ? 'Showing ' . esc_html(($paged - 1) * 25 + 1) . '&ndash;' . esc_html(min($paged * 25, $total)) . ' of ' . esc_html($total) . ' events' : '0 events') . '</span><nav aria-label="Report pages">';
    $base = str_replace('999999999', '%#%', add_query_arg(['page' => 'hometown-announcement-reports', 'status' => $status, 'location' => $location, 'paged' => 999999999], admin_url('admin.php')));
    $pagination = paginate_links(['base' => $base, 'current' => $paged, 'total' => max(1, (int) ceil($total / 25))]);
    echo wp_kses_post($pagination ?? '');
    echo '</nav></div></section><details class="htr-reports-maintenance"><summary>History management</summary><p>Permanently remove report history. Imported rules and announcement settings are retained.</p>';
    hometown_api_form_start('delete_history');
    echo '<button class="button" onclick="return confirm(\'Permanently delete all report history? Imported rules will remain.\')">Delete all report history</button></form></details></div>';
    echo <<<'HTML'
<style>
.htr-reports-export{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:16px 24px 0}.htr-reports-export .button{min-height:36px;padding:2px 14px;border-radius:6px}.htr-reports-export>span{color:#646970;font-size:11px}
.htr-reports{max-width:1500px}.htr-reports-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0 24px}.htr-reports-heading h1{padding:0}.htr-reports-heading p{margin:8px 0 0;color:#646970}
.htr-reports-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.htr-reports-stat{padding:20px 22px;border:1px solid #dfe4eb;border-radius:10px;background:#fff}.htr-reports-stat>span{display:block;color:#646970;font-size:12px}.htr-reports-stat>strong{display:block;margin-top:10px;font-size:28px;line-height:1.2;color:#1d2327;font-variant-numeric:tabular-nums}.htr-reports-context{margin:10px 0 20px;color:#646970;font-size:11px;line-height:1.7}
.htr-reports-sync{display:flex;align-items:center;flex-wrap:wrap;gap:8px 12px;margin-bottom:20px;padding:13px 16px;background:#eef5fc;border:1px solid #d6e5f3;border-radius:8px;color:#315b80;font-size:12px}.htr-reports-sync>.dashicons{font-size:18px}.htr-reports-sync__error{flex-basis:100%;color:#a63727}.htr-reports-muted{color:#646970}
.htr-reports-panel{overflow:hidden;border:1px solid #dfe4eb;border-radius:12px;background:#fff}.htr-reports-panel__heading{padding:22px 24px 0}.htr-reports-panel__heading h2{display:flex;align-items:center;gap:10px;margin:0;font-size:17px}.htr-reports-panel__heading h2>span{padding:3px 9px;border-radius:16px;background:#f0f2f5;color:#526174;font-size:11px}.htr-reports-panel__heading p{margin:8px 0 0;color:#646970;font-size:12px}
.htr-reports-filters{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;padding:20px 24px}.htr-reports-filters label{display:flex;flex-direction:column;gap:6px;font-size:11px;font-weight:600;color:#50575e}.htr-reports-filters input{width:280px;max-width:100%}.htr-reports-filters input,.htr-reports-filters select{min-height:38px;border-color:#cdd3db;border-radius:6px;font-size:12px}.htr-reports-filters .button{min-height:38px;border-radius:6px;padding:3px 16px}.htr-reports-reset{padding:10px 4px;font-size:12px}
.htr-reports-table-wrap{overflow-x:auto}.htr-reports .htr-reports-table{border:0;border-collapse:collapse;min-width:960px}.htr-reports-table thead th{padding:12px 18px;background:#f8fafc;border-top:1px solid #e8edf2;border-bottom:1px solid #e8edf2;color:#646970;font-size:11px;font-weight:600;white-space:nowrap}.htr-reports-table td{padding:18px;border-bottom:1px solid #edf0f3;vertical-align:top;font-size:12px}.htr-reports-table tbody tr:hover{background:#fcfdff}.htr-reports-location{display:block;min-width:110px;font-weight:600;text-decoration:none}.htr-reports-type{display:block;margin-top:7px;color:#646970;font-size:10px;text-transform:uppercase;letter-spacing:.03em}.htr-reports-message{min-width:220px;max-width:400px;overflow-wrap:anywhere}.htr-reports-message p{margin:0;font-size:12px;line-height:1.7}.htr-reports-message details{margin-top:9px;color:#646970;font-size:11px}.htr-reports-message summary{cursor:pointer}.htr-reports-message details p{margin-top:7px;font-size:11px}.htr-reports-event{white-space:nowrap}.htr-reports-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:20px;background:#f0f2f5;color:#526174;font-size:10px;font-weight:500;white-space:nowrap}.htr-reports-badge:before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}.htr-reports-badge--approved{color:#16703a;background:#eaf7ee}.htr-reports-badge--pending{color:#8a6112;background:#fff6df}.htr-reports-badge--conflict,.htr-reports-badge--rejected{color:#a63727;background:#fcefeb}.htr-reports-time{white-space:nowrap;font-size:11px!important;font-variant-numeric:tabular-nums}.htr-reports-time>span{display:block;margin:0 0 4px;color:#646970;font-size:10px}.htr-reports-time>span:not(:first-child){margin-top:10px}
.htr-reports-pagination{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding:18px 24px;color:#646970;font-size:12px}.htr-reports-pagination nav{display:flex;gap:5px;flex-wrap:wrap}.htr-reports-pagination .page-numbers{padding:5px 10px;border:1px solid #dce2e9;border-radius:5px;text-decoration:none;background:#fff}.htr-reports-pagination .page-numbers.current{background:#2271b1;border-color:#2271b1;color:#fff}.htr-reports-empty{padding:38px 16px;text-align:center;color:#646970}.htr-reports-empty>.dashicons{font-size:30px;width:30px;height:30px;color:#8d9aa8}.htr-reports-empty h3{margin:14px 0 6px;font-size:15px;color:#3c434a}.htr-reports-empty p{margin:0;font-size:12px}.htr-reports-maintenance{margin:24px 0;color:#646970;font-size:12px}.htr-reports-maintenance summary{cursor:pointer}.htr-reports-maintenance p{font-size:12px}.htr-reports-maintenance button{color:#a63727;border-color:#dfc7c3;border-radius:5px}
@media(max-width:900px){.htr-reports-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.htr-reports-heading{align-items:flex-start;flex-direction:column}.htr-reports-stats{gap:10px}.htr-reports-stat{padding:16px}.htr-reports-panel__heading{padding:18px 16px 0}.htr-reports-filters{padding:16px}.htr-reports-filters label{width:100%}.htr-reports-filters input{width:100%}.htr-reports-pagination{padding:16px}}
</style>
HTML;
}
