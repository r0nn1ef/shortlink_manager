# Shortlink Manager Documentation

Shortlink Manager is a Drupal 10/11 module that provides a full-featured short URL system with automatic UTM tracking, click analytics, QR code generation, link expiration, and health monitoring.

## Documentation Index

| Document | Audience | Description |
|---|---|---|
| [Installation & Setup](installation-and-setup.md) | Administrators | Requirements, installation steps, and first-run configuration |
| [Administrator Guide](administrator-guide.md) | Administrators | Managing shortlinks, UTM sets, bulk operations, settings overview |
| [Configuration Reference](configuration-reference.md) | Administrators | Complete reference for every setting, permission, and option |
| [Expiration & Analytics](expiration-and-analytics.md) | Administrators | Click tracking, expiration rules, the dashboard block, and data retention |
| [Drush Commands](drush-commands.md) | Administrators / Developers | CLI commands for health checks and bulk operations |
| [Tokens, Views & Blocks](tokens-views-blocks.md) | Developers / Site Builders | Token usage, Views field plugins, and block configuration |
| [End-User Guide](end-user-guide.md) | End Users | How to access and use shortlinks on the site |

## How Shortlinks Work

When a visitor navigates to a shortlink URL (e.g., `https://example.com/go/xE4iqh`), the module:

1. Looks up the shortlink record in the database
2. Checks whether it has expired
3. Logs the click (timestamp, referrer, hashed IP)
4. Appends configured UTM parameters to the destination URL
5. Issues an HTTP redirect to the final destination

The path prefix (`go` by default) and the redirect HTTP status code (301, 307, or 308) are configurable.

## Key Features at a Glance

- **Automatic shortlink generation** when new content is published
- **UTM parameter sets** that attach tracking parameters to destination URLs
- **QR code generation** for every shortlink (PNG download)
- **Click analytics** with per-shortlink and global dashboard reporting
- **Link expiration** by date, maximum click count, or inactivity period
- **Bulk generation** for existing content that pre-dates the module
- **Drush commands** for health checks, chain detection, and missing link generation
- **Drupal Tokens** for embedding shortlink URLs in content and emails
- **Views integration** with copy-to-clipboard and QR download field plugins
