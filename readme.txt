=== Dragon Product Visibility for WooCommerce ===
Contributors: dragoncoreltd
Tags: woocommerce, product visibility, customer restrictions, role based access, private products
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 10.4
Stable tag: 1.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restrict WooCommerce product visibility by specific customers or user roles. Simple per-customer restrictions without complex membership plugins.

== Description ==

Dragon Product Visibility lets you control which customers can see and purchase specific products in your WooCommerce store. Perfect for B2B stores, exclusive products, or customer-specific pricing tiers.

**Key Features:**

* **Per-Customer Restrictions** - Make products visible only to specific customers
* **Role-Based Access** - Restrict products by WordPress user role
* **Whitelist Mode** - Only selected users/roles can see the product
* **Blacklist Mode** - Hide products from specific users/roles (everyone else can see)
* **Complete Hiding** - Restricted products are hidden from shop, search, and direct URL access
* **Cart Protection** - Prevents adding restricted products to cart
* **Lightweight** - No bloat, just focused functionality

**How It Works:**

1. Edit any WooCommerce product
2. Go to the "Visibility Restrictions" tab
3. Choose "Whitelist" (only selected see it) or "Blacklist" (hide from selected)
4. Select specific customers or user roles
5. Save - restrictions are now active

**Use Cases:**

* **B2B Stores** - Show wholesale products only to approved business customers (whitelist)
* **VIP Products** - Create exclusive items for your best customers (whitelist)
* **Competitor Blocking** - Hide products from known competitor accounts (blacklist)
* **Custom Pricing** - Different product catalogs for different customer tiers
* **Pre-release Access** - Let select customers preview new products (whitelist)
* **Regional Restrictions** - Hide products from certain user groups (blacklist)

**What Happens When Someone Can't Access:**

* Product won't appear in shop listings or search results
* Direct URL access redirects to shop with an error message
* Can't be added to cart even if URL is known
* Items removed from cart if access is revoked

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dragon-product-visibility/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Edit any product and look for the "Visibility Restrictions" tab
4. Configure restrictions and save

== Frequently Asked Questions ==

= Does this work with variable products? =

Yes! Restrictions apply to the entire product including all variations.

= Can I restrict by user role? =

Yes, you can select specific WordPress roles that should have access to the product.

= What happens if I restrict a product that's already in someone's cart? =

The product will be automatically removed from their cart when they visit the cart or checkout page, with a notice explaining why.

= Do admins and shop managers see restricted products? =

Yes, users with the `manage_woocommerce` capability can always see all products.

= Can guests (non-logged-in users) see restricted products? =

For whitelist mode: No, guests cannot see whitelisted products.
For blacklist mode: Yes, guests can see the product unless you specifically need to block them.

= What's the difference between whitelist and blacklist mode? =

**Whitelist:** Product is hidden by default. Only users you specifically select can see it. Use this for exclusive/VIP products.

**Blacklist:** Product is visible by default. Only users you specifically select are blocked from seeing it. Use this to hide products from competitors or specific accounts.

= Does this affect product feeds or APIs? =

The plugin filters WooCommerce's standard product queries. REST API access depends on the authentication context.

= Is this compatible with HPOS (High-Performance Order Storage)? =

Yes, the plugin declares HPOS compatibility.

== Changelog ==

= 1.0.10 =
* Every screen, email and alert is now translatable, and translations bundled in the plugin's languages folder now load. Counts use proper plural forms, and numbers and dates follow your site's language.
* Product names in the cart notice are escaped.

= 1.0.9 =
* Fixed: saving a product's visibility restrictions could silently lose its customer list. The old list was deleted before the new one was written, with neither step checked, and a failed write was still reported as "saved". The save now only removes deselected customers and adds newly selected ones, and a failed write is reported with an error on the product screen or in the AJAX response instead of a success message.
* Fixed: a part-finished save could leave a product visible to a customer it was meant to hide from. On a database that cannot undo a half-finished save - an older MyISAM table, or a host that hides the information the plugin needs to tell - deleting the old customer rows could succeed while writing the new ones failed, leaving a blacklisted customer unblocked (or a new customer allowed in under a whitelist) while the screen said the product kept its previous rules. The plugin now confirms that both its own table and the post meta table can undo a half-finished save before it starts one, orders the writes so an interrupted save can only leave a product more restricted rather than less, puts back anything it did manage to write, and reads the rules back afterwards so the message tells you what is actually stored.
* Fixed: the customer-visibility AJAX save only checked the general "edit products" capability. It now also requires permission to edit the specific product being changed.
* Fixed: the plugin's database version was recorded on activation even when the customer visibility table had not actually been created. The table is now confirmed before the version is stored, and a missing table is recreated on a later admin load (checked at most once every five minutes).
* Fixed: the table definition is now in the form WordPress's dbDelta() can compare against an existing table, so future schema changes can be applied on update.
* Fixed: on the Visibility Rules screen, a category/tag rule whose save did not land was still reported as added or deleted. Both actions now report a failure and leave your existing rules unchanged.

= 1.0.8 =
* Performance: category/tag visibility rules now prime WordPress's term cache for the whole candidate set, so a shop with a large hidden category no longer runs an extra term lookup per product on front-end and Store API requests.

= 1.0.7 =
* New: category & tag visibility rules. Under Products → Visibility Rules, hide a whole category or tag from chosen roles, or show it only to them, in a single step. Rules on a category automatically cover its sub-categories, and "show only to" also hides the products from logged-out visitors. Rules set on an individual product still take precedence.

= 1.0.5 =
* Compatibility: tested up to WordPress 7.1.
* Housekeeping: corrected the contributor name in the plugin readme.

= 1.0.4 =
* Polish: the WooCommerce-required notice now only appears on the Plugins screen.

= 1.0.3 =
* Data safety: uninstalling the plugin no longer deletes its data unless you explicitly opt in first — a reinstall now picks up exactly where you left off.

= 1.0.2 =
* Fix: the admin customer-search script was not updated to the new prefix, breaking the visibility selector; settings also carry safely on reactivate.

= 1.0.1 =
* Renamed all option, hook and constant prefixes to the unique `dragonproductvisibility_` / `DRAGONPRODUCTVISIBILITY_` prefix. Existing settings are migrated automatically on update; product data (stored as post/user meta) is unaffected.

= 1.0.0 =
* Initial release
* Whitelist mode - show products only to selected users/roles
* Blacklist mode - hide products from selected users/roles
* Per-customer product restrictions
* Role-based access control
* Direct URL protection
* Cart validation
* WooCommerce blocks support

== Upgrade Notice ==

= 1.0.10 =
Translation-ready throughout.

= 1.0.9 =
Fixes a save path that could silently drop a product's customer restrictions: existing customers are no longer deleted before the new list is written, and failures are reported.

= 1.0.0 =
Initial release.
