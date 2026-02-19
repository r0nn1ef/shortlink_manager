# Configuration Reference

Complete reference for all settings, permissions, and configuration options in Shortlink Manager.

---

## General Settings

**Location:** `/admin/config/system/shortlink`
**Config object:** `shortlink_manager.settings`

| Setting | Key | Default | Description |
|---|---|---|---|
| Path Prefix | `path_prefix` | `go` | URL segment before each slug. Max 8 chars. No slashes. Example: `go` makes URLs like `/go/xE4iqh`. **Cannot be changed after shortlinks are created without breaking existing URLs.** |
| Path Length | `path_length` | `6` | Number of random characters in auto-generated slugs. Range: 4–12. |
| Redirect Status | `redirect_status` | `301` | HTTP redirect code: `301` (permanent), `307` (temporary), or `308` (permanent, method-preserving). |
| Available Entity Types | `available_entity_types` | `{}` | Entity types enabled for shortlink support (auto-generation, sidebar preview, tokens). |

> **Path prefix critical note:** The full path (prefix + slug) is stored in the `path` field of each shortlink entity. If you change the prefix after shortlinks exist, all existing URLs break and there is no automatic migration. Always set the prefix before creating any shortlinks.

---

## Auto-Generate Settings

**Location:** `/admin/config/system/shortlink/auto-generate`
**Config path:** `shortlink_manager.settings` → `auto_generate_settings.{entity_type}.{bundle}`

Per-bundle settings:

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Whether new entities of this bundle automatically get shortlinks on insert. |
| `utm_set` | `[]` | Array of UTM Set machine names. One shortlink is created per set. |
| `default_utm_set` | `''` | UTM set pre-selected when creating a shortlink manually for this bundle, and used by bulk generation. |

---

## Expiration Settings

**Location:** `/admin/config/system/shortlink/expiration`
**Config path:** `shortlink_manager.settings` → `expiration.*`

| Key | Default | Description |
|---|---|---|
| `expiration.enabled` | `false` | Master toggle. When `true`, cron queues active shortlinks with expiration rules for processing. |
| `expiration.default_expiration_type` | `none` | Type pre-selected on the shortlink edit form: `none`, `time`, `max_clicks`, or `inactive`. |
| `expiration.default_expire_days` | `0` | Default days until expiration when type is `time`. |
| `expiration.default_max_clicks` | `0` | Default click limit when type is `max_clicks`. |
| `expiration.default_inactive_days` | `0` | Default inactivity days when type is `inactive`. |
| `expiration.click_log_retention_days` | `90` | Days to retain click records in `shortlink_clicks`. Set to `0` to keep indefinitely. |

> **Expiration enforcement happens in two places:** The redirect controller checks expiration on every access (real-time enforcement), and cron processes a queue to clean up expired shortlinks that have not been accessed. Both are required for complete coverage — enable cron expiration processing to handle shortlinks that expire due to inactivity or time but are never accessed again.

---

## Per-Shortlink Fields

These fields are set on individual shortlink entities, not in global settings.

| Field | Type | Description |
|---|---|---|
| `label` | string | Human-readable name. Required. |
| `path` | string | Full path including prefix (e.g., `go/xE4iqh`). Unique. Set on creation, not editable. |
| `description` | text | Administrative notes. Optional. |
| `status` | boolean | Active (`1`) or disabled (`0`). Disabled shortlinks return 404. |
| `utm_set` | entity reference | UTM Set to apply at redirect time. Optional. |
| `target_entity_type` | string | Drupal entity type machine name (e.g., `node`). |
| `target_entity_id` | string | Entity ID of the target. |
| `destination_override` | string | Manual URL or internal path. Mutually exclusive with target entity fields. Supports tokens. |
| `click_count` | integer | Running click total. Read-only in UI. |
| `last_accessed` | timestamp | Time of most recent click. Read-only in UI. |
| `expires_at` | datetime | Hard expiration date/time. Set expiration type to `time` to activate. |
| `max_clicks` | integer | Maximum click limit. `0` = unlimited. Set expiration type to `max_clicks` to activate. |
| `expire_if_inactive_days` | integer | Days without a click before expiry. `0` = disabled. Set expiration type to `inactive` to activate. |
| `has_broken_destination` | boolean | Flagged by `drush shortlink:check-destinations`. Informational. |

---

## Permissions

**File:** `shortlink_manager.permissions.yml`

