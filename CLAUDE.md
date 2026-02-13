# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Drupal 10/11 custom module** called **Shortlink Manager** that provides shortlink creation, management, and redirect functionality with UTM tracking, click analytics, QR code generation, expiration logic, and health monitoring. It is not hosted on drupal.org and is installed via a custom Composer repository.

- **Requirements:** PHP 8.3+, Drupal core ^10.5 || ^11.2
- **Primary Namespace:** `Drupal\shortlink_manager`

## Development Standards

- **Coding Standard:** Follow Drupal Coding Standards (`Drupal` and `DrupalPractice` sniffs via `drupal/coder` and `phpcs`).
- **File Naming:** PSR-4 for classes in `src/`. Hooks in `.module` file.
- **Dependency Injection:** Always prefer DI in Controllers, Forms, and Services over `\Drupal::service()`.
- **Type Hinting:** Use strict typing and return type hints for all new methods.

## Commands

```bash
# Install the module (within a Drupal site)
composer require r0nn1ef/shortlink_manager:^1.0
drush en shortlink_manager

# Run database updates after schema changes
drush updb

# Clear caches (needed after routing, service, or hook changes)
drush cr

# Export configuration after settings changes
drush cex

# Check Drupal coding standards
phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,theme src/ shortlink_manager.module shortlink_manager.install

# Auto-fix coding standard violations
vendor/bin/phpcbf --standard=Drupal --extensions=php,module,install,theme src/ shortlink_manager.module shortlink_manager.install

# Run tests (none currently, but this is the command when added)
vendor/bin/phpunit -c core modules/custom/shortlink_manager

# Drush: check for broken shortlink destinations
drush shortlink:check-destinations

# Drush: check for redirect chains
drush shortlink:check-chains

# Drush: generate missing shortlinks for configured bundles
drush shortlink:add-missing-links
```

## Project Structure & Key Files

- `shortlink_manager.info.yml` — Module metadata and dependencies
- `shortlink_manager.routing.yml` — Route definitions for the manager UI, QR downloads, bulk generate, expiration settings
- `shortlink_manager.links.menu.yml` — Administrative menu links
- `shortlink_manager.links.task.yml` — Admin tabs (Settings, Auto Generate, Expiration)
- `shortlink_manager.links.action.yml` — Action links (Add shortlink, Bulk generate)
- `shortlink_manager.module` — Hook implementations (cron, entity lifecycle, form_alter, tokens, views_data_alter, theme)
- `shortlink_manager.install` — Install/update hooks (10501–10511)
- `shortlink_manager.services.yml` — Service definitions (5 services)
- `shortlink_manager.libraries.yml` — JS/CSS library for clipboard functionality
- `shortlink_manager.permissions.yml` — 11 granular permissions
- `drush.services.yml` — Drush command registration
- `src/` — Controllers, Entities, Forms, Services, Plugins
- `config/install/` — Default configuration for shortlink entities/settings
- `config/schema/` — Configuration schema definitions
- `js/shortlink-clipboard.js` — Copy-to-clipboard JavaScript behavior
- `css/shortlink-manager.css` — Shortlink UI styles
- `templates/` — Twig templates (shortlink-block, shortlink-dashboard)

## Architecture

### Entities

- **Shortlink** (`src/Entity/Shortlink.php`) — Content entity stored in `shortlink` table. Fields: path (unique), label, description, destination_override, target_entity_type, target_entity_id, utm_set (entity_reference), status, click_count, last_accessed, expires_at, max_clicks, expire_if_inactive_days, has_broken_destination. Uses `ShortlinkAccessControlHandler` for granular permission mapping. Includes `isExpired()` method checking time-based, max-click, and inactivity expiration rules.
- **UtmSet** (`src/Entity/UtmSet.php`) — Config entity storing predefined UTM parameters (source, medium, campaign, term, content) plus custom parameters. Supports alteration via `hook_shortlink_manager_utm_parameters_alter`.

### Services

| Service ID | Class | Purpose |
|---|---|---|
| `shortlink_manager.shortlink_manager` | `ShortlinkManager` | Path generation (configurable length, base62 alphabet), vanity URL validation, entity shortlink lookup, shortlink deletion |
| `shortlink_manager.click_tracker` | `ShortlinkClickTracker` | Granular click logging to `shortlink_clicks` table, analytics queries, log purging |
| `shortlink_manager.qr_generator` | `ShortlinkQrGenerator` | QR code generation (PNG) via `endroid/qr-code` library |
| `shortlink_manager.health_checker` | `ShortlinkHealthChecker` | Broken destination detection, redirect chain detection, flag management |
| `shortlink_manager.route_subscriber` | `ShortlinkRouteSubscriber` | Dynamic route registration based on configured path prefix |

### Request Flow

