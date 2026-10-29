<?php
/** Run with PHP CLI. Isolated WordPress doubles: no network or database mutations. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MB_IN_BYTES', 1048576);
$options = $hooks = $shortcodes = [];
$http_calls = 0;
$http_response = [];
class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
class TestDatabase {
    public $prefix = 'wp_'; public $events = [];
    public function insert($table, $row) { $this->events[] = $row; return 1; }
    public function update($table, $values, $where) {
        foreach ($this->events as &$row) { if ($row['rule_key'] === $where['rule_key']) { $row = array_merge($row, $values); } }
        return 1;
    }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) { $sql = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . addslashes($arg) . "'", $sql, 1); }
        return $sql;
    }
    public function get_var($sql) { return count($this->events); }
    public function get_results($sql, $format) { $GLOBALS['report_sql'] = $sql; return array_slice(array_reverse($this->events), 0, 25); }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
}
$wpdb = new TestDatabase();
function add_action($hook, $callback, $priority = 10) {
    $GLOBALS['hooks'][$hook][] = $callback;
    $GLOBALS['prioritized_hooks'][$hook][$priority][] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['filters'][$hook] = $callback; }
function get_user_option($option) { return $GLOBALS['user_options'][$option] ?? false; }
function add_screen_option($name, $args) { $GLOBALS['screen_options'][$name] = $args; }
function add_menu_page($title, $label, $capability, $slug, $callback, $icon, $position) {
    $GLOBALS['menus'][$slug] = true;
    return 'toplevel_page_' . $slug;
}
function add_submenu_page($parent, $title, $label, $capability, $slug, $callback) {
    check(! empty($GLOBALS['menus'][$parent]), 'Parent menu exists before submenu registration');
    $GLOBALS['submenus'][$slug] = $callback;
}
function add_shortcode($name, $callback) { $GLOBALS['shortcodes'][$name] = $callback; }
function register_activation_hook($path, $callback) { $GLOBALS['activation'] = $callback; }
function register_deactivation_hook($path, $callback) { $GLOBALS['deactivation'] = $callback; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$key])) { return false; } return update_option($key, $value); }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function esc_url_raw($s, $protocols = null) { return (string) $s; }
function wp_http_validate_url($s) { return filter_var($s, FILTER_VALIDATE_URL); }
function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
function wp_unslash($s) { return $s; }
function wp_json_encode($s, $flags = 0) { return json_encode($s, $flags); }
function current_time($type, $gmt = false) { return $type === 'Y-m-d' ? '2026-10-29' : '2026-10-29 12:00:00'; }
function apply_filters($name, $value) { return $value; }
function wp_parse_url($s, $component = -1) { return parse_url($s, $component); }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_safe_remote_get($url, $args) { $GLOBALS['http_calls']++; $GLOBALS['http_args'] = $args; return $GLOBALS['http_response']; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_event($when, $interval, $hook) { $GLOBALS['scheduled'][$hook] = $when; $GLOBALS['interval'] = $interval; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['scheduled'][$hook]); }
function current_user_can($capability) { return $GLOBALS['allowed'] ?? false; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message); }
function check_admin_referer($action) { throw new RuntimeException('Nonce required'); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_textarea($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function checked($a, $b = true, $echo = true) { $s = $a == $b ? 'checked' : ''; if ($echo) { echo $s; } return $s; }
function selected($a, $b = true, $echo = true) { $s = $a == $b ? 'selected' : ''; if ($echo) { echo $s; } return $s; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="fixture-nonce">'; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'fixture-nonce' && in_array($action, ['hometown_announcement_example', 'hometown_announcement_rule_preview'], true); }
function wp_nonce_url($url, $action, $name) { return $url . '&' . $name . '=fixture-nonce'; }
function wp_enqueue_script(...$args) { $GLOBALS['enqueued_scripts'][] = $args; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/hometown-location-announcement-bar/'; }
function submit_button($label, $type = '') { echo '<button>' . esc_html($label) . '</button>'; }
function absint($number) { return abs((int) $number); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_kses_post($s) { check(is_string($s), 'HTML sanitizer receives a string'); return $s; }
function paginate_links($args) { $GLOBALS['pagination_args'] = $args; return null; }
define('ARRAY_A', 'ARRAY_A');

require dirname(__DIR__) . '/hometown-location-announcement-bar.php';
$checks = 0;
function check($condition, $message) { $GLOBALS['checks']++; if (! $condition) { throw new RuntimeException($message); } }
function snapshot($locations) { return ['ok' => true, 'locations' => $locations]; }
ksort($prioritized_hooks['admin_menu']);
foreach ($prioritized_hooks['admin_menu'] as $callbacks) {
    foreach ($callbacks as $callback) { $callback(); }
}
check(isset($submenus['hometown-announcement-api'], $submenus['hometown-announcement-reports']), 'Both submenu pages registered');
foreach ($hooks['load-toplevel_page_hometown-announcements'] as $callback) { $callback(); }
check($screen_options['per_page']['option'] === 'hometown_announcement_rules_per_page', 'Screen Options registers rules per page');
check(hometown_announcement_rules_per_page() === 6, 'Default screen preference is six rules');
$user_options['hometown_announcement_rules_per_page'] = 12;
check(hometown_announcement_rules_per_page() === 12, 'User screen preference controls page size');
$screen_saver = $filters['set_screen_option_hometown_announcement_rules_per_page'];
check($screen_saver(false, 'hometown_announcement_rules_per_page', 999) === 100, 'Screen preference caps at 100');
check($screen_saver(false, 'hometown_announcement_rules_per_page', 0) === 1, 'Screen preference requires positive count');
unset($user_options['hometown_announcement_rules_per_page']);
function review_entry($key, $decision, $rule = null) {
    $entry = get_option(HTR_API_RULES)[$key];
    return hometown_api_review($key, $entry['revision'], $decision, $rule ?? $entry['rule']);
}
$boerne = ['match_type' => 'city', 'state' => 'TX', 'city' => 'Boerne', 'message' => 'Recent hail in Boerne'];
check(hometown_api_import(snapshot([$boerne])) === 1, 'Import Boerne');
$key = array_key_first(get_option(HTR_API_RULES));
check(get_option(HTR_API_RULES)[$key]['status'] === 'approved', 'New entries auto-approved');
check(count(hometown_announcement_api_active_rules()) === 1, 'Imported rules automatically active');
check(review_entry($key, 'approved') === true, 'Approve rule');
check(count(hometown_announcement_api_active_rules()) === 1, 'Approved rule included');
$edited = get_option(HTR_API_RULES)[$key]['rule']; $edited['message'] = 'Administrator text';
check(review_entry($key, 'approved', $edited) === true, 'Approve edited rule');
$before = count($wpdb->events);
hometown_api_import(snapshot([$boerne, $boerne]));
check(count($wpdb->events) === $before, 'Unchanged and duplicate imports add no history');
check(get_option(HTR_API_RULES)[$key]['rule']['message'] === 'Administrator text', 'Unchanged source preserves edits');
check(get_option(HTR_API_RULES)[$key]['status'] === 'approved', 'Unchanged source preserves approval');
$stale_revision = get_option(HTR_API_RULES)[$key]['revision'];
$boerne['message'] = 'Updated storm';
hometown_api_import(snapshot([$boerne]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'approved', 'Changed source auto-approved');
check(get_option(HTR_API_RULES)[$key]['rule']['message'] === 'Updated storm', 'Updated API content shown');
check(is_wp_error(hometown_api_review($key, $stale_revision, 'approved', $edited)), 'Stale approval rejected');
review_entry($key, 'approved');
$before = get_option(HTR_API_RULES);
check(is_wp_error(hometown_api_import(['ok' => false, 'locations' => []])), 'ok false rejected');
check(is_wp_error(hometown_api_import(snapshot([['match_type' => 'city']]))), 'Malformed location rejected');
check(get_option(HTR_API_RULES) === $before, 'Invalid snapshot preserves rules');
hometown_api_import(snapshot([]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'inactive', 'Empty snapshot deactivates');
check(hometown_announcement_api_active_rules() === [], 'Missing rule excluded');
check(is_wp_error(review_entry($key, 'approved')), 'Missing rule cannot be approved');
hometown_api_import(snapshot([$boerne]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'approved', 'Returned rule auto-approved within original expiration');
$conflicting = $boerne; $conflicting['message'] = 'Conflicting alert';
hometown_api_import(snapshot([$boerne, $conflicting]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'conflict', 'Conflicting identity flagged');
check(is_wp_error(review_entry($key, 'approved')), 'Conflict approval blocked');
$before = count($wpdb->events);
hometown_api_import(snapshot([$conflicting, $boerne]));
check(count($wpdb->events) === $before, 'Conflict order stable');
hometown_api_import(snapshot([$boerne]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'approved', 'Resolved conflict auto-approved');
review_entry($key, 'rejected'); hometown_api_import(snapshot([$boerne]));
check(get_option(HTR_API_RULES)[$key]['status'] === 'rejected', 'Reject persists unchanged');
review_entry($key, 'disabled'); hometown_api_import(snapshot([$boerne]));
check(hometown_announcement_api_active_rules() === [], 'Disabled rule excluded');

$state = ['match_type' => 'state', 'state' => 'tx', 'message' => 'Texas storm'];
$url = ['match_type' => 'url', 'url_slug' => 'boerne', 'message' => 'URL storm'];
check(! is_wp_error(hometown_api_map_location($state)), 'State mapping');
check(! is_wp_error(hometown_api_map_location($url)), 'URL mapping');
check(is_wp_error(hometown_api_map_location(array_merge($boerne, ['state' => 'XX']))), 'Invalid state rejected');
check(is_wp_error(hometown_api_map_location(array_merge($boerne, ['schedule_type' => 'dates', 'start_date' => '2026-02-30']))), 'Invalid date rejected');
check(is_wp_error(hometown_api_map_location(array_merge($boerne, ['schedule_type' => 'dates', 'start_date' => '2026-11-02', 'end_date' => '2026-11-01']))), 'Reversed date range rejected');
check(! hometown_announcement_is_active(['schedule_type' => 'dates', 'start_date' => '2026-11-01']), 'Future schedule excluded');
check(! hometown_announcement_is_active(['schedule_type' => 'dates', 'end_date' => '2026-10-28']), 'Expired schedule excluded');
check(hometown_announcement_is_active(['schedule_type' => 'dates', 'start_date' => '2026-10-29', 'end_date' => '2026-10-29']), 'Inclusive schedule boundary');

update_option(HTR_API_RULES, []);
hometown_api_import(snapshot([$state, $url, $boerne]));
foreach (get_option(HTR_API_RULES) as $k => $entry) { review_entry($k, 'approved'); }
$_SERVER['REQUEST_URI'] = '/other/'; $_SERVER['HTTP_CF_IPCITY'] = 'Boerne'; $_SERVER['HTTP_CF_REGION_CODE'] = 'TX';
$settings = hometown_announcement_defaults();
$manual = hometown_api_map_location(array_merge($boerne, ['message' => 'Manual city']));
$settings['locations'] = [$manual]; update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_get_current_announcement()['message'] === 'Manual city', 'Manual city precedes API city');
$settings['locations'] = [hometown_api_map_location(array_merge($state, ['message' => 'Manual state']))];
update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_get_current_announcement()['message'] === 'Updated storm', 'API city precedes manual state');
check(hometown_get_current_announcement()['cta_url'] === '/get-a-quote/', 'Blank CTA inherits global');
$_SERVER['REQUEST_URI'] = '/trusted/boerne/';
check(hometown_get_current_announcement()['message'] === 'URL storm', 'URL precedes city');
$settings['locations'] = [hometown_api_map_location(array_merge($url, ['message' => 'Manual URL']))]; update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_get_current_announcement()['message'] === 'Manual URL', 'Manual URL precedes API URL');
$_SERVER['REQUEST_URI'] = '/other/'; $_SERVER['HTTP_CF_IPCITY'] = 'Austin';
$settings['locations'] = []; update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_get_current_announcement()['message'] === 'Texas storm', 'State fallback');
$_SERVER['HTTP_CF_REGION_CODE'] = 'CA';
check(hometown_get_current_announcement()['type'] === 'global', 'Global fallback');
$settings['enabled'] = 0; update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_get_current_announcement() === null, 'Master switch');
check($http_calls === 0, 'Frontend matching makes no HTTP calls');

check(hometown_api_save_config(['url' => 'https://example.test/api', 'username' => 'test', 'password' => 'fixture-secret']), 'Save credentials');
check(hometown_api_save_config(['url' => 'https://example.test/api', 'username' => 'test', 'password' => '']), 'Blank password save');
check(get_option(HTR_API_CONFIG)['password'] === 'fixture-secret', 'Blank preserves saved password');
check(! hometown_api_save_config(['url' => 'http://example.test/api', 'username' => 'test', 'password' => '']), 'HTTP endpoint rejected');
check(! hometown_api_save_config(['url' => 'https://user:secret@example.test/api', 'username' => 'test', 'password' => '']), 'Embedded endpoint credentials rejected');
foreach ($hooks['init'] as $callback) { $callback(); }
check($interval === 'hourly' && isset($scheduled[HTR_API_CRON]), 'Hourly scheduled');
$http_response = ['response' => ['code' => 200], 'body' => json_encode(snapshot([$boerne]))];
check(hometown_api_fetch() === 1, 'Successful fetch');
check($http_args['redirection'] === 0, 'Authenticated redirect disabled');
check($http_args['headers']['Authorization'] === 'Basic ' . base64_encode('test:fixture-secret'), 'Basic authentication');
$before = get_option(HTR_API_RULES);
foreach ([new WP_Error('timeout', 'sensitive detail'), ['response' => ['code' => 401], 'body' => 'secret'], ['response' => ['code' => 200], 'body' => '{broken'], ['response' => ['code' => 200], 'body' => '{"ok":true,"locations":{}}']] as $failure) {
    $http_response = $failure;
    check(is_wp_error(hometown_api_fetch()), 'Failed fetch rejected');
    check(get_option(HTR_API_RULES) === $before, 'Failed fetch preserves last snapshot');
}
check(strpos(json_encode(get_option(HTR_API_SYNC)), 'sensitive detail') === false, 'Transport details excluded');
check(strpos(json_encode($wpdb->events), 'fixture-secret') === false, 'Credentials excluded from history');
check(! get_option('hometown_announcement_api_lock'), 'Lock released on failure');
add_option('hometown_announcement_api_lock', time() + 60);
check(is_wp_error(hometown_api_fetch()), 'Concurrent sync blocked'); delete_option('hometown_announcement_api_lock');
$deactivation(); check(! isset($scheduled[HTR_API_CRON]), 'Deactivation clears cron');

$handler = $hooks['admin_post_hometown_announcement_api'][0];
try { $handler(); check(false, 'Unauthorized action must fail'); }
catch (RuntimeException $error) { check($error->getMessage() === 'Not authorized.', 'Action requires manage_options'); }
$allowed = true;
try { $handler(); check(false, 'Action without nonce must fail'); }
catch (RuntimeException $error) { check($error->getMessage() === 'Nonce required', 'Action requires nonce before changes'); }
ob_start(); hometown_api_admin_page(); $html = ob_get_clean();
check(strpos($html, 'fixture-secret') === false, 'Saved password never rendered');
check(strpos($html, 'type="password" value=""') !== false, 'Blank masked password field');
check(strpos($html, 'name="_wpnonce"') !== false, 'Forms include nonce');
check(strpos($html, 'Save and enable') !== false && strpos($html, 'Reject') !== false, 'Review actions render');
check(strpos($html, 'hometown_announcement_settings[locations][0][message]') !== false, 'Existing rule editor reused');
$before_options = $options; $before_events = $wpdb->events; $before_http = $http_calls;
ob_start(); hometown_api_example_preview(); $preview = ob_get_clean();
check(strpos($preview, 'Mapping successful') !== false && strpos($preview, 'Boerne, TX') !== false, 'Example response maps to Boerne rule');
check(strpos($preview, '<input') === false && strpos($preview, '<textarea') === false, 'Example cards contain no editable fields');
check($options === $before_options && $wpdb->events === $before_events, 'Example preview preserves manual/API rules, sync status and history');
check($http_calls === $before_http, 'Example preview makes no webhook request');
$wpdb->events[0]['message'] = '<script>unsafe</script>';
$_GET = ['status' => 'pending', 'location' => 'Boerne'];
ob_start(); hometown_api_reports_page(); $html = ob_get_clean();
check(strpos($html, '<script>unsafe</script>') === false, 'Report text escaped');
check(strpos($html, 'rule-' . $key) !== false, 'History links to review rule');
check(strpos($report_sql, "status = 'pending'") !== false && strpos($report_sql, 'location LIKE') !== false, 'Report filters applied to query');
check(strpos($report_sql, 'LIMIT 25 OFFSET 0') !== false, 'Reports paginate');
check(strpos($pagination_args['base'], '%#%') !== false && strpos($pagination_args['base'], 'location=Boerne') !== false, 'Pagination retains filters and replacement token');
$_GET = [];
$allowed = false;
ob_start(); hometown_api_admin_page(); hometown_api_reports_page(); $html = ob_get_clean();
check($html === '', 'Admin pages reject unauthorized access');
$expiry_entries = get_option(HTR_API_RULES);
$expiry_key = hometown_api_identity($boerne, hometown_api_map_location($boerne));
$expiry_entry = $expiry_entries[$expiry_key];
$expiry_entries = [$expiry_key => $expiry_entry];
check(hometown_api_expires_at($expiry_entry) === strtotime($expiry_entry['first_detected'] . ' UTC') + 14 * 86400, 'Expiration is 14 days after first import');
$expiry_entries[$expiry_key]['expires_at'] = strtotime('2026-10-29 12:00:00 UTC');
$expiry_entries[$expiry_key]['status'] = 'approved';
update_option(HTR_API_RULES, $expiry_entries);
check(hometown_announcement_api_active_rules() === [], 'Expiration enforced at exact boundary without cron');
check(is_wp_error(review_entry($expiry_key, 'approved')), 'Manual enabling cannot bypass expiration');
$events_before_expiration = count($wpdb->events);
hometown_api_expire_rules();
check(get_option(HTR_API_RULES)[$expiry_key]['status'] === 'expired', 'Expiration stored for reports');
check(count($wpdb->events) === $events_before_expiration + 1, 'Expiration recorded in history');
hometown_api_expire_rules();
check(count($wpdb->events) === $events_before_expiration + 1, 'Expiration history not duplicated');
$original_expiry = get_option(HTR_API_RULES)[$expiry_key]['expires_at'];
hometown_api_import(snapshot([$boerne]));
check(get_option(HTR_API_RULES)[$expiry_key]['expires_at'] === $original_expiry, 'Repeated fetch does not extend expiration');
check(hometown_announcement_api_active_rules() === [], 'Expired source cannot reactivate on unchanged fetch');
$boerne['message'] = 'Changed after expiration';
hometown_api_import(snapshot([$boerne]));
check(hometown_announcement_api_active_rules() === [], 'Source changes cannot extend expiration');
update_option(HTR_API_RULES, []);
$named_locations = hometown_api_example_response()['locations'];
$manual_before = get_option(HOMETOWN_ANNOUNCEMENT_OPTION);
check(hometown_api_import(snapshot($named_locations)) === 5, 'Five named storm locations imported');
check(count(hometown_announcement_api_active_rules()) === 5, 'Named locations automatically active');
$named_rules = hometown_announcement_api_active_rules();
check($named_rules[0]['city'] === 'San Antonio' && $named_rules[0]['state'] === 'TX', 'Name mapped to Texas city');
check($named_rules[0]['message'] === $manual_before['default']['message'], 'Named location uses global message');
check($named_rules[0]['cta_label'] === '' && $named_rules[0]['cta_url'] === '', 'Named location inherits global CTA');
check(get_option(HOMETOWN_ANNOUNCEMENT_OPTION) === $manual_before, 'Import preserves manual settings');
$named_before = get_option(HTR_API_RULES);
$named_event_count = count($wpdb->events);
$named_locations[0]['lat'] = 30; $named_locations[0]['radius'] = 100;
hometown_api_import(snapshot($named_locations));
check(get_option(HTR_API_RULES) === $named_before, 'Coordinates and radius do not affect city rules');
$settings = $manual_before; $settings['enabled'] = 1; $settings['default']['message'] = 'New global storm message';
$settings['default']['cta_label'] = 'Global CTA';
update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(hometown_announcement_api_active_rules()[0]['message'] === 'New global storm message', 'Global message updates without fetching');
hometown_api_import($named_locations);
check(count($wpdb->events) === $named_event_count, 'Global changes and raw list create no new source events');
$http_response = ['response' => ['code' => 200], 'body' => json_encode($named_locations)];
check(hometown_api_fetch() === 5, 'Fetch accepts root JSON locations array');
check(is_wp_error(hometown_api_map_location(['name' => ['invalid'] ])), 'Malformed name rejected');
$_SERVER['REQUEST_URI'] = '/other/'; $_SERVER['HTTP_CF_IPCITY'] = 'Austin'; $_SERVER['HTTP_CF_REGION_CODE'] = 'TX';
check(hometown_get_current_announcement()['message'] === 'New global storm message', 'Named city renders global message');
check(hometown_get_current_announcement()['cta_label'] === 'Global CTA', 'Named city renders global CTA');
$preview_options = $options; $preview_events = $wpdb->events; $preview_requests = $http_calls;
$_GET = ['htr_announcement_example' => '1', '_htr_preview_nonce' => 'fixture-nonce'];
$allowed = false;
check(hometown_api_frontend_example() === null, 'Visitor cannot activate website example');
$allowed = true; $_GET['_htr_preview_nonce'] = 'invalid';
check(hometown_api_frontend_example() === null, 'Invalid nonce cannot activate website example');
$_GET['_htr_preview_nonce'] = 'fixture-nonce'; $_GET['htr_announcement_example'] = '99';
check(hometown_api_frontend_example() === null, 'Unknown example city rejected');
$_GET['htr_announcement_example'] = '1';
check(hometown_get_current_announcement()['type'] === 'api-example', 'Authorized preview uses announcement selection');
$render_bar = $shortcodes['hometown_announcement'];
$bar_html = $render_bar();
check(strpos($bar_html, 'site-header__announcement') !== false && strpos($bar_html, 'New global storm message') !== false, 'Example renders through production shortcode');
check($options === $preview_options && $wpdb->events === $preview_events && $http_calls === $preview_requests, 'Website example leaves saved rules, reports and API untouched');
$_GET = []; $allowed = false;
$settings = hometown_announcement_get_settings();
$settings['locations'] = [hometown_api_map_location(['match_type' => 'city', 'state' => 'TX', 'city' => 'Boerne', 'message' => 'Saved manual preview'])];
update_option(HOMETOWN_ANNOUNCEMENT_OPTION, $settings);
check(strpos(hometown_manual_preview_links()[0], 'htr_announcement_rule=0') !== false, 'Manual preview creates signed website link');
$_GET = ['htr_announcement_rule' => '0', '_htr_preview_nonce' => 'fixture-nonce'];
check(hometown_manual_preview_index() === null, 'Manual website preview denies visitors');
$allowed = true;
check(hometown_get_current_announcement()['message'] === 'Saved manual preview', 'Manual link previews saved rule through announcement selection');
$_GET['_htr_preview_nonce'] = 'invalid';
check(hometown_manual_preview_index() === null, 'Manual preview rejects invalid nonce');
$_GET = []; $allowed = false;
$export_handler = $hooks['admin_post_hometown_announcement_export'][0];
try { $export_handler(); check(false, 'Unauthorized export must fail'); }
catch (RuntimeException $error) { check($error->getMessage() === 'Not authorized.', 'Export requires administrator capability'); }
$allowed = true;
try { $export_handler(); check(false, 'Export without nonce must fail'); }
catch (RuntimeException $error) { check($error->getMessage() === 'Nonce required', 'Export verifies nonce'); }
$csv_fixture = ['id' => 42, 'location' => 'Boerne, TX', 'message' => "Hail, wind and \"rain\"\nSecond line", 'password' => 'never-export-this'];
$csv_cells = hometown_api_csv_row($csv_fixture);
$csv_stream = fopen('php://temp', 'w+');
fputcsv($csv_stream, $csv_cells, ',', '"', ''); rewind($csv_stream);
check(fgetcsv($csv_stream, 0, ',', '"', '') === $csv_cells, 'CSV round trip preserves commas, quotes and newlines'); fclose($csv_stream);
check(strpos(json_encode($csv_cells), 'never-export-this') === false, 'Export uses explicit safe column allowlist');
foreach (['=1+1', '+SUM(A1)', '-1+2', '@SUM(A1)', "\t=1+1", '  =1+1'] as $formula) {
    check(hometown_api_csv_row(['message' => $formula])[3] === "'" . $formula, 'CSV neutralizes spreadsheet formulas');
}
check(hometown_api_report_where('approved', 'Boerne') === "WHERE 1=1 AND status = 'approved' AND location LIKE '%Boerne%'", 'Export and reports share filter query');
ob_start(); hometown_api_reports_page(); $export_html = ob_get_clean();
check(strpos($export_html, 'hometown_announcement_export') !== false && strpos($export_html, 'Export CSV') !== false, 'Reports renders export action');
$allowed = false;
echo "Passed $checks checks (isolated WordPress doubles; no live requests).\n";
