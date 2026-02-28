# Administrator Guide

This guide covers the day-to-day management of shortlinks, UTM sets, bulk operations, and site-builder integrations.

---

## Shortlink Listing

Navigate to **Administration → Content → Shortlinks** (`/admin/content/shortlink`).

This Views-based listing shows all shortlinks on the site with columns for label, path, status, click count, and last accessed date. Available actions:

- **Add shortlink** — Create a new shortlink manually
- **Bulk generate shortlinks** — Create shortlinks for existing content in bulk
- **Enable / Disable / Delete** — Bulk operations via checkboxes

The listing supports Views-standard filtering, sorting, and export (CSV via `views_data_export`).

---

## Creating Shortlinks Manually

Click **Add shortlink** from the listing page.

### Required Fields

| Field | Description |
|---|---|
| **Label** | Human-readable name shown in listings and analytics. Example: `Spring Sale 2026 - Email Campaign` |
| **Destination** | Either a **Target Entity** (entity type + entity ID) or a **Destination Override** URL/path — not both |

### Custom vs Auto-Generated Path

- **Leave Custom Path empty** to have the system generate a random slug (e.g., `go/xE4iqh`) using the configured path length
- **Enter a custom slug** (e.g., `spring-sale`) to create a vanity URL (`go/spring-sale`)

Custom slugs:
- May only contain letters, numbers, hyphens (`-`), and underscores (`_`)
- Must not conflict with an existing shortlink, Drupal path alias, or Drupal route
- Are validated on save and rejected with an error message if there is a collision

### Destination Options

**Option A — Target Entity:**
Set the entity type (e.g., `node`) and entity ID (e.g., `42`). The shortlink resolves to that entity's canonical URL at redirect time. If the entity is later deleted or its URL changes, the redirect updates automatically.

**Option B — Destination Override:**
Enter any internal path (starting with `/`) or external URL. Supports Drupal tokens for dynamic values (e.g., `/products/[node:field_sku]`).

> You cannot set both a target entity and a destination override on the same shortlink. The form disables the other group of fields automatically.

### UTM Set

