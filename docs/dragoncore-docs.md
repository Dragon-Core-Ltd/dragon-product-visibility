# Dragon Product Visibility

Per-customer and per-role product visibility for WooCommerce — hide products from everyone except the customers who should see them.

## Usage
Edit any product and find the **Visibility** box: choose specific customers and/or roles who may see it. Hidden products disappear from the shop, search, archives and direct URLs for everyone else.

## Typical setups
- **Wholesale items** visible only to a "wholesale" role.
- **Client-specific products** visible only to that client's account.
- **Members-only ranges** for logged-in customers.

## Data & privacy
Restriction mode and role rules are stored as product metadata; per-customer visibility lives in the plugin's own database table in your database. **Uninstall keeps rules by default** (`wp option update dpv_delete_data_on_uninstall 1` to opt into deletion).
