# Tokens, Views & Blocks

Reference for developers and site builders who want to embed shortlink URLs in content, customise shortlink Views displays, or use shortlink blocks in layouts.

---

## Token Support

Shortlink Manager registers Drupal tokens through `hook_token_info` and `hook_tokens`. The `drupal/token` module is a required dependency.

### Shortlink Tokens (direct)

These tokens require a `shortlink` context object (e.g., from token-aware fields or Feeds):

| Token | Description |
|---|---|
| `[shortlink:url]` | Full absolute URL of the shortlink (e.g., `https://example.com/go/xE4iqh`) |
| `[shortlink:path]` | The stored path string (e.g., `go/xE4iqh`) |
| `[shortlink:label]` | The shortlink's label |
| `[shortlink:click-count]` | The total click count for this shortlink |

### Chained Entity Tokens

For each entity type listed in **Available Entity Types** (general settings), the module registers chained tokens that resolve the entity's first associated shortlink:

| Token | Description |
|---|---|
| `[node:shortlink:url]` | Absolute shortlink URL for the current node |
| `[node:shortlink:path]` | Shortlink path for the current node |
| `[node:shortlink:label]` | Label of the node's first shortlink |
| `[node:shortlink:click-count]` | Click count of the node's first shortlink |

Replace `node` with any entity type you have enabled (e.g., `[commerce_product:shortlink:url]`).

The chained tokens resolve to the **first shortlink found** for the entity (no UTM set filter). If an entity has multiple shortlinks (one per UTM set), only the first one is returned.

### Using Tokens

#### In Email Templates / Messages

```
Read the full article: [node:shortlink:url]
```

#### In UTM Set Custom Parameter Values

```
author:[node:author:name]
content_id:[node:nid]
```

Token values in UTM parameters are replaced at redirect time, then sanitized (lowercased, special characters replaced with underscores).

#### In Destination Override

```
/product/[node:field_product_sku]
```

Tokens in `destination_override` are replaced at redirect time.

#### Via Token Browser

Install `drupal/token` (required dependency) to access the token browser widget on forms that support token input.

---

## Views Integration

The shortlink listing at `/admin/content/shortlink` is a Views-based display. Shortlink Manager adds two custom field plugins to enhance it.

### Copy-to-Clipboard Button (`shortlink_copy_button`)

Adds a button to each row that copies the full shortlink URL to the clipboard.

**Adding to a View:**
1. Edit the shortlink View (or create a custom view based on the `shortlink` entity)
2. Under **Fields**, click **Add**
3. Search for "Copy to clipboard" and add it
4. No additional configuration is required

**How it works:**
- Renders a `<button class="shortlink-copy-btn" data-shortlink-url="...">Copy</button>` element
- The `shortlink_manager/clipboard` JavaScript library is attached to the view
- On click, `navigator.clipboard.writeText()` copies the URL
- The button text changes to "Copied!" for 2 seconds with green styling, then resets

**Styling:** The button uses `.shortlink-copy-btn` CSS class (defined in `css/shortlink-manager.css`). Override in your theme if needed.

### QR Code Download Link (`shortlink_qr_code`)

Adds a "Download QR" link that opens the shortlink's QR code PNG in a new tab.

**Adding to a View:**
1. Edit the shortlink View
2. Under **Fields**, click **Add**
3. Search for "QR Code" and add it

**How it works:**
- Renders an `<a href="/admin/content/shortlink/{id}/qr" target="_blank" class="shortlink-qr-download">Download QR</a>` element
- Clicking downloads the QR code as a 300×300 PNG file

**Access:** Requires the `view shortlink` permission on the QR download route.

### CSV Export

The default shortlink listing includes a CSV export display powered by `views_data_export`. Access it via the "Export" link below the listing. This exports all filtered/sorted results as a downloadable CSV file.

---

## Block Plugins

### Shortlink Block

**Plugin ID:** `shortlink_manager_shortlink_block`

Displays all shortlinks for the currently viewed page with copy-to-clipboard buttons.

**Placement:** Structure → Block layout → select a region → Add block → search "Shortlink Block"

**How it finds shortlinks for the current page:**

