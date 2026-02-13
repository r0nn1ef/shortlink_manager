# Shortlink Manager

## Introduction

The Shortlink Manager module provides a powerful and automated solution for creating, managing, and redirecting short URLs within Drupal. It simplifies the process of creating trackable shortlinks for content on your site, allowing for automated UTM tagging, click analytics, expiration logic, QR code generation, and custom redirects.

This module is designed for developers and site builders who need a robust, flexible, and integrated way to handle shortlinks without relying on external services.

## Features

### Core Shortlink Management

* **Content Entity-based Shortlinks:** Uses a custom content entity to store shortlink data, providing full integration with Drupal's content management ecosystem.
* **Automated Generation:** Automatically creates shortlinks for new content based on configurable rules for content types and bundles.
* **Custom Path / Vanity URLs:** Optionally specify a custom slug for shortlinks (e.g., `go/summer-sale`) instead of using auto-generated paths. Validates against character rules, uniqueness, path alias collisions, and Drupal route collisions.
* **Configurable Path Length:** Control the length of auto-generated shortlink paths (4-12 characters) from the settings form.
* **Customizable Redirects:** Configurable redirect statuses (301, 307, 308) and the ability to set custom destination URLs.
* **Unique Path Generation:** Guarantees unique, conflict-free shortlink paths using a base62 alphabet (excluding visually confusing characters).

### UTM Tracking

* **UTM Sets:** Create reusable UTM parameter sets (source, medium, campaign, term, content) plus custom key-value parameters.
* **Per-Bundle UTM Defaults:** Assign a default UTM Set per content type bundle so manually-created shortlinks auto-fill the UTM set.
* **UTM Parameter Passthrough:** Configurable list of UTM parameters that pass through from incoming requests to the destination URL.
* **Extensible UTM Parameters:** UTM parameters can be altered via `hook_shortlink_manager_utm_parameters_alter`.

### Click Tracking & Analytics

* **Click Count Tracking:** Every shortlink tracks total click count and last accessed timestamp.
* **Granular Click Log:** Individual click events are recorded in a dedicated `shortlink_clicks` table, capturing timestamp, referrer, user agent, and a SHA-256 hashed IP address (for privacy).
* **Analytics Dashboard Block:** A placeable block showing total clicks (last 30 days), top 10 shortlinks by clicks, and 10 most recent click events.
* **Click Log Retention:** Configurable retention period (default: 90 days) with automatic purging of old click data via cron.

### Expiration Logic

Each shortlink can be configured with a single expiration method, selected from a dropdown:

* **Expire at a specific date/time:** Set an absolute expiration timestamp.
* **Expire after maximum clicks reached:** Set a click limit (useful for controlling limited offers).
* **Expire after days of inactivity:** Expire shortlinks that have not been clicked within a configurable number of days.

Only the configuration field for the selected method is displayed, keeping the form clean and unambiguous. A site-wide default expiration method can be configured at `/admin/config/system/shortlink/expiration`. Expired shortlinks are automatically disabled via a daily cron task using Drupal's Queue API.

### QR Code Generation

* **QR Code Downloads:** Generate and download QR code images (PNG) for any shortlink.
* **Views Integration:** QR code download links appear in the shortlink listing view.
* **Requires:** The `endroid/qr-code` Composer package (installed automatically as a dependency).

### Bulk Operations

* **Bulk Actions:** Enable, disable, or delete multiple shortlinks at once from the shortlink listing using Views bulk operations.
* **Bulk Generate:** Generate shortlinks for all entities of a selected type/bundle that are missing one. Available at `/admin/content/shortlink/bulk-generate`. Uses the Batch API for large datasets.

### Copy to Clipboard

* **One-click Copying:** Copy shortlink URLs to the clipboard directly from the shortlink listing view or the shortlink block.
* **Visual Feedback:** A "Copied!" confirmation appears after copying.

### Entity Form Integration

* **Shortlink Preview:** When editing content that has associated shortlinks, a sidebar panel displays each shortlink's full URL, UTM set label, and click count. If no shortlink exists and auto-generation is enabled for that bundle, an informational message is shown.

### Broken Destination Detection

* **Health Checks:** Detect shortlinks pointing to deleted entities, unpublished content, invalid paths, or broken external URLs.
* **Redirect Chain Detection:** Identify shortlinks whose destinations return 3xx redirects, creating undesirable redirect chains.
* **Drush Commands:** Run health checks from the command line (see Drush Commands section below).
* **Broken Destination Flag:** Shortlinks with broken destinations are flagged in the database for filtering and reporting.

### Token Integration

Provides tokens for use in other modules and content:

* `[shortlink:url]` - The full shortlink URL
* `[shortlink:path]` - The shortlink path
* `[shortlink:click-count]` - The click count
* `[shortlink:label]` - The shortlink label
* `[node:shortlink:url]` - Chained token to get a node's shortlink URL (works with other entity types as well)

### JSON:API / REST Exposure

Shortlink entities are exposed via Drupal's JSON:API module (included in core) with a custom access control handler. Access is controlled by the module's permissions.

## Requirements

