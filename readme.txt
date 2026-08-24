=== Persistent Account Menu for HivePress ===
Tags: hivepress, account, menu, empty state, dashboard
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.6
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Keeps HivePress account menu items visible even when they are empty, and replaces each empty page with a helpful notice, icon and button.

== Description ==

By default, HivePress and its extensions only show an account menu item once there is something to list: no favourites means no Favorites link, no bookings means no Bookings link. That keeps menus tidy, but it also hides features from new users. This plugin keeps the menu items in place and turns each empty page into a friendly empty state with a call to action.

Items are only forced when the matching extension is active, and vendor-only items only appear for users with a vendor profile:

* Listings (HivePress core)
* Favorites (Favorites)
* Requests and Offers (Requests)
* Calendar and Bookings (Bookings)
* Saved Searches (Search Alerts)
* Messages (Messages, when message storage is enabled)
* Membership (Memberships)
* Orders, Placed Orders and Payouts (Marketplace / WooCommerce)
* Subscriptions (WooCommerce Subscriptions)

Under **HivePress - Settings - Default Menu Items** you can pick which menu items stay visible, and customise the button on each placeholder page: change its label, point it at a custom URL, or add a button to pages that have none by default.

All texts are translatable under the `persistent-account-menu-for-hivepress` text domain, with a ready-made template in `languages/`. If you translate or reword them with Loco Translate, choose the **System** location when Loco asks where to save: that is WordPress's own `wp-content/languages/plugins/` folder, which is loaded automatically and, unlike the plugin's own folder, survives plugin updates. Three developer filters are provided: `hppam/v1/items` (adjust the managed items), `hppam/v1/notice_html` (filter the rendered notice HTML) and `hppam/v1/native_item` (decide for yourself whether a page counts as populated).

== Installation ==

1. Download the latest release zip from the GitHub releases page.
2. In your WordPress dashboard, go to Plugins, Add New Plugin, Upload Plugin, and upload the zip.
3. Activate Persistent Account Menu for HivePress.
4. Visit HivePress, Settings, Default Menu Items to choose the items to keep visible.

== Frequently Asked Questions ==

= Why does a menu item still not appear? =

Items only appear when the matching HivePress extension is active, and vendor-only items (Calendar, Received Orders, Payouts) only appear for users with a vendor profile. Check the item is also ticked under HivePress, Settings, Default Menu Items.

= Does this change what visitors see? =

No. The account menu is only shown to logged-in users, and the plugin only affects their own account pages.

= Why does /account/ now open a different page than before? =

That is HivePress, not a setting in this plugin. The account home does not have a page of its own: HivePress sends people straight to the first item in their account menu. Keeping more items visible therefore changes which item is first, so members may land on Listings where they used to land somewhere else.

If your site sends members through an onboarding step (completing a vendor profile, for example) that step may now appear sooner. To land people somewhere else, untick the items you do not want at the top of the menu under HivePress, Settings, Default Menu Items.

== Changelog ==

= 1.6.6 =
* Fixed - hiding an item from the account menu no longer empties the page behind it. If you use
  another plugin to take an item off the menu, this one read that missing item as an empty page,
  so the page itself showed the "nothing here yet" notice with its real contents wiped out, even
  for a member with listings, bookings or messages sitting there. Whether a page is empty is now
  judged only on what the extensions themselves put in the menu, and your own choices about which
  items to show are left out of it.
* Fixed - the changelog in the "View details" popup no longer loses the wording of a link. A link
  whose wording contained a piece of code arrived with that wording replaced by a stray number,
  and if the formatting ran into trouble a whole line could come through as bare numbers in place
  of its links and code. Release notes are now put back together in full, and anything that still
  cannot be restored is removed rather than shown.

= 1.6.5 =
* Fixed - updates are offered again. The 1.6.4 change that moved update checks into the background
  filled a cache the rest of the plugin never read, so a published release was never shown on the
  Plugins screen, "View details" opened an error, and the notice after pressing Check for updates
  named no version. The cached answer is now read wherever WordPress asks.

= 1.6.4 =
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.

= 1.6.3 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.6.2 =
* Fixed: the author shown on the Plugins screen now reads "ChrisB @ HivePress Community" and links to the right profile page, matching every other extension in the range.
* Fixed: the star icon on the "Donate" link no longer sits flush against the word.
* Removed: the thank-you line under the settings form. The "Donate" link on the Plugins screen and in the plugin details popup is the only place the ask appears now, so it never interrupts you while you are configuring the plugin.

= 1.6.1 =
* Changed: the Plugins-screen link now reads "Donate", matching the wording WordPress uses in the plugin details popup.
* Fixed: the changelog in the plugin details popup showed raw formatting marks such as ** instead of bold text and bullet points.
* Improved: the empty-page button carries the same class list as the buttons in HivePress's own extensions.
* Improved: the readme now explains where to save a translation so it survives plugin updates, and answers why the account home may open a different page once items are kept visible.

= 1.6.0 =
* Changed: deleting the plugin now KEEPS your settings by default. If you want them removed, tick the new "Delete All Data" box in the Removing the Plugin section before deleting. WordPress shows its own warning on the delete screen saying data goes too; that warning does not apply here unless you tick the box.
* Added: a note on the settings page when one of the empty pages has been customised under HivePress, Templates, because the notice cannot be shown on a page you have designed yourself.
* Added: a Sponsor link on the Plugins screen and a thank-you under the settings form.
* Fixed: the update check no longer sends your site address and WordPress version to GitHub.
* Fixed: "Check for updates" now says plainly when no release has been published yet, instead of reporting it as a connection error.
* Improved: the "HivePress is missing" notice can now be dismissed.

= 1.5.3 =
* Fixed: menu items added after your settings were saved (for example by activating a new HivePress extension) are now switched on automatically instead of silently staying hidden.
* Changed: translations now load from the standard WordPress location only, matching how HivePress and its official extensions work.
* Improved: the notice button and sizing now reuse HivePress's own classes and scale, so the empty pages match your theme even more closely.

= 1.5.2 =
* Fixed: the empty-state notice never appeared on the vendor calendar page, because its template sits outside the account template family.
* Added: uninstall cleanup that removes all of the plugin's options and cached update data.
* Added: an admin notice when HivePress is missing, and this readme.
* Changed: blocks are now injected with HivePress's own merge_blocks, ahead of the announced deprecation of merge_trees.
* Improved: settings screen copy (section title and tooltips on every field) and British English throughout.

= 1.5.1 =
* Earlier history is on the GitHub releases page.
