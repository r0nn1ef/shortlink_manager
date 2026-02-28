# Drush Commands

Shortlink Manager provides three Drush commands for administrative and maintenance tasks. All commands are registered under the `shortlink` namespace.

---

## Prerequisites

The commands require a working Drush installation within the Drupal site:

```bash
# Verify Drush can access the commands
drush list --filter=shortlink
```

---

## `shortlink:add-missing-links`

**Alias:** `sl:add-missing`

Creates shortlinks for published entities that are missing them. This command is essential when:
- The module was installed on a site with existing published content
- New UTM sets were added to a bundle's auto-generate configuration after content was already published
- Shortlinks were accidentally deleted and need to be recreated

```bash
drush shortlink:add-missing-links
# or
drush sl:add-missing
```

### What It Does

1. Iterates all bundles where `auto_generate_settings.{type}.{bundle}.enabled = TRUE`
2. For each bundle, queries all published entities
3. For each entity, loads its existing shortlinks and checks which UTM sets are already covered
4. Creates new shortlinks for any UTM set not yet assigned to that entity
5. Reports a summary of created and skipped counts

### Output Example

```
Processing node / article...
[notice] Created shortlink go/xE4iqh for node:42 (email campaign)
[notice] Skipped node:42 — shortlink for 'social' already exists
[success] Completed: 47 created, 128 skipped.
```

### Important Notes

- Only processes bundles with `enabled = TRUE` in auto-generate settings
- Uses the `default_utm_set` for bulk creation when no specific UTM sets are configured
- Will not create duplicate shortlinks for UTM sets already covered
- Does not modify existing shortlinks

---

## `shortlink:check-destinations`

**Alias:** `sl:check`

Checks all active shortlinks for broken or invalid destinations. Run this command periodically to identify shortlinks that point to deleted or unpublished content.

```bash
drush shortlink:check-destinations
# or
drush sl:check
```

### What It Checks

**Entity-based shortlinks** (those with a `target_entity_type` and `target_entity_id`):
- `deleted` — The target entity no longer exists in the database
- `unpublished` — The target entity exists but is not published (status = 0)

**Destination override shortlinks** (those with a `destination_override` starting with `/`):
- The internal path is validated using Drupal's path validator
- Paths that do not resolve to a valid route or alias are flagged as broken

External URLs in `destination_override` (starting with `http://` or `https://`) are **not checked** by this command. Use `shortlink:check-chains` to detect external redirect issues.

### Output

The command displays a table of issues:

```
+----+---------------------------+-------------+-------------+---------------------------+
| ID | Label                     | Path        | Issue       | Details                   |
+----+---------------------------+-------------+-------------+---------------------------+
| 12 | Spring Sale - Email       | go/xE4iqh   | deleted     | node:42 no longer exists  |
| 15 | Product Page - Social     | go/kPqmR7   | unpublished | node:55 is not published  |
+----+---------------------------+-------------+-------------+---------------------------+
```

After displaying results, the command prompts:

```
Flag broken shortlinks in the database? (yes/no) [no]:
```

If you answer `yes`:
- The `has_broken_destination` field is set to `TRUE` on all shortlinks in the issues list
- All previously flagged shortlinks not in the current issues list are cleared
- This flag can be used as a Views filter to build a "broken shortlinks" report

### Recommended Usage

Run this as part of a regular maintenance cycle or via scheduled cron outside Drupal:

```bash
# Run non-interactively and auto-flag broken links
drush shortlink:check-destinations --no-interaction
```

---

## `shortlink:check-chains`

**Alias:** `sl:chains`

Detects redirect chains — shortlinks whose destination URL itself redirects to another URL. Redirect chains add unnecessary latency and reduce the effectiveness of UTM tracking.

```bash
drush shortlink:check-chains
# or
drush sl:chains
```

### What It Does

For each active shortlink:
1. Resolves the destination to an absolute URL
2. Makes an HTTP HEAD request to that URL with `allow_redirects = FALSE` and a 5-second timeout
3. Records any 3xx response as a redirect chain

### Output

The command displays a table of detected chains:

```
+----+---------------------------+-------------+--------+------------------------------------------+
| ID | Label                     | Path        | Status | Redirects to                             |
+----+---------------------------+-------------+--------+------------------------------------------+
| 7  | Homepage - Email          | go/aB3cD4   | 301    | https://example.com/new-homepage         |
| 18 | Old Product - Social      | go/eF5gH6   | 302    | https://example.com/products/new-slug    |
+----+---------------------------+-------------+--------+------------------------------------------+
```

### Remediation

This command is **informational only** — it does not automatically fix chains. To resolve a redirect chain:
1. Update the shortlink's `destination_override` to point directly to the final destination URL
2. Or update the target entity's canonical URL if it was changed via a redirect

### Notes

- Times out per-URL after 5 seconds; slow external servers may cause false negatives
- Network errors are silently skipped; the command only reports confirmed 3xx responses
- Internal path overrides are resolved to absolute URLs before checking

---

## General Drush Maintenance Commands

These standard Drush commands are also used regularly with this module:

```bash
# Apply database updates after upgrading the module
drush updb

# Clear all caches (required after changing path prefix or routing config)
drush cr

# Export configuration after changing settings
drush cex

# Import configuration on deployment
drush cim
```