1. Checks for shortlinks whose `destination_override` matches the current path
2. Checks the current route for any entity parameter (e.g., the node being viewed), then queries for shortlinks targeting that entity
3. If no shortlinks are found, the block does not render (access denied)

**Output template:** `shortlink-block.html.twig`

**Available template variables:**
- `shortlinks` — Array of Shortlink entity objects
- `entity` — The matched entity object (may be `NULL` for path-matched shortlinks)
- `current_path` — Current path info string

**Permissions:** `view shortlink block`

**Cache:** Per-URL path (`url.path` cache context). Automatically invalidated when the target entity's cache tags are invalidated.

**Customizing the template:** Override the template in your theme by creating `templates/shortlink-block.html.twig` in your theme folder.

### Shortlink Dashboard Block

**Plugin ID:** `shortlink_manager_dashboard`

Provides a 30-day analytics summary suitable for admin dashboards.

**Placement:** Structure → Block layout → select a region → Add block → search "Shortlink Dashboard"

**Output template:** `shortlink-dashboard.html.twig`

**Available template variables:**
- `total_clicks` — Integer total click count for the last 30 days
- `top_shortlinks` — Array of rows with `label`, `path`, `click_count` keys
- `recent_clicks` — Array of rows with `shortlink_label`, `formatted_time`, `referrer` keys (referrer truncated to 50 chars)

**Permissions:** `view shortlink dashboard`

**Cache:** 5-minute max-age. Does not vary by user.

---

## Programmatic API

### Finding Shortlinks for an Entity

```php
/** @var \Drupal\shortlink_manager\ShortlinkManager $manager */
$manager = \Drupal::service('shortlink_manager.shortlink_manager');
$shortlinks = $manager->getShortlinksForEntity('node', '42');
```

### Generating a Shortlink Path

```php
$path = $manager->generateShortlinkPath(); // Uses configured path_length
$path = $manager->generateShortlinkPath(8); // Explicit 8-character slug
// Returns: "go/xE4iqhAb" (full path including prefix)
```

### Validating a Custom Path

```php
$errors = $manager->validateCustomPath('spring-sale');
// Returns: [] if valid, or ['Error message.', ...] if invalid

// When editing an existing shortlink, pass its ID to exclude it from uniqueness check:
$errors = $manager->validateCustomPath('spring-sale', $shortlink->id());
```

### Recording a Click

```php
/** @var \Drupal\shortlink_manager\ShortlinkClickTracker $tracker */
$tracker = \Drupal::service('shortlink_manager.click_tracker');
$tracker->recordClick($shortlink_id, $request);
```

### Generating a QR Code

```php
/** @var \Drupal\shortlink_manager\ShortlinkQrGenerator $qr */
$qr = \Drupal::service('shortlink_manager.qr_generator');

// Get PNG binary data:
$png_bytes = $qr->generateQrCode($shortlink, 300);

// Get inline data URI for HTML:
$data_uri = $qr->getQrCodeDataUri($shortlink, 150);
// Result: "data:image/png;base64,..."
```

### UTM Parameter Alteration

Add dynamic UTM parameters by implementing `hook_shortlink_manager_utm_parameters_alter`:

```php
/**
 * Implements hook_shortlink_manager_utm_parameters_alter().
 */
function mymodule_shortlink_manager_utm_parameters_alter(array &$parameters, \Drupal\shortlink_manager\UtmSetInterface $utm_set): void {
  // Add or modify parameters dynamically.
  $parameters['custom_param'] = 'dynamic_value';
}
```

---

## Feeds Integration

Shortlink Manager provides a Feeds target plugin (`@FeedsTarget`) for importing shortlink data from feeds. The target maps these properties:

| Property | Type | Description |
|---|---|---|
| `id` | string | Shortlink entity ID |
| `label` | string | Shortlink label |
| `path` | string | Full path (e.g., `go/abc123`) |
| `status` | boolean | Active/inactive |
| `target_entity_id` | string | ID of the target entity |
| `target_entity_type` | string | Entity type of the target |
| `utm_set` | string | UTM Set machine name |
| `destination_override` | string | Destination URL override |
| `description` | string | Administrative description |

Configure the Feeds importer to use the "Shortlink" target when setting up field mappings.
