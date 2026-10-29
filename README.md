# Hometown Announcement Bar

## API Location Rules

Open **Announcement Bars → API Location Rules**, enter the endpoint URL, username and password, and save. The default endpoint is the Hometown automation announcement webhook. No credentials are bundled with this plugin. A blank password preserves the existing saved password; the saved value is never sent back to the admin form.

Click **Fetch now** to import the first snapshot. Valid, conflict-free API rules are automatically approved. You can edit them with **Save and enable**, or stop display with Disable or Reject. The announcement master switch, matching and schedules still apply.

Use **Preview example fetch** on the same page to inspect the five sample city rules. Each includes **Preview in announcement bar**, which opens the homepage in a new tab using the existing announcement shortcode and global copy. The signed preview works only for logged-in administrators, bypasses normal rule selection and the master switch for that request, and includes an exit notice. Preview responses send no-cache headers. The theme must render the announcement shortcode, and the global message must be populated. No sample rules or reports are saved. Test actual authentication and imports separately with **Fetch now**, which shows a spinner and prevents duplicate clicks while the request runs.

Imports run hourly through WP-Cron once credentials are saved. On sites with low traffic, configure an external WordPress cron runner if strict hourly execution is needed. Frontend requests only read saved rules; they do not call the API.

The API uses HTTPS GET with HTTP Basic Authentication. Redirects are disabled to prevent credential forwarding. Credentials are stored in a non-autoloaded WordPress option and used only on the server; protect database access and backups as with other WordPress secrets.

### Assumed response contract

Detected storm locations can now use the confirmed compact shape below. `name` becomes a Texas (`TX`) city rule, with the current global announcement message and CTA. `lat`, `lng` and `radius` are ignored for matching. A blank global message prevents importing these entries; set the global announcement copy first. The API may return a root JSON array or the existing `{ "ok": true, "locations": [...] }` wrapper. HTTP responses must contain JSON, without a JavaScript `const locations =` declaration.

```json
[
  { "name": "San Antonio", "lat": 29.4241, "lng": -98.4936, "radius": 50 },
  { "name": "Boerne", "lat": 29.7947, "lng": -98.7319, "radius": 40 },
  { "name": "Austin", "lat": 30.2672, "lng": -97.7431, "radius": 50 },
  { "name": "Marble Falls", "lat": 30.5782, "lng": -98.2728, "radius": 40 },
  { "name": "Aransas Pass", "lat": 27.9095, "lng": -97.1499, "radius": 40 }
]
```

The earlier explicit rule shape remains supported for compatibility:

```json
{
  "ok": true,
  "locations": [
    {
      "id": "optional-stable-event-id",
      "match_type": "city",
      "state": "TX",
      "city": "Boerne",
      "message": "Recent hail and high winds reported in parts of Texas. Boerne",
      "cta_label": "",
      "cta_url": "",
      "schedule_type": "always",
      "start_date": "",
      "end_date": ""
    }
  ]
}
```

Supported match types are `city`, `state`, and `url` (which requires `url_slug`). City and state entries require a US state code. Dates use `YYYY-MM-DD`; `schedule_type` accepts `always` or `dates`. Blank CTA values inherit the manual global CTA. Other top-level response sections are ignored. The plugin does not expose `/hometown/v1/announcement`.

Live authentication and the actual populated HTTP response remain unverified. The mapper is isolated in `includes/api-locations.php` for future field adjustments.

### Automatic activation, expiration and history

- API rules expire exactly 14 days after their first successful import (UTC). Hourly fetches, content updates, returning locations and manual enabling do not extend that deadline. Frontend selection enforces expiration even when cron is delayed or the API fails. Earlier API schedule end dates still apply. Existing entries use their original first-detected time, and existing pending entries become automatically approved on the next successful sync. A genuinely new event should have a new stable API ID.
- Manual rules win within the same match type. The overall order remains URL, city, state, global.
- Unchanged source data preserves local edits and review status. Source changes replace the editable fields and are automatically approved unless conflicting or expired.
- API IDs identify events when supplied. Otherwise normalized match type and location/URL identify them. Identical duplicates collapse. Conflicting duplicates block approval until resolved by the source.
- A location missing from a successful snapshot becomes inactive. A returning location is automatically approved only within its original 14-day lifetime. A successful empty `locations` array deactivates all API rules.
- Invalid snapshots, invalid location entries, HTTP errors, authentication failures, and timeouts preserve the previous successful rules. A malformed entry rejects the whole snapshot to avoid partial imports deactivating valid rules.
- Reports list event and review history, with status/location filters and links to review rules. Repeated unchanged imports refresh last-seen timestamps without adding history rows. Status describes the recorded event; follow its link for the rule's current status. Timestamps are UTC.
- History remains in the dedicated `${wpdb->prefix}hometown_announcement_events` table until an administrator uses **Delete all report history**. Deactivation clears cron but retains settings, rules, and history.

## Local verification

Reports includes **Export CSV** for all events matching the applied status and location filters, across every page. Apply filters before exporting; clear them to export all history. Downloads contain event, location, message, schedule/expiration and UTC timestamps, use UTF-8, and require administrator access and a nonce. Credentials are excluded and spreadsheet formula prefixes are neutralized. Exporting does not change report history or rules.

Run `php tests/api-locations-test.php` from this plugin directory. The isolated harness uses WordPress doubles and tests import lifecycle, matching, schedules, credential handling and fetch failures without calling the webhook or changing a WordPress database.

Before production use, verify authentication and the real populated response with **Fetch now**, check the Reports screen and database table, and verify approved announcements in a browser with the site's caching and Cloudflare location headers.
