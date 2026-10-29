# Hometown Announcement Bar

A WordPress plugin for global announcements, manual location rules, and automatically imported storm-location alerts.

**Version:** 2.3.0

Manual rules and API rules are stored separately. The frontend uses the existing announcement shortcode and saved rules; it does not fetch the automation API during page rendering.

## Quick start

1. Install this repository in `wp-content/plugins/hometown-location-announcement-bar/` and activate **Hometown Announcement Bar**.
2. Open **Announcement Bars** and set the global title, message, CTA label, and CTA URL.
3. Enable the announcement bar and save.
4. Render the shortcode in your page or header. If your theme already renders it, do not add a second copy.
5. Add manual rules or configure **Announcement Bars → API Location Rules**.

```text
[hometown_announcement]
```

In a PHP theme template:

```php
echo do_shortcode('[hometown_announcement]');
```

The markup uses the `site-header__announcement` class family. The Hometown theme supplies its presentation; another theme will need corresponding CSS.

## Admin pages

All settings, reports, and preview actions require the `manage_options` capability.

| Page | Purpose |
| --- | --- |
| Announcement Bars | Global announcement, master enable switch, and manual location rules. |
| API Location Rules | API credentials, Fetch now, sync status, sample previews, and imported rules. |
| Reports | Current-rule summaries, event history, filters, review links, and CSV export. |

### Manual location rules

Use **Add Location Rule** to create a city, state, or page-URL rule. Cards open a popup editor with a live announcement preview. Enable, duplicate, and delete controls sit at the top of each card.

- **City:** matches the visitor city, with a state comparison when both the rule and visitor provide a state.
- **State:** matches the visitor state code.
- **URL:** matches a complete path segment. For example, `boerne` matches `/trusted/boerne/`.
- **Schedule:** Always Active or a start/end date range. Date checks use the WordPress site timezone and include both boundary dates.
- **CTA:** blank rule CTA fields inherit the global values.

Popup edits remain unsaved until you click **Save Announcement Settings**. The card's **Preview announcement** link opens the saved rule on the website, so save new or edited rules first.

Pagination retains all fields in the settings form, including edits on other pages. Open **Screen Options → Location Rules per page** to choose 1–100 cards per page; the default is 6, and the choice is saved per administrator.

## Matching order

The first eligible rule wins in this order:

1. URL rules.
2. City rules.
3. State rules.
4. Global announcement.

Within each match type, manual rules precede API rules. Disabled, missing, conflicting, or expired API rules cannot display. The master switch controls normal frontend display.

**An expired or disabled API rule can fall back to a manual rule or the global announcement.** It does not necessarily hide the entire bar. Set global copy with that fallback behavior in mind.

### Visitor location

City and state matching use Cloudflare request headers, including `CF-IPCity` and `CF-Region-Code`. These must be available on the deployed site; installing the plugin does not configure Cloudflare geolocation.

URL matching does not require visitor geolocation. For integrations that supply location another way, the plugin exposes the `hometown_announcement_visitor_location` filter.

When `WP_DEBUG` is enabled, administrators can use `hometown_test_city` and `hometown_test_state` query parameters for local matching checks.

## API connection

Open **API Location Rules**, enter the endpoint URL, username, and password, and save.

The default endpoint is:

```text
https://automation.hometownroofingtx.com/webhook/htr-announcement-api
```

No credentials are bundled in this repository. A blank password field preserves the saved password. Credentials are used server-side and stored in a non-autoloaded WordPress option.

| Request setting | Behavior |
| --- | --- |
| Method | HTTPS GET |
| Authentication | HTTP Basic Authentication |
| Expected status | HTTP 200 |
| Body | JSON |
| Timeout | 30 seconds |
| Response size limit | 2 MB |
| Redirects | Disabled to prevent credential forwarding |

Click **Fetch now** for an immediate import. The button displays a loading state and blocks duplicate clicks while the request runs. Last-attempt, last-success, location count, and error information appear on the page.

### Supported location formats

The compact format represents **detected storm locations**, not a list of monitoring areas:

```json
[
  { "name": "San Antonio", "lat": 29.4241, "lng": -98.4936, "radius": 50 },
  { "name": "Boerne", "lat": 29.7947, "lng": -98.7319, "radius": 40 },
  { "name": "Austin", "lat": 30.2672, "lng": -97.7431, "radius": 50 },
  { "name": "Marble Falls", "lat": 30.5782, "lng": -98.2728, "radius": 40 },
  { "name": "Aransas Pass", "lat": 27.9095, "lng": -97.1499, "radius": 40 }
]
```

`name` becomes a Texas (`TX`) city rule. These rules use the **current global message and CTA**. Coordinates and radius do not affect matching. Set a nonempty global message before importing compact entries.

A wrapped response is also accepted:

```json
{
  "ok": true,
  "locations": [
    { "id": "storm-boerne-2026-10-30", "name": "Boerne" }
  ]
}
```

For explicit messages, schedules, or other states, use the full rule shape:

```json
{
  "ok": true,
  "locations": [
    {
      "id": "storm-boerne-2026-10-30",
      "match_type": "city",
      "state": "TX",
      "city": "Boerne",
      "message": "Recent hail and high winds reported in Boerne.",
      "cta_label": "",
      "cta_url": "",
      "schedule_type": "dates",
      "start_date": "2026-10-30",
      "end_date": "2026-11-05"
    }
  ]
}
```

Full rules support `city`, `state`, and `url` match types. URL rules require `url_slug`; city and state rules require a valid US state code. Dates use `YYYY-MM-DD`, and `schedule_type` accepts `always` or `dates`. Blank CTA fields inherit the global CTA.

