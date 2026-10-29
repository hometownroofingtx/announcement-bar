<?php

/**
 * Plugin Name: Hometown Announcement Bar
 * Description: Location-aware announcement bar with URL, city, state and global matching.
 * Version: 2.3.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define(
    'HOMETOWN_ANNOUNCEMENT_OPTION',
    'hometown_announcement_settings'
);

require_once __DIR__ . '/includes/api-locations.php';


/**
 * =========================================================
 * ADMIN MENU
 * =========================================================
 */

add_action('admin_menu', function () {

    $screen_hook = add_menu_page(
        'Announcement Bar Settings',
        'Announcement Bars',
        'manage_options',
        'hometown-announcements',
        'hometown_announcement_admin_page',
        'dashicons-megaphone',
        60
    );

    add_action('load-' . $screen_hook, function () {
        add_screen_option('per_page', [
            'label' => 'Location Rules per page',
            'default' => 6,
            'option' => 'hometown_announcement_rules_per_page',
        ]);
    });
});

add_filter('set_screen_option_hometown_announcement_rules_per_page', function ($status, $option, $value) {
    return max(1, min(100, (int) $value));
}, 10, 3);

function hometown_announcement_rules_per_page() {
    $value = get_user_option('hometown_announcement_rules_per_page');
    return $value === false || $value === '' ? 6 : max(1, min(100, (int) $value));
}


/**
 * =========================================================
 * REGISTER SETTINGS
 * =========================================================
 */

add_action('admin_init', function () {

    register_setting(
        'hometown_announcement_group',
        HOMETOWN_ANNOUNCEMENT_OPTION,
        [
            'type'              => 'array',
            'sanitize_callback' => 'hometown_announcement_sanitize',
            'default'           => [],
        ]
    );
});


/**
 * =========================================================
 * STATES
 * =========================================================
 */

function hometown_get_states()
{
    return [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];
}


/**
 * =========================================================
 * DEFAULTS
 * =========================================================
 */

function hometown_announcement_defaults()
{
    return [
        'enabled' => 1,

        'default' => [
            'title'     => 'STORM DAMAGE ALERT',
            'message'   => 'Recent hail and high winds reported in parts of Texas.',
            'cta_label' => 'Schedule a Free Roof Inspection',
            'cta_url'   => '/get-a-quote/',
        ],

        'locations' => [],
    ];
}


/**
 * =========================================================
 * GET SETTINGS
 * =========================================================
 */

function hometown_announcement_get_settings()
{
    $saved = get_option(
        HOMETOWN_ANNOUNCEMENT_OPTION,
        []
    );

    return wp_parse_args(
        $saved,
        hometown_announcement_defaults()
    );
}


/**
 * =========================================================
 * SANITIZE DATE
 * =========================================================
 */

function hometown_sanitize_date($date)
{
    $date = sanitize_text_field($date);

    if (! $date) {
        return '';
    }

    $parsed = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    if (
        ! $parsed ||
        $parsed->format('Y-m-d') !== $date
    ) {
        return '';
    }

    return $date;
}


/**
 * =========================================================
 * SANITIZE SETTINGS
 * =========================================================
 */

function hometown_announcement_sanitize($input)
{
    $output = [];

    $output['enabled'] =
        ! empty($input['enabled'])
        ? 1
        : 0;


    $output['default'] = [
        'title' => sanitize_text_field(
            $input['default']['title'] ?? ''
        ),

        'message' => sanitize_textarea_field(
            $input['default']['message'] ?? ''
        ),

        'cta_label' => sanitize_text_field(
            $input['default']['cta_label'] ?? ''
        ),

        'cta_url' => esc_url_raw(
            $input['default']['cta_url'] ?? ''
        ),
    ];


    $output['locations'] = [];

    if (
        ! empty($input['locations']) &&
        is_array($input['locations'])
    ) {

        foreach ($input['locations'] as $location) {

            $match_type = sanitize_key(
                $location['match_type'] ?? 'state'
            );

            if (
                ! in_array(
                    $match_type,
                    ['url', 'city', 'state'],
                    true
                )
            ) {
                $match_type = 'state';
            }


            $state = strtoupper(
                sanitize_text_field(
                    $location['state'] ?? ''
                )
            );

            $city = sanitize_text_field(
                $location['city'] ?? ''
            );

            $url_slug = sanitize_title(
                $location['url_slug'] ?? ''
            );


            if (
                $match_type === 'url' &&
                ! $url_slug
            ) {
                continue;
            }

            if (
                $match_type === 'state' &&
                ! $state
            ) {
                continue;
            }

            if (
                $match_type === 'city' &&
                ! $city
            ) {
                continue;
            }


            $schedule_type =
                ($location['schedule_type'] ?? 'always') === 'dates'
                ? 'dates'
                : 'always';


            $output['locations'][] = [
                'enabled' =>
                ! empty($location['enabled'])
                    ? 1
                    : 0,

                'match_type' =>
                $match_type,

                'url_slug' =>
                $url_slug,

                'city' =>
                $city,

                'state' =>
                $state,

                'message' =>
                sanitize_textarea_field(
                    $location['message'] ?? ''
                ),

                'cta_label' =>
                sanitize_text_field(
                    $location['cta_label'] ?? ''
                ),

                'cta_url' =>
                esc_url_raw(
                    $location['cta_url'] ?? ''
                ),

                'schedule_type' =>
                $schedule_type,

                'start_date' =>
                hometown_sanitize_date(
                    $location['start_date'] ?? ''
                ),

                'end_date' =>
                hometown_sanitize_date(
                    $location['end_date'] ?? ''
                ),
            ];
        }
    }

    return $output;
}


/**
 * =========================================================
 * SCHEDULE CHECK
 * =========================================================
 */

function hometown_announcement_is_active($rule)
{
    $schedule_type =
        $rule['schedule_type'] ?? 'always';

    if ($schedule_type !== 'dates') {
        return true;
    }

    $today =
        current_time('Y-m-d');

    $start =
        $rule['start_date'] ?? '';

    $end =
        $rule['end_date'] ?? '';


    if (
        $start &&
        $today < $start
    ) {
        return false;
    }

    if (
        $end &&
        $today > $end
    ) {
        return false;
    }

    return true;
}


/**
 * =========================================================
 * CURRENT PATH
 * =========================================================
 */

function hometown_get_current_path()
{
    $request_uri =
        $_SERVER['REQUEST_URI'] ?? '/';

    $path =
        wp_parse_url(
            $request_uri,
            PHP_URL_PATH
        );

    return trim(
        (string) $path,
        '/'
    );
}


/**
 * =========================================================
 * VISITOR GEOLOCATION
 * =========================================================
 *
 * Uses Cloudflare headers when available.
 */