Select a UTM Set to append UTM tracking parameters to the destination URL. If left empty, the redirect happens without any UTM parameters. See [UTM Sets](#utm-sets) below.

### Status

The **Status** checkbox controls whether the shortlink is active. Disabled shortlinks return a 404 to visitors.

### Expiration

Expand the **Expiration** section to configure when this shortlink should automatically deactivate. See [Expiration & Analytics](expiration-and-analytics.md).

---

## Editing Shortlinks

From the listing, click a shortlink's label to open the edit form.

- The **path** field is shown (read-only display) but cannot be changed to a different value — paths are permanent once set
- All other fields including status, UTM set, destination, and expiration are editable

---

## Disabling and Deleting Shortlinks

- **Disable** a shortlink to make it temporarily inactive without deleting the record. Useful for seasonal campaigns. Disabled shortlinks return a 404.
- **Delete** a shortlink to permanently remove it. Deletion is irreversible. The shortlink URL immediately returns a 404 after deletion.

Both operations are available as single-entity actions (via the edit form or the delete route) and as bulk operations from the listing page.

---

## UTM Sets

UTM Sets define reusable groups of UTM tracking parameters. Each shortlink can reference one UTM set; the parameters are appended to the destination URL when the redirect happens.

Navigate to **Administration → Structure → UTM Sets** (`/admin/structure/utm-set`).

### Creating a UTM Set

Click **Add UTM Set**. Fill in:

| Field | Required | Description |
|---|---|---|
| **Label** | Yes | Human name shown in dropdowns |
| **Machine Name** | Yes | Lowercase identifier, set on creation, cannot be changed |
| **Description** | Yes | Administrative note about this set's purpose |
| **UTM Source** | Yes | e.g., `newsletter`, `google`, `facebook` |
| **UTM Medium** | Yes | e.g., `email`, `cpc`, `social` |
| **UTM Campaign** | Yes | e.g., `spring-sale-2026` |
| **UTM Term** | No | Paid keyword term |
| **UTM Content** | No | Ad variant identifier |
| **Custom Parameters** | No | Additional query parameters; one `key:value` per line |

### Custom Parameters

Custom parameters allow non-standard query string additions. Enter one per line in `parameter_name:value` format:

```
sales_rep:john-doe
region:north-america
```

Tokens are supported in values:

```
author:[node:author:name]
nid:[node:nid]
```

Token output is sanitized at redirect time: spaces and special characters are replaced with underscores, values are lowercased.

> **Important:** When a UTM Set is saved, all its parameter keys are automatically added to the global `passthrough_parameters` configuration list. This ensures these keys are recognized site-wide.

### UTM Parameter Alteration Hook

Developers can modify UTM parameters at redirect time using `hook_shortlink_manager_utm_parameters_alter(&$parameters, $utm_set)`. This allows dynamic parameter values based on context.

---

## Auto-Generation

Navigate to **Administration → Configuration → System → Shortlink Auto Generate**.

When enabled for a bundle, a shortlink is automatically created each time a new entity of that bundle is saved. The shortlink points to the new entity's canonical URL.

### Configuration per Bundle

| Setting | Description |
|---|---|
| **Enable auto-generation** | Master toggle for the bundle |
| **UTM Sets** | Select one or more sets. One shortlink is created per selected UTM set on entity insert. |
| **Default UTM Set** | Pre-fills the UTM set field when creating a shortlink manually for this bundle. Also used as the fallback UTM set for bulk generation. |

### How Multiple UTM Sets Work

If a bundle has three UTM sets selected (e.g., `email`, `social`, `direct`), every new entity of that bundle receives three separate shortlinks — one per UTM set. This allows tracking traffic from each channel independently using distinct shortlink URLs.

### Reconciliation on Bundle Config Change

When the UTM set configuration for a bundle changes (sets added or removed), updating any entity of that bundle triggers:
- Creation of new shortlinks for newly added UTM sets
- Deletion of shortlinks for removed UTM sets
- No change to shortlinks for unchanged UTM sets

---

## Bulk Generation

Click **Bulk generate shortlinks** from the shortlink listing (`/admin/content/shortlink/bulk-generate`).

Select the bundles to process. The bulk generator:
- Queries all published entities for each selected bundle
- Skips entities that already have a shortlink
- Creates shortlinks using the `default_utm_set` configured for each bundle
- Processes 25 entities per batch chunk to avoid timeouts

This is useful when enabling the module on an existing site with published content, or when adding a new UTM set to existing content.

> **Note:** Bulk generation only creates one shortlink per entity (using the default UTM set). To create shortlinks for all configured UTM sets on existing content, use the `drush shortlink:add-missing-links` command instead.

---

## QR Codes

Every shortlink has a QR code available. Access it by:
- Clicking the **QR Code** link in the shortlink listing (Views field)
- Navigating directly to `/admin/content/shortlink/{id}/qr`

The QR code is a 300×300 pixel PNG file that encodes the full absolute shortlink URL. It downloads as `shortlink-{id}-qr.png`.

You must have the `view shortlink` permission to download QR codes.

---

## The Shortlink Block

The **Shortlink Block** plugin (`shortlink_manager_shortlink_block`) displays all shortlinks associated with the currently viewed page.

Place it via **Structure → Block layout**. The block:
- Finds shortlinks by matching the current URL path against `destination_override` values
- Also finds shortlinks by inspecting the current route's entity (e.g., the node being viewed)
- Renders each shortlink's URL with a copy-to-clipboard button
- Groups shortlinks by their UTM set label (or "General Link" if no UTM set)
- Requires the `view shortlink block` permission to be visible

Cache contexts: `url.path` (re-renders per page).

---

## The Shortlink Sidebar on Edit Forms

When an entity type is listed under **Available Entity Types** in settings, the shortlink edit form sidebar (via `hook_form_alter`) displays all existing shortlinks for that entity on its edit form. This gives editors a quick view of the active shortlinks without leaving the content editing interface.

---

## The Dashboard Block

The **Shortlink Dashboard** block (`shortlink_manager_dashboard`) provides a 30-day analytics summary. Place it on any page (typically an admin dashboard page).

Shows:
- Total clicks in the last 30 days
- Top 10 shortlinks by click volume
- 10 most recent click events globally (with shortlink label, timestamp, and referrer)

Cache: refreshed every 5 minutes.

Requires the `view shortlink dashboard` permission.

---

## Health Monitoring

Use the Drush commands to periodically check the health of your shortlinks:

```bash
# Check for broken destination URLs
drush shortlink:check-destinations

# Check for redirect chains (shortlinks pointing to URLs that redirect again)
drush shortlink:check-chains
```

The `check-destinations` command can optionally flag broken shortlinks in the database (`has_broken_destination = TRUE` field) after displaying the results. This flag is visible in the shortlink listing and can be used as a Views filter.

See [Drush Commands](drush-commands.md) for full details.

---

## Content Visibility and JSON:API

Because Shortlink is a Drupal content entity, the **JSON:API** module (Drupal core) automatically exposes shortlink data at `/jsonapi/shortlink/shortlink`. Access is governed by `ShortlinkAccessControlHandler`:

| Operation | Permission |
|---|---|
| View | `view shortlink` |
| Create | `create shortlink` |
| Update | `edit any shortlink` |
| Delete | `delete any shortlink` |

If JSON:API access to shortlinks is undesirable, restrict access by not granting the above permissions to API-consuming roles, or disable the JSON:API resource for the shortlink entity type.