The response must contain JSON, without a JavaScript `const locations =` declaration. Other top-level sections, such as `storm`, `global_announcement`, and `wordpress_settings`, are ignored. This plugin does not provide `/wp-json/hometown/v1/announcement`.

### Automatic activation and expiration

- Valid, conflict-free imports are automatically approved. No review is required before normal matching can display them.
- API rules expire **14 days after their first successful import**, in UTC. Manual rules do not receive this automatic expiration.
- Unchanged hourly fetches, source changes, returning locations, and manual enabling do not extend the original deadline.
- An earlier explicit schedule end date still applies. Frontend selection checks expiration even if cron is delayed or the API fails.
- Unchanged source data preserves local edits and decisions such as Disable or Reject. Changed source content replaces editable fields and is automatically approved unless conflicting or expired.
- A location missing from a successful snapshot becomes inactive. A returning location can reactivate only within its original lifetime.
- A valid empty array, or a successful wrapper containing `"locations": []`, deactivates imported rules.
- Authentication failures, timeouts, malformed JSON, `ok: false`, or invalid entries preserve the last successful snapshot. One invalid entry rejects the entire snapshot rather than partially deactivating valid rules.

API IDs identify events when present; otherwise normalized match type and location identify them. Identical duplicates collapse, while conflicting entries sharing an identity block approval until the source resolves them.

**Use a new stable API ID for each genuinely new storm event.** Without event IDs, another storm in the same city reuses the previous identity and its original expiration.

## Website and popup previews

Under **Preview example fetch**, each sample city has a **Preview announcement** link. Manual cards also provide signed website preview links:

```text
/?htr_announcement_example=0&_htr_preview_nonce=<nonce>
/?htr_announcement_rule=0&_htr_preview_nonce=<nonce>
```

Website previews require a logged-in administrator and a valid nonce. They open the homepage and override normal matching and the master switch for that request. The theme must render the shortcode, and the selected message must not be blank.

Preview responses send no-cache headers and show an exit notice. Examples do not call the API, create rules, change sync status, or add reports. Manual website previews read saved rules; the popup preview reflects unsaved edits.

## Close button

Clicking X removes the announcement and remembers that dismissal in `sessionStorage` for the current tab session. A different announcement content fingerprint can appear again. If storage is unavailable, the button still closes the current bar.

Preview dismissals do not persist or hide normal visitor announcements.

## Reports and CSV export

Reports includes current-rule summary counts and a paginated event history. Summary counts describe saved rules and are independent of history filters; they are not a count of visitor impressions.

History records imports, content changes, approvals/edits, disabled or rejected rules, disappearance, and expiration. Unchanged fetches update last-seen timestamps without adding a new event row. History status describes the recorded event; use its location link to inspect the current rule. Report timestamps are UTC.

Apply status and location filters, then click **Export CSV** to download all matching events across every page. Clear filters to export all history. Downloads contain event IDs, locations, messages, schedules/expiration, statuses, and timestamps. Credentials are excluded, and spreadsheet formula prefixes are neutralized.

**Delete all report history** permanently clears history after confirmation but retains imported rules and settings.

## Background sync and storage

The `hometown_announcement_api_hourly` WP-Cron event is scheduled hourly once a username and password are saved. Each run checks expiration, makes one API request, and synchronizes saved rules and history. A lock prevents overlapping fetch/review operations.

WP-Cron depends on site traffic. For predictable scheduling, use a server cron runner appropriate to your WordPress deployment. Rendering the announcement uses saved data and never calls the automation webhook.

| Storage | Purpose |
| --- | --- |
| `hometown_announcement_settings` option | Global settings and manual rules |
| `hometown_announcement_api_config` option | Endpoint and credentials |
| `hometown_announcement_api_rules` option | Imported rules and lifecycle state |
| `hometown_announcement_api_sync` option | Sync timestamps, count, and errors |
| `{prefix}hometown_announcement_events` table | Persistent report history |

Deactivation clears the cron event and retains settings, rules, and history. Protect database access and backups because API credentials are stored there.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| No announcement | Master switch, nonempty message, rule state, schedule, API expiration, and whether the theme renders the shortcode. |
| City rule does not match | Cloudflare location headers, city spelling, state data, and any higher-priority URL rule. |
| Old announcement persists | Full-page/CDN cache and current global/manual fallback copy. |
| Fetch fails | HTTPS endpoint, Basic Auth credentials, response status, JSON format, and sync error text. |
| API rule remains expired | Repeated snapshots cannot extend it; use a new event ID for a new storm. |
| Website preview fails | Log in as an administrator and generate a fresh link. Save manual rule changes before previewing. |

Test geolocation-aware output with your actual page cache/CDN configuration. A cached response may otherwise serve one visitor's selected announcement to another.

## Local validation

From the plugin directory:

```sh
php tests/api-locations-test.php
php -l hometown-location-announcement-bar.php
php -l includes/api-locations.php
```

The isolated suite currently contains 135 checks. It covers imports, expiration, matching, CTA inheritance, credentials, failed fetches, permissions, preview links, reports, CSV handling, and Screen Options using WordPress doubles. It makes no live API requests or WordPress database changes.

Live API authentication, actual response compatibility, browser interactions, cron execution, and cache-sensitive location rendering require deployed integration verification.

## Project layout

```text
hometown-location-announcement-bar.php  Plugin bootstrap, rendering and manual settings UI
includes/api-locations.php             API mapping, lifecycle, previews, reports and export
assets/                               Frontend dismissal script and supporting assets
tests/api-locations-test.php           Isolated local checks
```