function hometown_get_visitor_location()
{
    $city = '';
    $state = '';
    $state_name = '';
    $country = '';


    if (! empty($_SERVER['HTTP_CF_IPCITY'])) {

        $city =
            sanitize_text_field(
                wp_unslash(
                    $_SERVER['HTTP_CF_IPCITY']
                )
            );
    }


    if (! empty($_SERVER['HTTP_CF_REGION_CODE'])) {

        $state =
            strtoupper(
                sanitize_text_field(
                    wp_unslash(
                        $_SERVER['HTTP_CF_REGION_CODE']
                    )
                )
            );
    }


    if (! empty($_SERVER['HTTP_CF_REGION'])) {

        $state_name =
            sanitize_text_field(
                wp_unslash(
                    $_SERVER['HTTP_CF_REGION']
                )
            );
    }


    if (! empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {

        $country =
            strtoupper(
                sanitize_text_field(
                    wp_unslash(
                        $_SERVER['HTTP_CF_IPCOUNTRY']
                    )
                )
            );
    }


    /**
     * Development-only test override.
     *
     * Example:
     * ?hometown_test_city=Houston&hometown_test_state=TX
     */
    if (
        defined('WP_DEBUG') &&
        WP_DEBUG &&
        current_user_can('manage_options')
    ) {
        if (! empty($_GET['hometown_test_city'])) {
            $city =
                sanitize_text_field(
                    wp_unslash(
                        $_GET['hometown_test_city']
                    )
                );
        }

        if (! empty($_GET['hometown_test_state'])) {
            $state =
                strtoupper(
                    sanitize_text_field(
                        wp_unslash(
                            $_GET['hometown_test_state']
                        )
                    )
                );
        }
    }


    return apply_filters(
        'hometown_announcement_visitor_location',
        [
            'city'       => $city,
            'state'      => $state,
            'state_name' => $state_name,
            'country'    => $country,
        ]
    );
}


/**
 * =========================================================
 * BUILD ANNOUNCEMENT
 * =========================================================
 */

function hometown_build_announcement(
    $rule,
    $default,
    $type = ''
) {
    return [
        'type' => $type,

        'title' =>
        $default['title'] ?? '',

        'message' =>
        $rule['message'] ?? '',

        'cta_label' =>
        ! empty($rule['cta_label'])
            ? $rule['cta_label']
            : ($default['cta_label'] ?? ''),

        'cta_url' =>
        ! empty($rule['cta_url'])
            ? $rule['cta_url']
            : ($default['cta_url'] ?? ''),
    ];
}


/**
 * =========================================================
 * GET CURRENT ANNOUNCEMENT
 * =========================================================
 *
 * Priority:
 *
 * 1. URL
 * 2. City
 * 3. State
 * 4. Global
 */

function hometown_get_current_announcement()
{
    $settings =
        hometown_announcement_get_settings();

    $example = hometown_api_frontend_example();
    if ($example !== null) { return $example; }

    if (empty($settings['enabled'])) {
        return null;
    }

    $default =
        $settings['default'] ?? [];

    $rules =
        $settings['locations'] ?? [];

    // Keep specificity precedence, with manual rules first within each type.
    $rules = array_merge($rules, hometown_announcement_api_active_rules());


    /**
     * 1. URL
     */

    $path =
        hometown_get_current_path();

    foreach ($rules as $rule) {

        if (empty($rule['enabled'])) {
            continue;
        }

        if (
            ($rule['match_type'] ?? '')
            !== 'url'
        ) {
            continue;
        }

        if (
            ! hometown_announcement_is_active(
                $rule
            )
        ) {
            continue;
        }

        $slug =
            sanitize_title(
                $rule['url_slug'] ?? ''
            );

        if (! $slug) {
            continue;
        }

        if (
            preg_match(
                '#(^|/)' .
                    preg_quote(
                        $slug,
                        '#'
                    ) .
                    '(/|$)#i',
                $path
            )
        ) {

            return hometown_build_announcement(
                $rule,
                $default,
                'url'
            );
        }
    }


    /**
     * GEO
     */

    $visitor =
        hometown_get_visitor_location();

    $visitor_city =
        strtolower(
            trim(
                $visitor['city'] ?? ''
            )
        );

    $visitor_state =
        strtoupper(
            trim(
                $visitor['state'] ?? ''
            )
        );


    /**
     * 2. CITY
     */

    if ($visitor_city) {

        foreach ($rules as $rule) {

            if (empty($rule['enabled'])) {
                continue;
            }

            if (
                ($rule['match_type'] ?? '')
                !== 'city'
            ) {
                continue;
            }

            if (
                ! hometown_announcement_is_active(
                    $rule
                )
            ) {
                continue;
            }


            $rule_city =
                strtolower(
                    trim(
                        $rule['city'] ?? ''
                    )
                );

            $rule_state =
                strtoupper(
                    trim(
                        $rule['state'] ?? ''
                    )
                );


            if (
                $rule_city !==
                $visitor_city
            ) {
                continue;
            }

            if (
                $rule_state &&
                $visitor_state &&
                $rule_state !==
                $visitor_state
            ) {
                continue;
            }

            return hometown_build_announcement(
                $rule,
                $default,
                'city'
            );
        }
    }


    /**
     * 3. STATE
     */

    if ($visitor_state) {

        foreach ($rules as $rule) {

            if (empty($rule['enabled'])) {
                continue;
            }

            if (
                ($rule['match_type'] ?? '')
                !== 'state'
            ) {
                continue;
            }

            if (
                ! hometown_announcement_is_active(
                    $rule
                )
            ) {
                continue;
            }


            $rule_state =
                strtoupper(
                    trim(
                        $rule['state'] ?? ''
                    )
                );


            if (
                ! $rule_state ||
                $rule_state !==
                $visitor_state
            ) {
                continue;
            }


            return hometown_build_announcement(
                $rule,
                $default,
                'state'
            );
        }
    }


    /**
     * 4. GLOBAL
     */

    if (
        empty($default['message'])
    ) {
        return null;
    }


    return [
        'type' => 'global',

        'title' =>
        $default['title'] ?? '',

        'message' =>
        $default['message'] ?? '',

        'cta_label' =>
        $default['cta_label'] ?? '',

        'cta_url' =>
        $default['cta_url'] ?? '',
    ];
}


/**
 * =========================================================
 * NORMALIZE URL
 * =========================================================
 */

function hometown_announcement_url($url)
{
    $url =
        trim(
            (string) $url
        );

    if (! $url) {
        return '';
    }

    if (
        preg_match(
            '#^https?://#i',
            $url
        )
    ) {
        return $url;
    }

    return home_url(
        '/' . ltrim(
            $url,
            '/'
        )
    );
}


/**
 * =========================================================
 * SHORTCODE
 * =========================================================
 *
 * [hometown_announcement]
 *
 * No frontend CSS is added.
 */

add_shortcode(
    'hometown_announcement',
    function () {

        $announcement =
            hometown_get_current_announcement();

        if (
            ! $announcement ||
            empty($announcement['message'])
        ) {
            return '';
        }


        $title =
            $announcement['title'] ?? '';

        $message =
            $announcement['message'] ?? '';

        $cta_label =
            $announcement['cta_label'] ?? '';

        $cta_url =
            hometown_announcement_url(
                $announcement['cta_url'] ?? ''
            );

        wp_enqueue_script('hometown-announcement-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', [], (string) filemtime(__DIR__ . '/assets/frontend.js'), true);
        $dismissal_key = hash('sha256', wp_json_encode($announcement));
        $is_preview = in_array($announcement['type'] ?? '', ['api-example', 'manual-preview'], true);


        ob_start();
?>

    <div
        class="site-header__announcement"
        data-hometown-announcement="<?php echo esc_attr($dismissal_key); ?>"
        data-hometown-announcement-preview="<?php echo $is_preview ? '1' : '0'; ?>"
        role="status">

        <div
            class="site-header__announcement-message">

            <span
                class="site-header__announcement-icon"
                aria-hidden="true">
                &#9888;
            </span>

            <?php if ($title) : ?>

                <strong
                    class="site-header__announcement-title">
                    <?php
                    echo esc_html($title);
                    ?>
                </strong>

            <?php endif; ?>

            <?php if ($title && $message) : ?>

                <span
                    class="site-header__announcement-divider"
                    aria-hidden="true"></span>

            <?php endif; ?>

            <span
                class="site-header__announcement-text">
                <?php
                echo esc_html($message);
                ?>
            </span>

        </div>


        <?php
        if (
            $cta_label &&
            $cta_url
        ) :
        ?>

            <a
                class="site-header__announcement-action"
                href="<?php
                        echo esc_url($cta_url);
                        ?>">

                <?php
                echo esc_html($cta_label);
                ?>

                <span aria-hidden="true">
                    &#8594;
                </span>

            </a>

        <?php endif; ?>


        <button
            class="site-header__announcement-close"
            data-hometown-announcement-close
            type="button"
            aria-label="Close announcement">
            &times;
        </button>

    </div>

<?php

        return ob_get_clean();
    }
);


/**
 * =========================================================
 * ADMIN ASSETS
 * =========================================================
 */

add_action(
    'admin_enqueue_scripts',
    function ($hook) {

        if (
            ! in_array($hook, ['toplevel_page_hometown-announcements', 'announcement-bars_page_hometown-announcement-api', 'hometown-announcements_page_hometown-announcement-api'], true)
        ) {
            return;
        }


        /**
         * CSS
         */

        wp_register_style(
            'hometown-announcement-admin',
            false
        );

        wp_enqueue_style(
            'hometown-announcement-admin'
        );


        $css = <<<'CSS'

.hometown-announcement-admin {
    max-width: 1100px;
}

.hometown-announcement-admin__intro {
    max-width: 760px;
    margin-bottom: 22px;
    color: #646970;
}

.hometown-announcement-card {
    margin-bottom: 20px;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
}

.hometown-announcement-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 16px 18px;
    background: #f6f7f7;
    border-bottom: 1px solid #dcdcde;
}

.hometown-announcement-card__header h2 {
    margin: 0;
}

.hometown-announcement-card__body {
    padding: 18px;
}

.hometown-announcement-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.hometown-announcement-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.hometown-announcement-field--full {
    grid-column: 1 / -1;
}

.hometown-announcement-field label {
    font-weight: 600;
}

.hometown-announcement-field input,
.hometown-announcement-field select,
.hometown-announcement-field textarea {
    width: 100%;
    max-width: none;
}

.hometown-announcement-help {
    color: #646970;
    font-size: 12px;
}

.hometown-announcement-shortcode {
    display: inline-block;
    padding: 5px 9px;
    background: #f0f0f1;
    border-radius: 4px;
    font-family: monospace;
}


/**
 * RULE ACCORDION
 */

.hometown-announcement-rule {
    margin-bottom: 12px;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
}

.hometown-announcement-rule__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #f6f7f7;
}

.hometown-announcement-rule__toggle {
    display: flex;
    align-items: center;
    gap: 10px;

    flex: 1;

    margin: 0;
    padding: 14px 16px;

    color: #1d2327;
    background: transparent;
    border: 0;

    text-align: left;
    cursor: pointer;
}

.hometown-announcement-rule__toggle:hover {
    background: #f0f0f1;
}

.hometown-announcement-rule__toggle:focus {
    outline: 2px solid #2271b1;
    outline-offset: -2px;
}

.hometown-announcement-rule__arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 20px;
    height: 20px;

    font-size: 11px;

    transition: transform .2s ease;
}

