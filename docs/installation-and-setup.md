# Installation & Setup

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 or higher |
| Drupal core | ^10.5 or ^11.2 |
| `drupal/views_data_export` | ^1.6 |
| `drupal/token` | any compatible version |
| `csv_serialization` | any compatible version |
| `endroid/qr-code` | ^5.0 or ^6.0 |

The `drupal/views`, `drupal/rest`, and `drupal/views_data_export` modules must be enabled.

---

## Installation

### Via Composer (recommended)

```bash
composer require r0nn1ef/shortlink_manager:^1.0
drush en shortlink_manager
drush cr
```

### Manual

1. Place the module folder in `modules/custom/shortlink_manager/`
2. Run `composer install` in the Drupal root to install `endroid/qr-code`
3. Enable via the Drupal admin UI at **Extend** → search for "Shortlink Manager" → check → Install

---

## First-Run Configuration

After enabling the module, complete these steps in order before creating any shortlinks. Skipping or changing these settings later can break existing shortlink URLs.

### Step 1 — Set the Path Prefix

Navigate to **Administration → Configuration → System → Shortlink Settings** (`/admin/config/system/shortlink`).

Set the **Path Prefix**. This is the URL segment that appears before every shortlink slug.

> **Critical:** The path prefix is baked into every shortlink URL stored in the database. Changing this value after shortlinks have been created will invalidate all existing shortlink URLs. Choose your prefix before generating any shortlinks and treat it as permanent.

- Default value: `go` (shortlinks appear as `/go/xE4iqh`)
- Maximum length: 8 characters
- Do not include a leading or trailing slash
- Avoid using a prefix that conflicts with any existing Drupal path, menu item, or path alias

After saving a new prefix, you **must** run `drush cr` (or clear caches via the UI) for the new redirect route to activate.

### Step 2 — Set the Path Length

On the same settings page, configure the **Path Length**.

- Default: `6` characters
- Range: 4–12 characters
- This controls how many random characters are generated for automatically created shortlink slugs (e.g., `xE4iqh` is 6 characters)
- Shorter paths are easier to type; longer paths reduce the chance of collisions on large sites

### Step 3 — Choose a Redirect Status Code

Select the **Redirect Status** code. Options:

| Code | Name | When to use |
|---|---|---|
| `301` | Moved Permanently | Default. Browsers and search engines cache this redirect. Best for permanent links. |
| `307` | Temporary Redirect | Not cached by browsers. Use when the destination may change. |
| `308` | Permanent Redirect | Like 301, but preserves the HTTP method (relevant for POST requests). |

> For most marketing and tracking use cases, `301` is the correct choice.

### Step 4 — Configure Available Entity Types

Under **Available Entity Types**, select the Drupal content entity types that should have shortlink support. This enables:
- The auto-generation feature for those types
- The shortlink sidebar preview on entity edit forms
- Token support for those entity types (e.g., `[node:shortlink:url]`)

At minimum, enable **Content** (`node`) if your site's main content is nodes.

### Step 5 — Configure Auto-Generation (optional but recommended)

Navigate to **Administration → Configuration → System → Shortlink Auto Generate** (`/admin/config/system/shortlink/auto-generate`).

For each bundle you want shortlinks generated automatically:
1. Expand the bundle's section
2. Check **Enable auto-generation**
3. Select one or more **UTM Sets** to assign — one shortlink is created per UTM set selected
4. Optionally select a **Default UTM Set** that pre-fills when manually creating a shortlink for this bundle

> If no UTM Sets have been created yet, create at least one at **Administration → Structure → UTM Sets** first.

### Step 6 — Configure Expiration (optional)

Navigate to **Administration → Configuration → System → Shortlink Expiration** (`/admin/config/system/shortlink/expiration`).

See [Expiration & Analytics](expiration-and-analytics.md) for full details. At minimum, review the **Click log retention** setting (default: 90 days).

---

## Verifying the Installation

1. Navigate to **Administration → Content → Shortlinks** (`/admin/content/shortlink`)
2. Click **Add shortlink**
3. Fill in a label, set a destination (either a target entity or a URL override), and save
4. Click the shortlink path link shown in the listing to verify it redirects correctly
5. Return to the listing and confirm the click count incremented to 1

---

## Granting Permissions

By default only the `use shortlinks` permission (following shortlink redirects) is granted to all roles including anonymous users. All management permissions must be granted explicitly.

Review the [Configuration Reference — Permissions](configuration-reference.md#permissions) section and assign roles appropriately before going live.

---

## Post-Upgrade Steps

After updating the module to a new version, always run:

```bash
drush updb
drush cr
```

The `updb` command applies any database schema updates added in the new version. Skipping this step can cause errors.