1. User hits `/{path_prefix}/{shortlink_path}` (default prefix: `go`)
2. `ShortlinkRouteSubscriber` dynamically registers the route based on configured prefix
3. `ShortlinkRedirectController` checks expiration, resolves the destination, logs click to tracking table, increments click count, merges UTM parameters, and issues an HTTP redirect (configurable: 301, 307, or 308)

### Hook Implementations (shortlink_manager.module)

- `hook_cron` — Queues expired shortlinks for processing, purges old click log data based on retention settings
- `hook_entity_insert` — Auto-generates shortlinks for configured entity types/bundles (with default UTM set fallback)
- `hook_entity_delete` — Cascading delete of shortlinks when target entities are removed
- `hook_entity_update` — Syncs UTM set changes to associated shortlinks
- `hook_form_alter` — Adds shortlink preview sidebar to entity edit forms for configured types
- `hook_token_info` / `hook_tokens` — Exposes `[shortlink:url]`, `[shortlink:path]`, `[shortlink:label]`, `[shortlink:click-count]` tokens and chained entity tokens (e.g., `[node:shortlink:url]`)
- `hook_views_data_alter` — Registers custom Views field plugins (copy button, QR code)
- `hook_theme` — Registers shortlink block and dashboard templates

### Plugins

- **Block:** `ShortlinkBlock` (displays shortlinks for current page with copy buttons), `ShortlinkDashboardBlock` (analytics dashboard: total clicks, top shortlinks, recent clicks)
- **Action:** `DeleteShortlinkAction`, `EnableShortlinkAction`, `DisableShortlinkAction` (bulk operations)
- **QueueWorker:** `ShortlinkExpirationWorker` (processes shortlink expiration during cron)
- **Views Fields:** `ShortlinkCopyButton` (copy-to-clipboard), `ShortlinkQrCode` (QR code download link)

### Key Admin Routes

- `/admin/config/system/shortlink` — Main settings (path prefix, path length, redirect status, entity types)
- `/admin/config/system/shortlink/auto-generate` — Per-bundle auto-generation + default UTM set
- `/admin/config/system/shortlink/expiration` — Expiration rules and click log retention
- `/admin/content/shortlink` — Shortlink listing (Views-based with bulk operations)
- `/admin/content/shortlink/bulk-generate` — Bulk shortlink generation form
- `/admin/content/shortlink/{shortlink}/qr` — QR code PNG download
- `/admin/structure/utm-set` — UTM Set management

### Drush Commands

Registered in `drush.services.yml`, implemented in `src/Commands/ShortlinkCommands.php`:
- `shortlink:add-missing-links` (alias `sl:add-missing`) — Creates shortlinks for entities missing them
- `shortlink:check-destinations` (alias `sl:check`) — Checks for broken/invalid destinations
- `shortlink:check-chains` (alias `sl:chains`) — Detects redirect chains

### Database Tables

- `shortlink` — Content entity base table (auto-generated from entity definition)
- `shortlink_revision` — Revision table (defined in `hook_schema()`)
- `shortlink_clicks` — Click event log (click_id, shortlink_id, timestamp, referrer, user_agent, ip_hash)

### Update Hooks

Update hooks in `shortlink_manager.install` follow the `shortlink_manager_update_1050X` naming convention (currently 10501–10511). New updates should increment from the last number.

| Hook | Description |
|---|---|
| 10501 | Add core entity fields (uuid, langcode, created, changed) |
| 10502 | Add custom_parameters to utm_set config entity |
| 10503 | Add default passthrough_parameters config |
| 10504 | Add click_count and last_accessed entity fields |
| 10505 | Set click_count = 0 for existing shortlinks |
| 10506 | Add path_length config setting |
| 10507 | Add default_utm_set to auto_generate_settings |
| 10508 | Create shortlink_clicks table |
| 10509 | Add expires_at, max_clicks, expire_if_inactive_days entity fields |
| 10510 | Add expiration config defaults |
| 10511 | Add has_broken_destination entity field |

## Module Dependencies

- `drupal/views_data_export` (^1.6) — Required for CSV export
- `drupal/token` — Token replacement in UTM parameters
- `csv_serialization` — CSV serialization support
- `endroid/qr-code` (^5.0 || ^6.0) — QR code generation

## Critical Context & Gotchas

- When modifying the `.install` file, remember to provide update hooks (`hook_update_N`).
- Always verify path aliases before creating a shortlink to avoid collisions with existing Drupal routes (handled by `ShortlinkManager::validateCustomPath()`).
- The `ShortlinkRedirectController` checks expiration before redirecting and disables expired shortlinks on access.
- Click tracking stores hashed IPs (SHA-256) for privacy. Old click data is purged by cron based on `expiration.click_log_retention_days` config (default: 90 days).
- The JSON:API module (Drupal core) auto-exposes the Shortlink content entity; granular access is handled by `ShortlinkAccessControlHandler`.