.hometown-announcement-rule.is-open
.hometown-announcement-rule__arrow {
    transform: rotate(90deg);
}

.hometown-announcement-rule__heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.hometown-announcement-rule__title {
    font-weight: 600;
}

.hometown-announcement-rule__type {
    display: inline-flex;
    align-items: center;

    padding: 2px 8px;

    color: #50575e;
    background: #e9eef6;
    border-radius: 999px;

    font-size: 11px;
    font-weight: 500;
}

.hometown-announcement-status {
    display: inline-flex;
    align-items: center;

    padding: 2px 8px;

    color: #116329;
    background: #edfaef;
    border-radius: 999px;

    font-size: 12px;
}

.hometown-announcement-status.is-inactive {
    color: #ff4646;
    background: #ffdbdb;
}


/**
 * ACTIONS
 */

.hometown-announcement-rule__actions {
    display: flex;
    align-items: center;
    gap: 6px;

    padding-right: 12px;
}

.hometown-rule-active-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    margin-right: 4px;
}

.hometown-icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 32px;
    height: 32px;

    padding: 0;

    border: 0;
    border-radius: 4px;

    background: transparent;

    cursor: pointer;
}

.hometown-icon-button:hover {
    background: #e8e8e8;
}

.hometown-icon-button .dashicons {
    width: 18px;
    height: 18px;

    font-size: 18px;
}

.hometown-duplicate-rule {
    color: #2271b1;
}

.hometown-duplicate-rule:hover {
    color: #135e96;
    background: #eef6fc;
}

.hometown-remove-rule {
    color: #b32d2e;
}

.hometown-remove-rule:hover {
    color: #b32d2e;
    background: #fcf0f1;
}


/**
 * BODY
 */

.hometown-announcement-rule__body {
    display: none;

    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;

    padding: 18px;

    border-top: 1px solid #dcdcde;
}

.hometown-announcement-rule.is-open
.hometown-announcement-rule__body {
    display: grid;
}

.hometown-announcement-empty {
    padding: 28px;

    color: #646970;

    text-align: center;

    border: 1px dashed #c3c4c7;
    border-radius: 8px;
}

.hometown-rule-field-hidden {
    display: none !important;
}


@media (max-width: 782px) {

    .hometown-announcement-grid,
    .hometown-announcement-rule__body,
    .hometown-announcement-rule.is-open
    .hometown-announcement-rule__body {
        grid-template-columns: 1fr;
    }

    .hometown-announcement-field--full {
        grid-column: auto;
    }

    .hometown-announcement-rule__header {
        align-items: stretch;
        flex-direction: column;
    }

    .hometown-announcement-rule__actions {
        padding: 0 16px 12px;
    }
}