| Permission | Description | Notes |
|---|---|---|
| `administer shortlink` | Full CRUD access to shortlinks and all shortlink settings. | Restricted access. Grant only to trusted editors and admins. |
| `administer utm set` | Full CRUD access to UTM Sets. | Restricted access. Grant only to trusted users. |
| `create shortlink` | Create new shortlink entities only. | Does not grant edit or delete. |
| `view shortlink` | View individual shortlink entity pages and download QR codes. | Does not grant admin access to the listing. |
| `view shortlink block` | See the Shortlink Block on pages. | Typically granted to authenticated editors and admins. |
| `edit any shortlink` | Edit any existing shortlink. | Does not grant create or delete. |
| `delete any shortlink` | Delete any shortlink. | Does not grant create or edit. |
| `generate bulk shortlinks` | Access to the bulk generate form. | Grant to content administrators. |
| `view shortlink dashboard` | View the Shortlink Dashboard analytics block. | Grant to marketing staff and administrators. |
| `use shortlinks` | Follow shortlink redirect URLs. | **Granted to all roles by default (including anonymous).** Remove from anonymous only if shortlinks should require login. |

### Recommended Permission Sets by Role

**Anonymous User:** `use shortlinks` only (default)

**Authenticated User / Editor:** `use shortlinks`, `view shortlink block`

**Marketing / Content Manager:** `use shortlinks`, `view shortlink`, `create shortlink`, `edit any shortlink`, `generate bulk shortlinks`, `view shortlink dashboard`, `view shortlink block`

**Administrator:** All permissions

---

## Shortlink Entity Expiration Types

The **Expiration Method** dropdown on the shortlink edit form sets which rule is active:

| Method | Stored Fields | Behavior |
|---|---|---|
| `none` | All expiration fields cleared to zero | The shortlink never expires automatically |
| `time` | `expires_at` set to a specific datetime | Expires when the current time passes the date/time |
| `max_clicks` | `max_clicks` set to a positive integer | Expires after that many total clicks |
| `inactive` | `expire_if_inactive_days` set to a positive integer | Expires if not clicked for this many days (checks from `last_accessed` or from `created` if never clicked) |

Only one expiration method is active at a time. Selecting a method clears the fields for the other methods on save.

---

## UTM Set Fields

| Field | Required | Description |
|---|---|---|
| `id` | Yes | Machine name. Lowercase, no spaces. Set on creation only. |
| `label` | Yes | Human-readable label shown in all dropdowns. |
| `description` | Yes | Administrative description. |
| `utm_source` | Yes | `utm_source` parameter value. |
| `utm_medium` | Yes | `utm_medium` parameter value. |
| `utm_campaign` | Yes | `utm_campaign` parameter value. |
| `utm_term` | No | `utm_term` parameter value. |
| `utm_content` | No | `utm_content` parameter value. |
| `custom_parameters` | No | Additional query parameters. One `key:value` per line. Tokens supported in values. |
| `status` | Yes | Whether the UTM Set is active. |

### Value Sanitization

UTM parameter values (and custom parameter values) are processed at redirect time:
1. Token replacement is performed if the value contains `[`
2. Non-alphanumeric/underscore characters are replaced with `_`
3. Multiple consecutive underscores are collapsed to one
4. Leading and trailing underscores are removed
5. The value is lowercased

This means a token output of `"John Smith"` becomes `john_smith` in the final URL.

---

## Redirect Behavior Details

### HTTP Headers

Every redirect response includes:
```
X-Robots-Tag: noindex, nofollow
```

This prevents search engines from crawling or indexing the shortlink paths themselves.

### External vs Internal URLs

- Destination URLs beginning with `http://` or `https://` use `TrustedRedirectResponse`
- Destination URLs beginning with `/` use `RedirectResponse` (internal Drupal redirect)

### Route Registration

The redirect route (`shortlink_manager.redirect`) is registered dynamically at runtime by `ShortlinkRouteSubscriber`. The route path is set to `/{path_prefix}/{slug}` where `path_prefix` comes from `shortlink_manager.settings`.

**After changing the path prefix, you must clear all caches (`drush cr`) for the new route path to activate.** Until caches are cleared, the old path prefix continues to work.

---

## Click Tracking Database Table

**Table:** `shortlink_clicks`

| Column | Type | Description |
|---|---|---|
| `click_id` | serial (auto-increment) | Unique click event ID |
| `shortlink_id` | integer unsigned | ID of the shortlink that was clicked |
| `timestamp` | integer unsigned | Unix timestamp of the click |
| `referrer` | varchar(2048) | HTTP Referer header (truncated to 2048 chars) |
| `user_agent` | varchar(512) | User-Agent header (truncated to 512 chars) |
| `ip_hash` | varchar(64) | SHA-256 hash of the client IP. **Never stored in plaintext.** |

**Indexes:** `shortlink_id`, `timestamp`, `(shortlink_id, timestamp)`

---

## Configuration Import/Export

All Shortlink Manager settings are stored in Drupal's configuration management system. Export configuration after making changes:

```bash
drush cex
```

This exports `shortlink_manager.settings` and all `shortlink_manager.utm_set.*` config entities to your configuration sync directory, making them deployable across environments via `drush cim`.

Note: Individual **shortlink entities** (the link records themselves) are **content entities**, not configuration. They are not exported by `drush cex` and are managed via content staging or manual migration.
