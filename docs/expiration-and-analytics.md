# Expiration & Analytics

This document covers click tracking, expiration rules, the analytics dashboard, and click data retention.

---

## Click Tracking

Every time a visitor follows a shortlink redirect, the module records:
- The shortlink's `click_count` field is incremented by 1
- `last_accessed` timestamp is updated to the current time
- A detailed record is appended to the `shortlink_clicks` table

### What Is Recorded

| Data | Storage | Privacy |
|---|---|---|
| Timestamp | Unix timestamp | No privacy concern |
| Referring URL | Up to 2048 characters | Contains URLs from external sites |
| User-Agent | Up to 512 characters | Browser and OS information |
| IP Address | **SHA-256 hash only** | The actual IP is never stored; only its hash |

> **Privacy note:** IP addresses are one-way hashed using SHA-256. The original IP address cannot be recovered from the stored hash. This approach supports privacy compliance goals while still allowing detection of repeat clicks from the same source.

### Two Click Data Sources

The module maintains click data in two places:

| Source | Purpose | Scope |
|---|---|---|
| `click_count` field on the shortlink entity | Fast counter for total all-time clicks | All time, single integer |
| `shortlink_clicks` table | Detailed per-click records for analytics | Subject to retention period |

The entity field (`click_count`) is a running total that is never reset. The `shortlink_clicks` table contains individual click records that are purged according to the retention setting.

---

## Expiration Settings

Navigate to **Administration → Configuration → System → Shortlink Expiration** (`/admin/config/system/shortlink/expiration`).

### Master Enable Toggle

The **Expiration enabled** checkbox is the master switch for cron-based expiration processing. When disabled, no shortlinks are automatically deactivated by cron — but **expiration is still enforced at redirect time** regardless of this setting.

> Even with the master toggle off, if a visitor accesses a shortlink that has an expiration rule and the rule is met, the shortlink is immediately disabled and the visitor receives a 404.

### Default Expiration Type

Sets the method pre-selected in the expiration dropdown when creating a new shortlink. Does not affect existing shortlinks.

| Type | Behavior |
|---|---|
| `none` | Shortlinks have no automatic expiration by default |
| `time` | Pre-fill the expiry date/time field |
| `max_clicks` | Pre-fill the maximum click limit field |
| `inactive` | Pre-fill the inactivity days field |

### Default Values

When a default expiration type other than `none` is selected, you can also set default values for the corresponding field:

- **Default expire days** — Number of days from creation before expiry (used with `time` type)
- **Default max clicks** — Click limit for new shortlinks (used with `max_clicks` type)
- **Default inactive days** — Inactivity period for new shortlinks (used with `inactive` type)

These defaults apply to new shortlinks only. They can be overridden on individual shortlinks.

---

## Per-Shortlink Expiration Rules

Expiration is configured on each individual shortlink via the **Expiration** collapsible section in the shortlink edit form.

### Expiration Type: Time

Set a specific date and time after which the shortlink deactivates. The shortlink remains active until that moment and then immediately becomes inactive.

Use case: Time-limited promotions (e.g., a sale ending on a specific date).

### Expiration Type: Max Clicks

Set a maximum number of total clicks. The shortlink deactivates after that many clicks have been recorded.

Use case: Limited-availability offers (e.g., first 100 registrations).

> The `click_count` field is what is checked against `max_clicks`. The count is incremented at redirect time, so the exact behavior is: on the click that causes `click_count` to equal or exceed `max_clicks`, the redirect still succeeds but the next check (either at the next access or next cron run) will find the link expired.

### Expiration Type: Inactive

Set a number of days of inactivity. If the shortlink receives no clicks for this many consecutive days, it deactivates.

Inactivity is measured from:
- `last_accessed` if the shortlink has been clicked at least once
- `created` if the shortlink has never been clicked

Use case: Automatically clean up old or forgotten shortlinks.

---

## How Expiration Is Enforced

Expiration is enforced in two independent ways:

### 1. At Redirect Time (Real-Time)

`ShortlinkRedirectController` checks `isExpired()` on every access. If the rule is met, the shortlink is immediately set to `status = FALSE`, saved, and the visitor receives a 404. This happens even if cron has not run recently.

### 2. Via Cron (Background Processing)

When the **Expiration enabled** master toggle is on:

1. Cron queries all active shortlinks that have at least one expiration rule set
2. Each qualifying shortlink ID is added to the `shortlink_expiration` queue
3. The `ShortlinkExpirationWorker` queue worker processes the queue (up to 60 seconds of work per cron run)
4. For each item, `isExpired()` is called; if expired, the shortlink is disabled and a notice is logged

This background processing handles shortlinks that expire due to time or inactivity but are not being actively accessed.

---

## Click Data Retention

**Setting:** `expiration.click_log_retention_days` (default: `90`)

On every cron run, click records in `shortlink_clicks` older than this number of days are permanently deleted. This keeps the table from growing unbounded.

- Set to `0` to retain click records indefinitely (not recommended for high-traffic sites)
- The `click_count` field on each shortlink entity is **not affected** by purging — it retains the all-time total

To adjust the retention period, go to **Administration → Configuration → System → Shortlink Expiration** and update the **Click log retention** field.

---

## Analytics Dashboard

The **Shortlink Dashboard** block provides a 30-day overview. Place it via **Structure → Block layout**.

### Data Shown

**Total Clicks (30-day):** Count of all click events in the last 30 days across all shortlinks.

**Top Shortlinks Table:**
| Column | Description |
|---|---|
| Label | Shortlink's human-readable label |
| Path | The shortlink path (e.g., `go/xE4iqh`) |
| Clicks | Click count for this shortlink in the last 30 days |

Shows the top 10 shortlinks by click volume.

**Recent Clicks Table:**
| Column | Description |
|---|---|
| Shortlink | Label of the shortlink that was clicked |
| Time | Formatted timestamp of the click |
| Referrer | First 50 characters of the HTTP Referer header |

Shows the 10 most recent click events globally.

The dashboard block refreshes every 5 minutes (cache max-age: 300 seconds).

Requires the `view shortlink dashboard` permission.

---

## Per-Shortlink Analytics

Each shortlink entity shows `click_count` and `last_accessed` values in the edit form (view-only) and in the shortlink listing.

For time-range click data on a specific shortlink, use the `ShortlinkClickTracker::getClicksByShortlink()` method programmatically, or query the `shortlink_clicks` table directly.

---

## CSV Export

The default shortlink Views listing includes a CSV export display (provided by `views_data_export`). Use this to export shortlink data including click counts for offline analysis in spreadsheet applications.
