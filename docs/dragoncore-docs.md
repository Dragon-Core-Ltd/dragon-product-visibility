# Dragon Product Visibility

Per-customer and per-role product visibility for WooCommerce — hide products from everyone except the customers who should see them.

## Usage
Edit any product and open the **Visibility Restrictions** tab in the Product data panel: pick a Restriction Mode, then the customers and/or roles it applies to. Hidden products disappear from the shop, search, archives and direct URLs for everyone else.

Variable products are covered as a whole: a variation of a hidden product is hidden as well, including in the WooCommerce Store API's variation listings and single-variation lookups, and it cannot be added to the cart. A visitor who opens a hidden product's URL is sent to the shop page with a notice.

Searching for customers on the product screen shows account names and email addresses, so it requires the `manage_woocommerce` or `list_users` capability.

## Typical setups
- **Wholesale items** visible only to a "wholesale" role.
- **Client-specific products** visible only to that client's account.
- **Members-only ranges** for logged-in customers.

## Category & tag visibility rules
Instead of setting visibility one product at a time, you can restrict whole categories or tags by role. Open **Products → Visibility Rules**, pick a category or tag, then choose:
- **Hide from the selected roles** (blacklist) — everyone sees it except the roles you name.
- **Show only to the selected roles** (whitelist) — only the roles you name can see it, and it is also hidden from logged-out visitors.

A rule on a category automatically covers its sub-categories. A visibility rule set on an individual product (the per-product **Visibility Restrictions** tab) always takes precedence over a category or tag rule. Managing rules requires the `manage_woocommerce` capability.

## Data & privacy
Restriction mode and role rules are stored as product metadata; per-customer visibility lives in the plugin's own database table in your database. **Uninstall keeps rules by default** (`wp option update dpv_delete_data_on_uninstall 1` to opt into deletion).
