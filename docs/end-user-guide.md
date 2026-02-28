# End-User Guide

This guide is for editors, marketing staff, and other non-technical users who create and manage shortlinks on a day-to-day basis.

---

## What Is a Shortlink?

A shortlink is a short, easy-to-share URL that redirects visitors to a longer destination page. For example:

- **Shortlink:** `https://example.com/go/spring26`
- **Destination:** `https://example.com/products/spring-collection-2026?utm_source=email&utm_medium=newsletter&utm_campaign=spring-sale-2026`

Shortlinks are used to:
- Make URLs shareable in print, email, and social media
- Track where traffic comes from (via UTM parameters)
- Provide a QR code that can be printed and scanned
- Manage and update destination URLs from one place

---

## Accessing the Shortlink Listing

Go to **Content → Shortlinks** in the administration menu, or navigate directly to `/admin/content/shortlink`.

Here you can see all shortlinks on the site with:
- **Label** — The human-readable name you gave the shortlink
- **Path** — The short URL path (e.g., `go/xE4iqh`)
- **Status** — Whether the shortlink is active (enabled) or inactive (disabled)
- **Clicks** — How many times the shortlink has been used
- **Last Accessed** — The date and time of the most recent click

---

## Creating a Shortlink

Click **Add shortlink** from the shortlink listing page.

### Step 1 — Give It a Label

The label is the human-readable name shown in listings and analytics. Use a descriptive name so you can identify it later:

- Good: `Spring Sale 2026 - Email Newsletter`
- Good: `Homepage - Social Media Bio Link`
- Avoid: `Link 1` or `Test`

### Step 2 — Choose a Path (optional)

**Leave this field empty** to have a random path generated automatically (e.g., `go/xE4iqh`).

**Or enter a custom slug** to create a memorable URL (e.g., enter `spring-sale` to create `go/spring-sale`).

Custom slugs:
- Can contain letters, numbers, hyphens (`-`), and underscores (`_`)
- Are case-sensitive
- Must be unique — the system will tell you if the slug is already taken
- Cannot be changed after the shortlink is saved

### Step 3 — Set the Destination

Choose one of two destination methods:

**Option A — Target a piece of content:**
Enter the entity type (e.g., `node`) and the content ID (e.g., `42`). The shortlink automatically points to that content's URL. If the content's URL changes later, the shortlink still works.

*Ask your site administrator for the entity type and ID if you're not sure.*

**Option B — Enter a destination URL directly:**
Type any URL (external or internal) in the **Destination Override** field. Examples:
- `/products/spring-collection` — an internal page
- `https://example.com/external-page` — an external URL

> You can only use one option at a time. The form automatically disables the other option when you start filling one in.

### Step 4 — Choose a UTM Set (optional but recommended)

Select a **UTM Set** to automatically append tracking parameters to the destination URL when visitors click the shortlink. This allows you to see in your analytics tool (e.g., Google Analytics) exactly where the traffic came from.

If you don't select a UTM set, visitors are redirected to the destination without any tracking parameters.

*UTM sets are created and managed by site administrators. Contact your administrator if the UTM set you need is not available.*

### Step 5 — Set Expiration (optional)

Expand the **Expiration** section if this shortlink should automatically deactivate at some point.

| Option | When to use |
|---|---|
| **None** | The shortlink stays active indefinitely (default) |
| **Specific date/time** | Time-limited promotions (e.g., a sale ending Sunday night) |
| **Maximum clicks** | Limited-availability offers (e.g., first 100 users get a discount) |
| **After inactive days** | Automatically retire shortlinks no one is using |

### Step 6 — Save

Click **Save**. The shortlink is created and you are returned to the listing. The new shortlink URL is ready to use immediately.

---

## Copying a Shortlink URL

From the shortlink listing, click the **Copy** button in the row for the shortlink you want. The full shortlink URL is copied to your clipboard.

Alternatively, click the path link (e.g., `go/xE4iqh`) in the listing — this takes you directly to the shortlink URL which you can then copy from your browser's address bar.

---

## Getting a QR Code

From the shortlink listing, click the **QR Code** link in the row for the shortlink. A 300×300 pixel PNG image downloads to your computer. This QR code encodes the full shortlink URL and can be used in printed materials, posters, and presentations.

---

## Editing a Shortlink

Click the shortlink's label to open the edit form. You can change:
- The label
- The UTM set
- The destination (target entity or override URL)
- The status (enabled/disabled)
- The expiration settings

> You cannot change the shortlink's path after it has been created. If you need a different path, create a new shortlink.

---

## Disabling and Re-enabling a Shortlink

To temporarily stop a shortlink from working without deleting it:

1. Open the shortlink's edit form
2. Uncheck the **Status** checkbox
3. Save

To re-enable it, check the Status checkbox and save again.

You can also use the **Disable** bulk action from the listing — check the boxes for the shortlinks you want to disable, select "Disable shortlink" from the **Action** dropdown, and click **Apply to selected items**.

---

## Deleting a Shortlink

> **Warning:** Deleting a shortlink is permanent. Anyone who visits the old URL will get a "Page not found" error. If you might want to use the shortlink again, disable it instead.

To delete a shortlink:
1. From the listing, find the shortlink and click its label
2. Click the **Delete** button (or navigate to the delete form)
3. Confirm the deletion

For bulk deletion:
1. Check the boxes for shortlinks to delete
2. Select "Delete shortlink" from the **Action** dropdown
3. Click **Apply to selected items** and confirm

---

## Viewing Click Statistics

**From the listing:** The **Clicks** column shows the total all-time click count for each shortlink.

**From the edit form:** The click count and last accessed date are shown in the form.

**From the dashboard block:** If your administrator has placed the Shortlink Dashboard block on a page, it shows:
- Total clicks in the last 30 days
- Top 10 shortlinks by click volume
- 10 most recent click events

---

## The Shortlink Block (for editors)

If your site has the **Shortlink Block** placed in a region, you will see a list of shortlinks for the page you are currently viewing when you are logged in with the appropriate permission.

This block shows the shortlinks associated with the current page and includes a **Copy** button for each. It is useful for quickly grabbing the shortlink URL while editing or viewing a piece of content.

---

## Automatic Shortlinks

If your site has auto-generation configured for a content type (e.g., Blog Posts or Products), shortlinks are created automatically when you publish new content. You don't need to do anything — the shortlinks appear in the listing under the label "Auto-generated for [your content title]".

If you need a shortlink for older content that was published before auto-generation was configured, ask your site administrator to run the bulk generation tool.

---

## Frequently Asked Questions

**Q: Can I reuse a path that was previously used by a deleted shortlink?**
A: Yes. Once a shortlink is deleted, its path is freed up and can be used for a new shortlink.

**Q: Will changing the destination URL break the shortlink?**
A: No. The shortlink URL stays the same. Only the page visitors are redirected to changes.

**Q: What happens when a shortlink expires?**
A: Visitors receive a "Page not found" (404) error. The shortlink is marked as disabled in the listing. You can re-enable it by clearing the expiration settings and re-saving.

**Q: Can two shortlinks point to the same destination?**
A: Yes. Multiple shortlinks can point to the same destination (useful for tracking different traffic sources to the same page).

**Q: Why doesn't my QR code work after I changed the destination?**
A: The QR code encodes the shortlink URL (e.g., `https://example.com/go/xE4iqh`), not the destination directly. The QR code always works as long as the shortlink is active — it will redirect to whatever destination is currently configured.

**Q: What does "Use shortlinks" permission mean?**
A: It means the user can follow shortlink redirects. All visitors (including those not logged in) have this permission by default. Without it, visitors would be denied access to shortlink URLs.