/* Main settings screen: presentation only, shared API editors retain their layout. */
.hometown-announcement-settings{max-width:1100px}
.hometown-announcement-settings .hometown-announcement-card{margin-bottom:24px;border-color:#dfe3e8;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.025)}
.hometown-announcement-settings .hometown-announcement-card__header{padding:20px 24px;background:#fff;border-bottom-color:#edf0f3}
.hometown-announcement-settings .hometown-announcement-card__header h2{font-size:17px;line-height:1.4}
.hometown-announcement-section-note{margin:5px 0 0;color:#646970;font-size:12px;line-height:1.6}
.hometown-announcement-settings .hometown-announcement-card__body{padding:24px}
.hometown-announcement-settings .hometown-announcement-grid{gap:22px}
.hometown-announcement-settings .hometown-announcement-field{gap:8px}
.hometown-announcement-settings .hometown-announcement-field label{color:#3c434a;font-size:12px}
.hometown-announcement-settings .hometown-announcement-field input:not([type=checkbox]),.hometown-announcement-settings .hometown-announcement-field select{min-height:42px;padding:6px 12px;border-color:#cdd3db;border-radius:6px;background-color:#fff}
.hometown-announcement-settings .hometown-announcement-field textarea{min-height:100px;padding:12px;border-color:#cdd3db;border-radius:6px;line-height:1.7;resize:vertical}
.hometown-announcement-settings .hometown-announcement-field input:focus,.hometown-announcement-settings .hometown-announcement-field select:focus,.hometown-announcement-settings .hometown-announcement-field textarea:focus{border-color:#2271b1;box-shadow:0 0 0 3px rgba(34,113,177,.12);outline:none}
.hometown-announcement-enable{display:inline-flex;align-items:center;gap:8px;flex-shrink:0;padding:9px 12px;border:1px solid #e1e6ec;border-radius:7px;background:#f8fafc;color:#3c434a;font-size:12px;font-weight:500}
.hometown-announcement-enable input{margin:0}
.hometown-announcement-settings .hometown-announcement-rule{margin-bottom:10px;border-color:#e1e5eb;border-radius:8px;transition:border-color .15s ease}
.hometown-announcement-settings .hometown-announcement-rule:last-child{margin-bottom:0}
.hometown-announcement-settings .hometown-announcement-rule__header{gap:0;background:#fff}
.hometown-announcement-settings .hometown-announcement-rule__toggle{min-width:0;min-height:62px;padding:14px 16px;line-height:1.5}
.hometown-announcement-settings .hometown-announcement-rule__toggle:hover{background:#f8fafc}
.hometown-announcement-settings .hometown-announcement-rule__toggle:focus{outline:none}
.hometown-announcement-settings .hometown-announcement-rule__toggle:focus-visible{outline:2px solid #2271b1;outline-offset:-3px;border-radius:6px}
.hometown-announcement-settings .hometown-announcement-rule__arrow{width:24px;height:24px;flex-shrink:0;border-radius:5px;background:#f1f4f8;color:#646970;font-size:10px}
.hometown-announcement-settings .hometown-announcement-rule.is-open{border-color:#b9cfe3}
.hometown-announcement-settings .hometown-announcement-rule.is-open .hometown-announcement-rule__header{background:#f6f9fc}
.hometown-announcement-settings .hometown-announcement-rule__title{font-size:13px}
.hometown-announcement-settings .hometown-announcement-rule__type{padding:2px 8px;background:#eef2f7;color:#526174;font-size:10px;line-height:1.7}
.hometown-announcement-settings .hometown-announcement-status{gap:5px;padding:3px 9px;font-size:10px;font-weight:500;line-height:1.5}
.hometown-announcement-settings .hometown-announcement-status:before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}
.hometown-announcement-settings .hometown-announcement-status.is-inactive{color:#66717e;background:#f0f2f5}
.hometown-announcement-settings .hometown-announcement-rule__actions{gap:4px;padding:0 14px 0 10px;border-left:1px solid #edf0f3;flex-shrink:0}
.hometown-announcement-settings .hometown-rule-active-wrap{width:32px;height:32px;margin:0}
.hometown-announcement-settings .hometown-rule-active-wrap input{margin:0}
.hometown-announcement-settings .hometown-icon-button{width:32px;height:32px;border-radius:6px;text-decoration:none!important}
.hometown-announcement-settings .hometown-icon-button .dashicons{display:flex;align-items:center;justify-content:center;margin:0;line-height:1}
.hometown-announcement-settings .hometown-announcement-rule__body{padding:22px;background:#fcfdfe;border-top-color:#e8edf3}
.hometown-announcement-settings .button-primary{min-height:38px;padding:3px 15px;border-radius:6px;font-weight:500}
.hometown-announcement-settings>.submit,.hometown-announcement-settings form>.submit{margin-top:4px}
@media(max-width:782px){.hometown-announcement-settings .hometown-announcement-card__header{align-items:flex-start;padding:18px;flex-wrap:wrap}.hometown-announcement-settings .hometown-announcement-card__body{padding:16px}.hometown-announcement-settings .hometown-announcement-rule__header{flex-direction:row;align-items:center;flex-wrap:wrap}.hometown-announcement-settings .hometown-announcement-rule__actions{padding:0 10px;border-left:0;margin-left:auto;margin-bottom:8px}.hometown-announcement-settings .hometown-announcement-rule__toggle{padding:12px;flex-basis:65%}.hometown-announcement-settings .hometown-announcement-rule__body{padding:16px}}
.hometown-announcement-settings{max-width:1500px}
.hometown-announcement-settings>form{display:grid;grid-template-columns:minmax(320px,.85fr) minmax(0,1.4fr);gap:24px;align-items:start}
.hometown-announcement-settings>form>.hometown-announcement-card{min-width:0;margin:0}
.hometown-announcement-settings>form>.submit{grid-column:1/-1;margin:0;padding:0}
.hometown-announcement-settings>form>input{grid-column:1/-1}
.hometown-announcement-settings>form>.hometown-announcement-card:first-of-type .hometown-announcement-card__header{align-items:flex-start;flex-wrap:wrap}
.hometown-announcement-settings #hometown-location-rules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:start}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-empty{grid-column:1/-1}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule{min-width:0;margin:0;border-radius:10px;box-shadow:0 2px 5px rgba(0,0,0,.025)}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__header{flex-direction:column;align-items:stretch;gap:0}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__toggle{flex:auto;align-items:flex-start;min-height:82px;padding:16px}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__heading{flex:1;gap:7px}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__title{flex-basis:100%;font-size:14px;overflow-wrap:anywhere}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__actions{justify-content:flex-end;margin:0;padding:9px 12px;border-left:0;border-top:1px solid #edf0f3;background:#fafbfc}
.hometown-announcement-settings #hometown-location-rules .hometown-rule-active-wrap{margin-right:auto}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__body{grid-template-columns:1fr;padding:16px;gap:16px}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-field--full{grid-column:auto}
@media(min-width:1101px) and (max-width:1350px){.hometown-announcement-settings #hometown-location-rules{grid-template-columns:1fr}}
@media(max-width:1100px){.hometown-announcement-settings>form{grid-template-columns:1fr}}
@media(max-width:600px){.hometown-announcement-settings #hometown-location-rules{grid-template-columns:1fr}}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__arrow{font-size:0;transform:none}
.hometown-announcement-settings #hometown-location-rules .hometown-announcement-rule__arrow:before{content:"\270e";font-size:15px}
.htr-rule-dialog{box-sizing:border-box;width:min(760px,calc(100vw - 32px));max-height:85vh;padding:0;border:1px solid #dce2e9;border-radius:12px;background:#fff;color:#1d2327;box-shadow:0 24px 80px rgba(0,0,0,.22)}
.htr-rule-dialog::backdrop{background:rgba(15,23,42,.5)}
.htr-rule-preview{padding:18px 22px;border-bottom:1px solid #edf0f3;background:#fafbfc}
.htr-rule-preview__heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;font-size:12px}.htr-rule-preview__heading>span{color:#646970;font-size:11px}
.htr-rule-preview .site-header__announcement{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:7px;background:#f8c33f;color:#050505;font-size:12px;line-height:1.6;overflow-wrap:anywhere}
.htr-rule-preview .site-header__announcement-message{display:flex;flex:1;flex-wrap:wrap;align-items:center;gap:6px 10px;min-width:0}
.htr-rule-preview .site-header__announcement-title{font-size:12px}.htr-rule-preview .site-header__announcement-action{max-width:190px;font-weight:700;font-size:11px}.htr-rule-preview .site-header__announcement-action:after{content:" \2192"}
.htr-rule-preview [hidden]{display:none!important}.htr-rule-preview__note{margin:10px 0 0;color:#646970;font-size:11px;line-height:1.6}.htr-rule-preview__empty{padding:16px;margin:0;border:1px dashed #c3cdd7;border-radius:7px;color:#646970;font-size:12px}
@media(max-width:600px){.htr-rule-preview{padding:16px}.htr-rule-preview .site-header__announcement{flex-wrap:wrap}.htr-rule-preview .site-header__announcement-action{max-width:100%}}
.htr-rule-dialog__heading,.htr-rule-dialog__footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;background:#fff}
.htr-rule-dialog__heading{position:sticky;top:0;z-index:2;border-bottom:1px solid #edf0f3;font-size:16px}
.htr-rule-dialog__footer{position:sticky;bottom:0;z-index:2;border-top:1px solid #edf0f3}.htr-rule-dialog__footer>span{max-width:380px;font-size:12px;color:#646970;line-height:1.6}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule{margin:0;border:0;border-radius:0;box-shadow:none}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__header{flex-direction:row;align-items:center;padding:0 8px;background:#f6f9fc}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__toggle{pointer-events:none;min-height:60px}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__arrow,.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-icon-button{display:none}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__actions{border:0;background:transparent;flex-shrink:0}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__body{grid-template-columns:repeat(2,minmax(0,1fr));padding:24px}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-field--full{grid-column:1/-1}
@media(max-width:600px){.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-announcement-rule__body{grid-template-columns:1fr;padding:16px}.htr-rule-dialog__heading,.htr-rule-dialog__footer{padding:14px 16px}.htr-rule-dialog__footer{flex-wrap:wrap}}
/* Match the compact example-preview cards. */
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule{border-color:#dfe4ec;border-radius:10px;box-shadow:none;background:#fff}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__toggle{padding:20px 18px 12px;min-height:122px;align-items:flex-start}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__arrow{display:none}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__heading{display:flex;flex-direction:column;align-items:flex-start;gap:10px}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__type{order:-1;padding:0;background:none;border-radius:0;color:#526174;font-size:10px;font-weight:500;letter-spacing:.045em;text-transform:uppercase}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__type:after{content:" match"}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__title{flex-basis:auto;font-size:15px;font-weight:600;line-height:1.4}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-status{padding:0;background:none;font-size:11px}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__actions{flex-wrap:wrap;gap:6px;padding:0 18px 14px;border:0;background:#fff}
.hometown-announcement-settings #hometown-location-rules .hometown-preview-rule{display:flex;align-items:center;justify-content:space-between;flex:0 0 100%;box-sizing:border-box;width:100%;min-height:40px;margin-bottom:6px;padding:6px 12px;border:1px solid #2271b1;border-radius:5px;background:#fff;color:#2271b1;font-size:12px;text-align:left}
.hometown-announcement-settings #hometown-location-rules .hometown-preview-rule:hover{background:#f0f6fc;border-color:#135e96;color:#135e96}
.hometown-announcement-settings #hometown-location-rules .htr-rule-dialog .hometown-preview-rule{display:none}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__actions{order:-1;padding:12px 14px 0}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-announcement-rule__toggle{min-height:106px;padding-top:10px}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule .hometown-preview-rule{flex-basis:auto;width:calc(100% - 36px);margin:0 18px 18px}
.hometown-announcement-settings #hometown-location-rules>.hometown-announcement-rule[hidden],.htr-rule-pagination[hidden]{display:none!important}
.htr-rule-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:20px;padding-top:16px;border-top:1px solid #edf0f3;color:#646970;font-size:12px}
.htr-rule-pagination__controls{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.htr-rule-pagination__controls label{display:flex;align-items:center;gap:6px;margin-right:4px;font-size:11px}.htr-rule-pagination__controls select{min-height:32px;border-radius:5px;font-size:12px}.htr-rule-pagination__controls .button{border-radius:5px;font-size:12px}.htr-rule-pagination__page{min-width:36px;text-align:center;font-size:11px;font-variant-numeric:tabular-nums}
@media(prefers-reduced-motion:reduce){.hometown-announcement-settings .hometown-announcement-rule,.hometown-announcement-settings .hometown-announcement-rule__arrow{transition:none}}
CSS;


        wp_add_inline_style(
            'hometown-announcement-admin',
            $css
        );


        /**
         * JS
         */

        wp_register_script(
            'hometown-announcement-admin',
            '',
            [],
            false,
            true
        );

        wp_enqueue_script(
            'hometown-announcement-admin'
        );


        wp_localize_script(
            'hometown-announcement-admin',
            'HometownAnnouncementAdmin',
            [
                'states' =>
                hometown_get_states(),
                'previewLinks' => hometown_manual_preview_links(),
                'rulesPerPage' => hometown_announcement_rules_per_page(),
            ]
        );


        $js = <<<'JS'

document.addEventListener('DOMContentLoaded', function () {

    const container =
        document.getElementById(
            'hometown-location-rules'
        );

    const addButton =
        document.getElementById(
            'hometown-add-rule'
        );

    if (!container || !addButton) {
        return;
    }


    /**
     * =====================================================
     * ACCORDION
     * =====================================================
     */

    function toggleRule(rule) {
        openRuleEditor(rule);
    }

    const ruleDialog = document.createElement('dialog');
    ruleDialog.className = 'htr-rule-dialog';
    ruleDialog.setAttribute('aria-label', 'Edit location rule');
    ruleDialog.innerHTML = '<div class="htr-rule-dialog__heading"><strong>Edit location rule</strong><button type="button" class="button htr-rule-dialog__close" aria-label="Close rule editor">Close</button></div><section class="htr-rule-preview" aria-label="Announcement bar preview"><div class="htr-rule-preview__heading"><strong>Announcement preview</strong><span>Unsaved preview</span></div><div class="htr-rule-preview__bar site-header__announcement"><div class="site-header__announcement-message"><span aria-hidden="true">&#9888;</span><strong class="site-header__announcement-title"></strong><span class="site-header__announcement-text"></span></div><span class="site-header__announcement-action"></span><span aria-hidden="true">&times;</span></div><p class="htr-rule-preview__empty" hidden>Enter an announcement message to see the preview.</p><p class="htr-rule-preview__note">Blank CTA fields use your global CTA. Display still depends on the rule being enabled, its schedule, and a matching location or URL.</p></section><div class="htr-rule-dialog__content"></div><div class="htr-rule-dialog__footer"><span>Changes are saved when you click Save Announcement Settings.</span><button type="button" class="button button-primary htr-rule-dialog__done">Done editing</button></div>';
    container.appendChild(ruleDialog);
    let editingRule = null;
    let editorPlaceholder = null;
    let editorTrigger = null;
    let rulePage = 1;
    const rulesPerPage = Number(HometownAnnouncementAdmin.rulesPerPage) || 6;
    const rulePager = document.createElement('nav');
    rulePager.className = 'htr-rule-pagination';
    rulePager.setAttribute('aria-label', 'Location Rules pages');
    rulePager.innerHTML = '<span class="htr-rule-pagination__count" role="status" aria-live="polite"></span><div class="htr-rule-pagination__controls"><button type="button" class="button htr-rule-pagination__previous">Previous</button><span class="htr-rule-pagination__page"></span><button type="button" class="button htr-rule-pagination__next">Next</button></div>';
    container.after(rulePager);
    function ruleCards() {
        return Array.from(container.children).filter(function (element) { return element.classList.contains('hometown-announcement-rule'); });
    }
    function renderRulePagination() {
        const cards = ruleCards();
        const totalPages = Math.max(1, Math.ceil(cards.length / rulesPerPage));
        rulePage = Math.max(1, Math.min(rulePage, totalPages));
        const first = (rulePage - 1) * rulesPerPage;
        cards.forEach(function (card, index) { card.hidden = index < first || index >= first + rulesPerPage; });
        rulePager.hidden = cards.length <= rulesPerPage;
        rulePager.querySelector('.htr-rule-pagination__count').textContent = cards.length ? 'Showing ' + (first + 1) + '–' + Math.min(first + rulesPerPage, cards.length) + ' of ' + cards.length + ' rules' : 'No rules';
        rulePager.querySelector('.htr-rule-pagination__page').textContent = rulePage + ' / ' + totalPages;
        rulePager.querySelector('.htr-rule-pagination__previous').disabled = rulePage === 1;
        rulePager.querySelector('.htr-rule-pagination__next').disabled = rulePage === totalPages;
    }
    rulePager.querySelector('.htr-rule-pagination__previous').addEventListener('click', function () { rulePage--; renderRulePagination(); });
    rulePager.querySelector('.htr-rule-pagination__next').addEventListener('click', function () { rulePage++; renderRulePagination(); });
    function updateRulePreview() {
        if (!editingRule) { return; }
        const form = container.closest('form');
        function globalValue(key) {
            const field = form.elements.namedItem('hometown_announcement_settings[default][' + key + ']');
            return field ? field.value : '';
        }
        function ruleValue(key) {
            const field = editingRule.querySelector('[name$="[' + key + ']"]');
            return field ? field.value : '';
        }
        const title = globalValue('title');
        const message = ruleValue('message');
        const cta = ruleValue('cta_label') || globalValue('cta_label');
        const url = ruleValue('cta_url') || globalValue('cta_url');
        const preview = ruleDialog.querySelector('.htr-rule-preview');
        const titleNode = preview.querySelector('.site-header__announcement-title');
        titleNode.textContent = title;
        titleNode.hidden = !title;
        preview.querySelector('.site-header__announcement-text').textContent = message;
        const ctaNode = preview.querySelector('.site-header__announcement-action');
        ctaNode.textContent = cta;
        ctaNode.hidden = !(cta && url);
        preview.querySelector('.htr-rule-preview__bar').hidden = !message;
        preview.querySelector('.htr-rule-preview__empty').hidden = !!message;
    }
    ruleDialog.addEventListener('input', updateRulePreview);
    ruleDialog.addEventListener('change', updateRulePreview);
    function openRuleEditor(rule) {
        if (ruleDialog.open) { return; }
        rulePage = Math.floor(ruleCards().indexOf(rule) / rulesPerPage) + 1;
        renderRulePagination();
        editingRule = rule;
        editorTrigger = document.activeElement;
        editorPlaceholder = document.createComment('Location rule position');
        rule.before(editorPlaceholder);
        rule.classList.add('is-open');
        ruleDialog.querySelector('.htr-rule-dialog__content').appendChild(rule);
        updateRulePreview();
        ruleDialog.showModal();
    }
    function closeRuleEditor() { ruleDialog.close(); }
    ruleDialog.querySelector('.htr-rule-dialog__close').addEventListener('click', closeRuleEditor);
    ruleDialog.querySelector('.htr-rule-dialog__done').addEventListener('click', closeRuleEditor);
    ruleDialog.addEventListener('close', function () {
        if (editingRule && editorPlaceholder) {
            editingRule.classList.remove('is-open');
            editorPlaceholder.replaceWith(editingRule);
        }
        editingRule = null;
        editorPlaceholder = null;
        renderRulePagination();
        if (editorTrigger && editorTrigger.isConnected) { editorTrigger.focus(); }
    });
    ruleDialog.addEventListener('click', function (event) {
        if (event.target !== ruleDialog) { return; }
        const bounds = ruleDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) { closeRuleEditor(); }
    });


    /**
     * =====================================================
     * FIELD VISIBILITY
     * =====================================================
     */

    function updateRule(rule) {

        const type =
            rule.querySelector(
                '.hometown-match-type'
            );

        if (!type) {
            return;
        }


        const urlField =
            rule.querySelector(
                '.hometown-url-field'
            );

        const cityField =
            rule.querySelector(
                '.hometown-city-field'
            );

        const stateField =
            rule.querySelector(
                '.hometown-state-field'
            );


        if (urlField) {
            urlField.classList.toggle(
                'hometown-rule-field-hidden',
                type.value !== 'url'
            );
        }


        if (cityField) {
            cityField.classList.toggle(
                'hometown-rule-field-hidden',
                type.value !== 'city'
            );
        }


        if (stateField) {
            stateField.classList.toggle(
                'hometown-rule-field-hidden',
                ![
                    'city',
                    'state'
                ].includes(type.value)
            );
        }


        updateTitle(rule);
    }


    /**
     * =====================================================
     * TITLE
     * =====================================================
     */

    function updateTitle(rule) {

        const type =
            rule.querySelector(
                '.hometown-match-type'
            );

        const title =
            rule.querySelector(
                '.hometown-announcement-rule__title'
            );

        const typeBadge =
            rule.querySelector(
                '.hometown-announcement-rule__type'
            );


        if (!type || !title) {
            return;
        }


        if (typeBadge) {

            const labels = {
                state: 'State',
                city: 'City',
                url: 'URL'
            };

            typeBadge.textContent =
                labels[type.value]
                || type.value;
        }


        let text =
            'New Rule';


        if (type.value === 'url') {

            const field =
                rule.querySelector(
                    '.hometown-url-slug'
                );

            if (
                field &&
                field.value.trim()
            ) {
                text =
                    'URL: ' +
                    field.value.trim();
            }
        }


        if (type.value === 'state') {

            const field =
                rule.querySelector(
                    '.hometown-state-select'
                );

            if (
                field &&
                field.value
            ) {
                text =
                    field.options[
                        field.selectedIndex
                    ].text;
            }
        }


        if (type.value === 'city') {

            const city =
                rule.querySelector(
                    '.hometown-city-input'
                );

            const state =
                rule.querySelector(
                    '.hometown-state-select'
                );


            if (
                city &&
                city.value.trim()
            ) {

                text =
                    city.value.trim();

                if (
                    state &&
                    state.value
                ) {
                    text +=
                        ', ' +
                        state.value;
                }
            }
        }


        title.textContent =
            text;
    }


    /**
     * =====================================================
     * STATUS
     * =====================================================
     */

    function updateStatus(rule) {

        const checkbox =
            rule.querySelector(
                '.hometown-rule-active'
            );

        const status =
            rule.querySelector(
                '.hometown-announcement-status'
            );


        if (!checkbox || !status) {
            return;
        }


        if (checkbox.checked) {

            status.textContent =
                'Active';

            status.classList.remove(
                'is-inactive'
            );

        } else {

            status.textContent =
                'Inactive';

            status.classList.add(
                'is-inactive'
            );
        }
    }


    /**
     * =====================================================
     * SCHEDULE
     * =====================================================
     */

    function updateSchedule(rule) {

        const select =
            rule.querySelector(
                '.hometown-schedule-type'
            );

        const dateFields =
            rule.querySelectorAll(
                '.hometown-schedule-date'
            );


        if (!select) {
            return;
        }


        dateFields.forEach(function (field) {

            field.style.display =
                select.value === 'dates'
                    ? ''
                    : 'none';
        });
    }


    /**
     * =====================================================
     * BIND RULE
     * =====================================================
     */

    function bindRule(rule) {

        const actions = rule.querySelector('.hometown-announcement-rule__actions');
        let previewButton = rule.querySelector('.hometown-preview-rule');
        if (!previewButton) {
            previewButton = document.createElement('a');
            previewButton.className = 'button hometown-preview-rule';
            previewButton.innerHTML = '<span>Preview announcement</span><span aria-hidden="true">&#8599;</span>';
        }
        rule.querySelector('.hometown-announcement-rule__header').appendChild(previewButton);
        const namedField = rule.querySelector('input[name],select[name],textarea[name]');
        const indexMatch = namedField && namedField.name.match(/\[locations\]\[([^\]]+)\]/);
        const previewUrl = indexMatch && HometownAnnouncementAdmin.previewLinks[indexMatch[1]];
        previewButton.href = previewUrl || '#';
        previewButton.target = '_blank';
        previewButton.rel = 'noopener';
        previewButton.title = 'Preview saved rule in a new tab. Save changes first.';
        previewButton.addEventListener('click', function (event) {
            if (!previewUrl) {
                event.preventDefault();
                window.alert('Save Announcement Settings first to create a website preview link for this rule.');
            }
        });

        const toggle =
            rule.querySelector(
                '.hometown-announcement-rule__toggle'
            );


        if (toggle) {

            toggle.setAttribute('aria-haspopup', 'dialog');

            toggle.addEventListener(
                'click',
                function () {

                    toggleRule(rule);
                }
            );
        }


        rule.addEventListener(
            'input',
            function () {

                updateTitle(rule);
            }
        );


        rule.addEventListener(
            'change',
            function () {

                updateRule(rule);
                updateStatus(rule);
                updateSchedule(rule);
            }
        );


        updateRule(rule);
        updateStatus(rule);
        updateSchedule(rule);
    }


    /**
     * EXISTING RULES
     */

    container
        .querySelectorAll(
            '.hometown-announcement-rule'
        )
        .forEach(function (rule) {

            bindRule(rule);

            rule.classList.remove(
                'is-open'
            );

            const toggle =
                rule.querySelector(
                    '.hometown-announcement-rule__toggle'
                );

            if (toggle) {
                toggle.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }
        });


    /**
     * =====================================================
     * STATE OPTIONS
     * =====================================================
     */

    renderRulePagination();

    function getStateOptions() {

        let html =
            '<option value="">Select State</option>';


        Object.entries(
            HometownAnnouncementAdmin.states
        ).forEach(function ([code, name]) {

            html +=
                `<option value="${code}">${name}</option>`;
        });


        return html;
    }


    /**
     * =====================================================
     * ADD RULE
     * =====================================================
     */

    addButton.addEventListener(
        'click',
        function () {

            const index =
                Date.now();


            const rule =
                document.createElement(
                    'div'
                );


            rule.className =
                'hometown-announcement-rule is-open';


            rule.innerHTML = `

                <div class="hometown-announcement-rule__header">

                    <button
                        type="button"
                        class="hometown-announcement-rule__toggle"
                        aria-expanded="true"
                    >

                        <span
                            class="hometown-announcement-rule__arrow"
                            aria-hidden="true"
                        >
                            ▶
                        </span>


                        <span class="hometown-announcement-rule__heading">

                            <strong class="hometown-announcement-rule__title">
                                New Rule
                            </strong>

                            <span class="hometown-announcement-rule__type">
                                State
                            </span>

                            <span class="hometown-announcement-status">
                                Active
                            </span>

                        </span>

                    </button>


                    <div class="hometown-announcement-rule__actions">

                        <label class="hometown-rule-active-wrap">

                            <input
                                type="checkbox"
                                class="hometown-rule-active"
                                name="hometown_announcement_settings[locations][${index}][enabled]"
                                value="1"
                                checked
                            >

                            <span class="screen-reader-text">
                                Active
                            </span>

                        </label>


                        <button
                            type="button"
                            class="button-link hometown-icon-button hometown-duplicate-rule"
                            aria-label="Duplicate rule"
                            title="Duplicate rule"
                        >
                            <span
                                class="dashicons dashicons-admin-page"
                                aria-hidden="true"
                            ></span>
                        </button>


                        <button
                            type="button"
                            class="button-link-delete hometown-icon-button hometown-remove-rule"
                            aria-label="Remove rule"
                            title="Remove rule"
                        >
                            <span
                                class="dashicons dashicons-trash"
                                aria-hidden="true"
                            ></span>
                        </button>

                    </div>

                </div>


                <div class="hometown-announcement-rule__body">


                    <div class="hometown-announcement-field">

                        <label>
                            Match By
                        </label>

                        <select
                            class="hometown-match-type"
                            name="hometown_announcement_settings[locations][${index}][match_type]"
                        >

                            <option value="state">
                                State
                            </option>

                            <option value="city">
                                City
                            </option>

                            <option value="url">
                                Page URL
                            </option>

                        </select>

                    </div>


                    <div class="hometown-announcement-field hometown-state-field">

                        <label>
                            State
                        </label>

                        <select
                            class="hometown-state-select"
                            name="hometown_announcement_settings[locations][${index}][state]"
                        >
                            ${getStateOptions()}
                        </select>

                    </div>


                    <div class="hometown-announcement-field hometown-city-field">

                        <label>
                            City
                        </label>

                        <input
                            type="text"
                            class="hometown-city-input"
                            name="hometown_announcement_settings[locations][${index}][city]"
                            placeholder="Boerne"
                        >

                    </div>


                    <div class="hometown-announcement-field hometown-url-field">

                        <label>
                            URL Location Slug
                        </label>

                        <input
                            type="text"
                            class="hometown-url-slug"
                            name="hometown_announcement_settings[locations][${index}][url_slug]"
                            placeholder="boerne"
                        >

                        <span class="hometown-announcement-help">
                            Example: boerne matches /trusted/boerne/
                        </span>

                    </div>


                    <div class="hometown-announcement-field hometown-announcement-field--full">

                        <label>
                            Announcement Message
                        </label>

                        <textarea
                            rows="3"
                            name="hometown_announcement_settings[locations][${index}][message]"
                            placeholder="Recent hail and high winds reported in this area."
                        ></textarea>

                    </div>


                    <div class="hometown-announcement-field">

                        <label>
                            CTA Label
                        </label>

                        <input
                            type="text"
                            name="hometown_announcement_settings[locations][${index}][cta_label]"
                            placeholder="Schedule a Free Roof Inspection"
                        >

                        <span class="hometown-announcement-help">
                            Leave blank to use global CTA.
                        </span>

                    </div>


                    <div class="hometown-announcement-field">

                        <label>
                            CTA URL
                        </label>

                        <input
                            type="text"
                            name="hometown_announcement_settings[locations][${index}][cta_url]"
                            placeholder="/get-a-quote/"
                        >

                        <span class="hometown-announcement-help">
                            Leave blank to use global CTA URL.
                        </span>

                    </div>


                    <div class="hometown-announcement-field">

                        <label>
                            Schedule
                        </label>

                        <select
                            class="hometown-schedule-type"
                            name="hometown_announcement_settings[locations][${index}][schedule_type]"
                        >

                            <option value="always">
                                Always Active
                            </option>

                            <option value="dates">
                                Schedule Dates
                            </option>

                        </select>

                    </div>


                    <div class="hometown-announcement-field hometown-schedule-date">

                        <label>
                            Start Date
                        </label>

                        <input
                            type="date"
                            name="hometown_announcement_settings[locations][${index}][start_date]"
                        >

                    </div>


                    <div class="hometown-announcement-field hometown-schedule-date">

                        <label>
                            End Date
                        </label>

                        <input
                            type="date"
                            name="hometown_announcement_settings[locations][${index}][end_date]"
                        >

                    </div>

                </div>
            `;


            const empty =
                container.querySelector(
                    '.hometown-announcement-empty'
                );


            if (empty) {
                empty.remove();
            }


            /**
             * Collapse other rules
             */

            container
                .querySelectorAll(
                    '.hometown-announcement-rule'
                )
                .forEach(function (existingRule) {

                    existingRule.classList.remove(
                        'is-open'
                    );

                    const existingToggle =
                        existingRule.querySelector(
                            '.hometown-announcement-rule__toggle'
                        );

                    if (existingToggle) {
                        existingToggle.setAttribute(
                            'aria-expanded',
                            'false'
                        );
                    }
                });


            container.appendChild(rule);

            bindRule(rule);

            rule.classList.add(
                'is-open'
            );

            openRuleEditor(rule);

        }
    );


    /**
     * =====================================================
     * DUPLICATE + REMOVE
     * =====================================================
     */

    container.addEventListener(
        'click',
        function (event) {

            const duplicateButton =
                event.target.closest(
                    '.hometown-duplicate-rule'
                );

            const removeButton =
                event.target.closest(
                    '.hometown-remove-rule'
                );


            /**
             * DUPLICATE
             */

            if (duplicateButton) {

                const original =
                    duplicateButton.closest(
                        '.hometown-announcement-rule'
                    );

                if (!original) {
                    return;
                }


                const clone =
                    original.cloneNode(true);


                const newIndex =
                    Date.now();


                clone
                    .querySelectorAll(
                        'input, select, textarea'
                    )
                    .forEach(function (field) {

                        if (!field.name) {
                            return;
                        }


                        field.name =
                            field.name.replace(
                                /\[locations\]\[[^\]]+\]/,
                                `[locations][${newIndex}]`
                            );
                    });


                /**
                 * Collapse original
                 */

                original.classList.remove(
                    'is-open'
                );


                const originalToggle =
                    original.querySelector(
                        '.hometown-announcement-rule__toggle'
                    );


                if (originalToggle) {
                    originalToggle.setAttribute(
                        'aria-expanded',
                        'false'
                    );
                }


                /**
                 * Open clone
                 */

                clone.classList.add(
                    'is-open'
                );


                const cloneToggle =
                    clone.querySelector(
                        '.hometown-announcement-rule__toggle'
                    );


                if (cloneToggle) {
                    cloneToggle.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }


                original.insertAdjacentElement(
                    'afterend',
                    clone
                );


                bindRule(clone);

                updateRule(clone);
                updateStatus(clone);
                updateSchedule(clone);

                openRuleEditor(clone);


                return;
            }


            /**
             * REMOVE
             */

            if (removeButton) {

                const rule =
                    removeButton.closest(
                        '.hometown-announcement-rule'
                    );


                if (rule) {
                    rule.remove();
                    renderRulePagination();
                }


                if (
                    ! container.querySelector(
                        '.hometown-announcement-rule'
                    )
                ) {

                    container.insertAdjacentHTML(
                        'beforeend',
                        `
                        <div class="hometown-announcement-empty">
                            No location rules yet.
                        </div>
                        `
                    );
                }
            }

        }
    );

});

JS;


        wp_add_inline_script(
            'hometown-announcement-admin',
            $js
        );
    }
);


/**
 * =========================================================
 * STATE OPTIONS
 * =========================================================
 */

function hometown_render_state_options(
    $selected = ''
) {
    echo '<option value="">Select State</option>';

    foreach (
        hometown_get_states()
        as $code => $name
    ) {

        printf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr($code),
            selected(
                $selected,
                $code,
                false
            ),
            esc_html($name)
        );
    }
}


/**
 * =========================================================
 * RULE TITLE
 * =========================================================
 */

function hometown_get_rule_title($rule)
{
    $match_type =
        $rule['match_type'] ?? 'state';

    if ($match_type === 'state') {

        $state =
            $rule['state'] ?? '';

        $states =
            hometown_get_states();

        return $states[$state]
            ?? 'State Rule';
    }


    if ($match_type === 'city') {

        $city =
            $rule['city'] ?? '';

        $state =
            $rule['state'] ?? '';

        $title =
            $city ?: 'City Rule';

        if ($state) {
            $title .= ', ' . $state;
        }

        return $title;
    }


    if ($match_type === 'url') {

        $slug =
            $rule['url_slug'] ?? '';

        return $slug
            ? 'URL: ' . $slug
            : 'URL Rule';
    }


    return 'Location Rule';
}


/**
 * =========================================================
 * RENDER RULE
 * =========================================================
 */

function hometown_render_announcement_rule(
    $index,
    $rule,
    $global_content = false
) {

    $enabled =
        ! empty($rule['enabled']);

    $match_type =
        $rule['match_type'] ?? 'state';

    $url_slug =
        $rule['url_slug'] ?? '';

    $city =
        $rule['city'] ?? '';

    $state =
        $rule['state'] ?? '';

    $message =
        $rule['message'] ?? '';

    $cta_label =
        $rule['cta_label'] ?? '';

    $cta_url =
        $rule['cta_url'] ?? '';

    $schedule_type =
        $rule['schedule_type'] ?? 'always';

    $start_date =
        $rule['start_date'] ?? '';

    $end_date =
        $rule['end_date'] ?? '';

    $card_title =
        hometown_get_rule_title($rule);

    $type_label =
        $match_type === 'url'
        ? 'URL'
        : ucfirst($match_type);

?>

    <div class="hometown-announcement-rule">

        <div class="hometown-announcement-rule__header">

            <button
                type="button"
                class="hometown-announcement-rule__toggle"
                aria-expanded="false">

                <span
                    class="hometown-announcement-rule__arrow"
                    aria-hidden="true">
                    ▶
                </span>


                <span class="hometown-announcement-rule__heading">

                    <strong
                        class="hometown-announcement-rule__title">
                        <?php
                        echo esc_html($card_title);
                        ?>
                    </strong>


                    <span
                        class="hometown-announcement-rule__type">
                        <?php
                        echo esc_html($type_label);
                        ?>
                    </span>


                    <span
                        class="hometown-announcement-status <?php
                                                            echo $enabled
                                                                ? ''
                                                                : 'is-inactive';
                                                            ?>">
                        <?php
                        echo $enabled
                            ? 'Active'
                            : 'Inactive';
                        ?>
                    </span>

                </span>

            </button>


            <div class="hometown-announcement-rule__actions">

                <label class="hometown-rule-active-wrap">

                    <input
                        type="checkbox"
                        class="hometown-rule-active"
                        name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][enabled]"
                        value="1"
                        <?php checked($enabled); ?>>

                    <span class="screen-reader-text">
                        Active
                    </span>

                </label>


                <button
                    type="button"
                    class="button-link hometown-icon-button hometown-duplicate-rule"
                    aria-label="Duplicate rule"
                    title="Duplicate rule">
                    <span
                        class="dashicons dashicons-admin-page"
                        aria-hidden="true"></span>
                </button>


                <button
                    type="button"
                    class="button-link-delete hometown-icon-button hometown-remove-rule"
                    aria-label="Remove rule"
                    title="Remove rule">
                    <span
                        class="dashicons dashicons-trash"
                        aria-hidden="true"></span>
                </button>

            </div>

        </div>


        <div class="hometown-announcement-rule__body">


            <div class="hometown-announcement-field">

                <label>
                    Match By
                </label>

                <select
                    class="hometown-match-type"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][match_type]">

                    <option
                        value="state"
                        <?php selected($match_type, 'state'); ?>>
                        State
                    </option>

                    <option
                        value="city"
                        <?php selected($match_type, 'city'); ?>>
                        City
                    </option>

                    <option
                        value="url"
                        <?php selected($match_type, 'url'); ?>>
                        Page URL
                    </option>

                </select>

            </div>


            <div class="hometown-announcement-field hometown-state-field">

                <label>
                    State
                </label>

                <select
                    class="hometown-state-select"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][state]">

                    <?php
                    hometown_render_state_options(
                        $state
                    );
                    ?>

                </select>

            </div>


            <div class="hometown-announcement-field hometown-city-field">

                <label>
                    City
                </label>

                <input
                    type="text"
                    class="hometown-city-input"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][city]"
                    value="<?php echo esc_attr($city); ?>"
                    placeholder="Boerne">

            </div>


            <div class="hometown-announcement-field hometown-url-field">

                <label>
                    URL Location Slug
                </label>

                <input
                    type="text"
                    class="hometown-url-slug"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][url_slug]"
                    value="<?php echo esc_attr($url_slug); ?>"
                    placeholder="boerne">

                <span class="hometown-announcement-help">
                    Example: boerne matches /trusted/boerne/
                </span>

            </div>


            <div class="hometown-announcement-field hometown-announcement-field--full">

                <label>
                    Announcement Message
                </label>

                <textarea
                    rows="3"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][message]"
                    <?php echo $global_content ? 'readonly' : ''; ?>
                    placeholder="Recent hail and high winds reported in this area."><?php echo esc_textarea($message); ?></textarea>

            </div>


            <div class="hometown-announcement-field">

                <label>
                    CTA Label
                </label>

                <input
                    type="text"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][cta_label]"
                    <?php echo $global_content ? 'readonly' : ''; ?>
                    value="<?php echo esc_attr($cta_label); ?>"
                    placeholder="Schedule a Free Roof Inspection">

                <span class="hometown-announcement-help">
                    Leave blank to use global CTA.
                </span>

            </div>


            <div class="hometown-announcement-field">

                <label>
                    CTA URL
                </label>

                <input
                    type="text"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][cta_url]"
                    <?php echo $global_content ? 'readonly' : ''; ?>
                    value="<?php echo esc_attr($cta_url); ?>"
                    placeholder="/get-a-quote/">

                <span class="hometown-announcement-help">
                    Leave blank to use global CTA URL.
                </span>

            </div>


            <div class="hometown-announcement-field">

                <label>
                    Schedule
                </label>

                <select
                    class="hometown-schedule-type"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][schedule_type]">

                    <option
                        value="always"
                        <?php selected($schedule_type, 'always'); ?>>
                        Always Active
                    </option>

                    <option
                        value="dates"
                        <?php selected($schedule_type, 'dates'); ?>>
                        Schedule Dates
                    </option>

                </select>

            </div>


            <div class="hometown-announcement-field hometown-schedule-date">

                <label>
                    Start Date
                </label>

                <input
                    type="date"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][start_date]"
                    value="<?php echo esc_attr($start_date); ?>">

            </div>


            <div class="hometown-announcement-field hometown-schedule-date">

                <label>
                    End Date
                </label>

                <input
                    type="date"
                    name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[locations][<?php echo esc_attr($index); ?>][end_date]"
                    value="<?php echo esc_attr($end_date); ?>">

            </div>

        </div>

    </div>

<?php
}


/**
 * =========================================================
 * ADMIN PAGE
 * =========================================================
 */

function hometown_announcement_admin_page()
{
    if (
        ! current_user_can(
            'manage_options'
        )
    ) {
        return;
    }


    $settings =
        hometown_announcement_get_settings();

    $default =
        $settings['default'] ?? [];

    $rules =
        $settings['locations'] ?? [];

?>

    <div class="wrap hometown-announcement-admin hometown-announcement-settings">

        <h1>
            Announcement Bar Settings
        </h1>


        <p class="hometown-announcement-admin__intro">
            Configure a global announcement and optional location-specific rules.
            Rules can match by visitor state, visitor city, or the current page URL.
        </p>

        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=hometown-announcement-api')); ?>">API Location Rules</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=hometown-announcement-reports')); ?>">Reports</a></p>


        <p>
            Shortcode:

            <span class="hometown-announcement-shortcode">
                [hometown_announcement]
            </span>
        </p>


        <form
            method="post"
            action="options.php">

            <?php
            settings_fields(
                'hometown_announcement_group'
            );
            ?>


            <!-- GLOBAL -->

            <div class="hometown-announcement-card">

                <div class="hometown-announcement-card__header">

                    <div><h2>
                        Global Announcement
                    </h2><p class="hometown-announcement-section-note">Default content for your announcement bar and API location alerts.</p></div>


                    <label class="hometown-announcement-enable">

                        <input
                            type="checkbox"
                            name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[enabled]"
                            value="1"
                            <?php checked(! empty($settings['enabled'])); ?>>

                        Enable Announcement Bar

                    </label>

                </div>


                <div class="hometown-announcement-card__body">

                    <div class="hometown-announcement-grid">


                        <div class="hometown-announcement-field hometown-announcement-field--full">

                            <label>
                                Announcement Title
                            </label>

                            <input
                                type="text"
                                name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[default][title]"
                                value="<?php echo esc_attr($default['title'] ?? ''); ?>"
                                placeholder="STORM DAMAGE ALERT">

                        </div>


                        <div class="hometown-announcement-field hometown-announcement-field--full">

                            <label>
                                Announcement Message
                            </label>

                            <textarea
                                rows="3"
                                name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[default][message]"><?php echo esc_textarea($default['message'] ?? ''); ?></textarea>

                            <span class="hometown-announcement-help">
                                Used when no URL, city, or state rule matches.
                            </span>

                        </div>


                        <div class="hometown-announcement-field">

                            <label>
                                CTA Label
                            </label>

                            <input
                                type="text"
                                name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[default][cta_label]"
                                value="<?php echo esc_attr($default['cta_label'] ?? ''); ?>">

                        </div>


                        <div class="hometown-announcement-field">

                            <label>
                                CTA URL
                            </label>

                            <input
                                type="text"
                                name="<?php echo esc_attr(HOMETOWN_ANNOUNCEMENT_OPTION); ?>[default][cta_url]"
                                value="<?php echo esc_attr($default['cta_url'] ?? ''); ?>"
                                placeholder="/get-a-quote/">

                        </div>

                    </div>

                </div>

            </div>


            <!-- LOCATION RULES -->

            <div class="hometown-announcement-card">

                <div class="hometown-announcement-card__header">

                    <div><h2>
                        Location Rules
                    </h2><p class="hometown-announcement-section-note">Manual overrides for specific cities, states, or page URLs.</p></div>


                    <button
                        type="button"
                        class="button button-primary"
                        id="hometown-add-rule">
                        + Add Location Rule
                    </button>

                </div>


                <div class="hometown-announcement-card__body">

                    <div id="hometown-location-rules">

                        <?php
                        if ($rules) :

                            foreach (
                                $rules
                                as $index => $rule
                            ) {

                                hometown_render_announcement_rule(
                                    $index,
                                    $rule
                                );
                            }

                        else :
                        ?>

                            <div class="hometown-announcement-empty">
                                No location rules yet.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <?php
            submit_button(
                'Save Announcement Settings'
            );
            ?>

        </form>

    </div>

<?php
}