* **Drupal core** ^10.5 || ^11.2
* **PHP** 8.3+
* **endroid/qr-code** ^5.0 || ^6.0 (installed via Composer)
* **drupal/views_data_export** ^1.6
* **drupal/token**

## Installation

1.  **Add the repository to Composer.**

    Since this module is not hosted on Drupal.org, you will need to add the repository to your project's `composer.json` file. Add the following to the `repositories` section:

    ```json
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/r0nn1ef/shortlink_manager.git"
        }
    ]
    ```

2.  **Require the module.**

    ```bash
    composer require r0nn1ef/shortlink_manager:^1.0
    ```

3.  **Enable the module.**

    Enable the module via the Drupal UI (`Extend` > `Shortlink Manager`) or using Drush:

    ```bash
    drush en shortlink_manager
    ```

4.  **Run database updates** (if updating from an earlier version):

    ```bash
    drush updb
    drush cr
    ```

## Configuration

### Main Settings

Navigate to `/admin/config/system/shortlink` (tabs: **Settings**, **Auto Generate**, **Expiration**).

* **Path Prefix:** The URL prefix for all shortlinks (e.g., `go` produces URLs like `yoursite.com/go/abc123`).
* **Redirect Status:** The default HTTP redirect status code (301, 307, or 308).
* **Path Length:** The number of characters for auto-generated shortlink paths (4-12, default: 6).
* **Passthrough Parameters:** UTM parameters that pass through from incoming requests to the destination URL.

### Auto-Generation

Navigate to `/admin/config/system/shortlink/auto-generate`.

* Enable automatic shortlink generation per content type and bundle.
* Select which UTM Sets to apply when auto-generating shortlinks.
* Set a default UTM Set per bundle for manually-created shortlinks.

### Expiration Settings

Navigate to `/admin/config/system/shortlink/expiration`.

* **Enable Expiration Cron:** Toggle automatic expiration processing during cron runs.
* **Default Expiration Method:** Select a single expiration method for new shortlinks. Only the configuration field for the selected method is displayed:
  * **None** - No expiration (default).
  * **Expire after a number of days** - Shows a field to set the number of days.
  * **Expire after maximum clicks reached** - Shows a field to set the click limit.
  * **Expire after days of inactivity** - Shows a field to set the inactivity threshold.
* **Click Log Retention:** Number of days to retain granular click log data (default: 90).

### UTM Sets

Navigate to `/admin/structure/utm-set` to create and manage UTM parameter sets. Each set can include:

* UTM Source, Medium, Campaign, Term, and Content
* Custom key-value parameters

## Usage

### Managing Shortlinks

* **Listing:** Navigate to `/admin/content/shortlinks` to view, filter, and manage all shortlinks.
* **Creating:** Add shortlinks manually at `/admin/content/shortlink/add`. Optionally enter a custom vanity slug or let the module auto-generate one.
* **Bulk Generate:** Navigate to `/admin/content/shortlink/bulk-generate` to create shortlinks for all entities of a given type that are missing one.
* **QR Codes:** Download a QR code PNG for any shortlink from the listing view or via `/admin/content/shortlink/{id}/qr`.
* **Copy to Clipboard:** Click the copy button next to any shortlink URL in the listing or block to copy it.

### Shortlink Block

Place the "Shortlink" block on any page to display shortlinks associated with the current content. The block includes copy-to-clipboard functionality.

### Analytics Dashboard

Place the "Shortlink Dashboard" block to display click analytics including total clicks, top shortlinks, and recent click activity. Requires the `view shortlink dashboard` permission.

## Drush Commands

| Command | Alias | Description |
|---|---|---|
| `shortlink:add-missing-links` | `sl:add-missing` | Creates shortlinks for all content that should have one but doesn't. |
| `shortlink:check-destinations` | `sl:check` | Checks all active shortlinks for broken or invalid destinations. Displays a table of issues and optionally flags them. |
| `shortlink:check-chains` | `sl:chains` | Checks all active shortlinks for redirect chain issues. |

## Permissions

| Permission | Description |
|---|---|
| Administer shortlinks | Full access to create, edit, and delete any shortlink. |
| Administer UTM set | Full access to create, edit, and delete any UTM set. |
| Create new shortlinks | Create new shortlink entities. |
| View any shortlink | View individual shortlink entities. |
| Edit any shortlink | Edit any existing shortlink. |
| Delete any shortlink | Delete any existing shortlink. |
| Generate bulk shortlinks | Access the bulk generate form. |
| View shortlink dashboard | View the analytics dashboard block. |
| View shortlink block | See the shortlink block on pages where it is placed. |
| Use shortlinks | Access shortlinks for redirection (enabled by default). |

## Database Considerations

The `shortlink` content entity stores a record for every shortlink created. The `shortlink_clicks` table stores individual click events with referrer, user agent, and hashed IP data. For high-traffic sites:

* Configure click log retention (default: 90 days) to automatically purge old data via cron.
* Monitor the `shortlink_clicks` table size if your shortlinks receive heavy traffic.

## Maintainers

* **Ron Ferguson** (r0nn1ef@drupalodyssey.com) - Initial creator and maintainer.

Feel free to open an issue or pull request if you find a bug or have a suggestion for a new feature.
