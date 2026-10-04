#!/usr/bin/env bash
# Real-HTTP scenarios for the product edit screen save, the two AJAX handlers,
# the category/tag rules screen, and what each kind of visitor then sees.
#
# Usage: tests/env/wporg-scenarios.sh <wp-env dir> [port]
# Runs against a throwaway wp-env built from copies of the plugin, with
# WooCommerce active. Everything it creates is named dpv-scn-* and removed at
# the end: products, terms, users, one role, its own category/tag rules and one
# must-use file that lets its own requests past WooCommerce's coming-soon page.

set -u -o pipefail

ENV_DIR="${1:?usage: wporg-scenarios.sh <wp-env dir> [port]}"
ENV_DIR="$(cd "$ENV_DIR" && pwd)"
PORT="${2:-$(php -r '$c = json_decode( file_get_contents( $argv[1] ), true ); echo $c["port"] ?? 8888;' "$ENV_DIR/.wp-env.json")}"
BASE="http://localhost:$PORT"
WORKSPACE=/Users/rich/Sites/wp-plugins
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/dpvwporg.XXXXXX")"
ADMIN_JAR="$TMP/admin.jar"
SUB_JAR="$TMP/sub.jar"
CUST_JAR="$TMP/cust.jar"
VIP_JAR="$TMP/vip.jar"
PASSWORD='Dpv-scn-pass-1!'
# A role id as another plugin might register it: mixed case, a space, a hyphen and a dot.
VIP_ROLE='DPV-Scn Whole.Sale'
SLUG_RE='/plugins/dragon-product-visibility/'

PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf 'PASS %s\n' "$*"; }
fail() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$*"; }
check() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (expected [$2], got [$1])"; fi; }
contains() { if grep -qF -- "$2" <<<"$1"; then pass "$3"; else fail "$3 (missing [$2])"; fi; }
lacks() { if grep -qF -- "$2" <<<"$1"; then fail "$3 (found [$2])"; else pass "$3"; fi; }

# --- WP-CLI ------------------------------------------------------------------

ENV_NAME="$(basename "$ENV_DIR")"
CLI="$(docker ps --format '{{.Names}}' | grep -E "^wp-env-${ENV_NAME}-[0-9a-f]+-cli-1\$" | head -1)"
WEB="$(docker ps --format '{{.Names}}' | grep -E "^wp-env-${ENV_NAME}-[0-9a-f]+-wordpress-1\$" | head -1)"

wp_cli() {
	if [ -n "$CLI" ]; then
		docker exec -u www-data "$CLI" wp --path=/var/www/html "$@" 2>/dev/null < /dev/null
	else
		( cd "$ENV_DIR" && NODE_OPTIONS="--require $WORKSPACE/force-ipv4.js" \
			"$WORKSPACE/node_modules/.bin/wp-env" run cli wp "$@" 2>/dev/null < /dev/null )
	fi
}

# `wp eval` with the PHP on stdin.
wp_php() { local code; code="$(cat)"; wp_cli eval "$code"; }

# One value out of a JSON object: json_get '<json>' key.
json_get() { php -r '$d = json_decode( $argv[1], true ); $v = $d[ $argv[2] ] ?? ""; echo is_scalar( $v ) ? $v : json_encode( $v );' "$1" "$2"; }

# --- HTTP --------------------------------------------------------------------

login() {
	local jar="$1" user="$2" pass="$3"
	rm -f "$jar"
	curl -s -o /dev/null -c "$jar" "$BASE/wp-login.php"
	curl -s -o /dev/null -b "$jar" -c "$jar" \
		--data-urlencode "log=$user" --data-urlencode "pwd=$pass" \
		--data-urlencode 'wp-submit=Log In' --data-urlencode "redirect_to=$BASE/wp-admin/" \
		--data-urlencode 'testcookie=1' "$BASE/wp-login.php"
	grep -q 'wordpress_logged_in_' "$jar"
}

page() { curl -s -b "$1" "$BASE/wp-admin/$2"; }

# POST a urlencoded body to a wp-admin file. Prints "<status> <location>"; the
# response body is left in $TMP/body.
admin_send() {
	local jar="$1" file="$2"
	shift 2
	curl -s -b "$jar" -o "$TMP/body" -D "$TMP/headers" "$@" "$BASE/wp-admin/$file"
	local status location
	status="$(head -1 "$TMP/headers" | awk '{print $2}')"
	location="$(grep -i '^location:' "$TMP/headers" | tr -d '\r' | sed 's/^[Ll]ocation: //')"
	printf '%s %s\n' "$status" "$location"
}

# The logged_in cookie value from a jar, URL-decoded the way PHP fills $_COOKIE.
logged_in_cookie() {
	local raw
	raw="$(grep 'wordpress_logged_in_' "$1" | awk -F'\t' '{print $7}' | head -1)"
	php -r 'echo urldecode( $argv[1] );' "$raw"
}

# A nonce for an action, minted for the session in the given jar.
mint_nonce() {
	local jar="$1" action="$2" cookie
	cookie="$(logged_in_cookie "$jar")"
	wp_php <<PHP
\$cookie = '$cookie';
\$_COOKIE[ LOGGED_IN_COOKIE ] = \$cookie;
\$user = wp_validate_auth_cookie( \$cookie, 'logged_in' );
wp_set_current_user( (int) \$user );
echo wp_create_nonce( '$action' );
PHP
}

# POST to admin-ajax.php; prints the JSON response.
ajax() {
	local jar="$1"
	shift
	curl -s -b "$jar" "$@" "$BASE/wp-admin/admin-ajax.php"
}

# What is stored for a product: mode, roles, customer rows.
state_of() {
	wp_php <<PHP
global \$wpdb;
\$rows = array_map( 'intval', (array) \$wpdb->get_col( \$wpdb->prepare( "SELECT customer_id FROM {\$wpdb->prefix}dpv_customer_visibility WHERE product_id = %d ORDER BY customer_id", $1 ) ) );
echo wp_json_encode(
	array(
		'mode'      => get_post_meta( $1, '_dpv_restriction_mode', true ),
		'roles'     => get_post_meta( $1, '_dpv_visible_roles', true ),
		'customers' => \$rows,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
PHP
}

rules_json() { wp_php <<'PHP'
echo wp_json_encode( get_option( 'dragonproductvisibility_bulk_rules', array() ), JSON_UNESCAPED_SLASHES );
PHP
}

# The id of the stored rule on a term, or nothing.
rule_id_for_term() {
	wp_php <<PHP
foreach ( (array) get_option( 'dragonproductvisibility_bulk_rules', array() ) as \$rule ) {
	if ( (int) \$rule['term_id'] === $1 ) {
		echo \$rule['id'];
		break;
	}
}
PHP
}

debug_size() { wp_php <<'PHP'
$f = WP_CONTENT_DIR . '/debug.log';
clearstatcache();
echo file_exists( $f ) ? filesize( $f ) : 0;
PHP
}

debug_since() {
	wp_php <<PHP
\$f = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( \$f ) ) {
	echo (string) file_get_contents( \$f, false, null, $1 );
}
PHP
}

# Remove everything named dpv-scn-*: products, their customer rows, terms, the
# rules on those terms, attachments, comments, users and the role.
remove_data() {
	wp_php <<PHP >/dev/null
require_once ABSPATH . 'wp-admin/includes/user.php';
global \$wpdb;

\$terms = array();
foreach ( array( 'product_cat', 'product_tag' ) as \$taxonomy ) {
	foreach ( (array) get_terms( array( 'taxonomy' => \$taxonomy, 'hide_empty' => false, 'search' => 'dpv-scn-' ) ) as \$term ) {
		if ( 0 === strpos( \$term->slug, 'dpv-scn-' ) ) {
			\$terms[] = (int) \$term->term_id;
			wp_delete_term( \$term->term_id, \$taxonomy );
		}
	}
}

\$rules = (array) get_option( 'dragonproductvisibility_bulk_rules', array() );
\$kept  = array();
foreach ( \$rules as \$rule ) {
	if ( ! in_array( (int) ( \$rule['term_id'] ?? 0 ), \$terms, true ) ) {
		\$kept[] = \$rule;
	}
}
if ( \$kept !== \$rules ) {
	update_option( 'dragonproductvisibility_bulk_rules', \$kept, false );
}

foreach ( (array) \$wpdb->get_col( "SELECT comment_ID FROM {\$wpdb->comments} WHERE comment_author_email LIKE 'dpv-scn-%'" ) as \$id ) {
	wp_delete_comment( (int) \$id, true );
}

foreach ( (array) \$wpdb->get_col( "SELECT ID FROM {\$wpdb->posts} WHERE post_type = 'attachment' AND post_name LIKE 'dpv-scn-%'" ) as \$id ) {
	wp_delete_post( (int) \$id, true );
}

foreach ( (array) \$wpdb->get_col( "SELECT ID FROM {\$wpdb->posts} WHERE post_type = 'product' AND post_name LIKE 'dpv-scn-%'" ) as \$id ) {
	\$wpdb->delete( \$wpdb->prefix . 'dpv_customer_visibility', array( 'product_id' => (int) \$id ), array( '%d' ) );
	wp_delete_post( (int) \$id, true );
}

foreach ( (array) \$wpdb->get_col( "SELECT ID FROM {\$wpdb->users} WHERE user_login LIKE 'dpv-scn-%'" ) as \$id ) {
	\$wpdb->delete( \$wpdb->prefix . 'dpv_customer_visibility', array( 'customer_id' => (int) \$id ), array( '%d' ) );
	wp_delete_user( (int) \$id );
}

remove_role( '$VIP_ROLE' );
PHP
}

# WooCommerce's coming-soon page hides the shop from every non-manager. This
# must-use file lets through only the requests that carry the scenario header.
MU_FILE=/var/www/html/wp-content/mu-plugins/dpv-scn-coming-soon.php
install_mu() {
	docker exec -i "${CLI:-$WEB}" sh -c "mkdir -p $(dirname "$MU_FILE") && cat > $MU_FILE" <<'PHP'
<?php
// Dragon Product Visibility scenarios: skip WooCommerce's coming-soon page for requests that send X-Dpv-Scn.
add_filter(
	'woocommerce_coming_soon_exclude',
	static function ( $exclude ) {
		return isset( $_SERVER['HTTP_X_DPV_SCN'] ) ? true : $exclude;
	}
);
PHP
}
remove_mu() { docker exec "${CLI:-$WEB}" rm -f "$MU_FILE" 2>/dev/null; }
# The must-use folder is shared with other scenario scripts, which may clear it.
ensure_mu() { docker exec "${CLI:-$WEB}" test -f "$MU_FILE" 2>/dev/null || install_mu; }

cleanup() {
	remove_data
	remove_mu
	rm -rf "$TMP"
}
trap cleanup EXIT

# --- setup -------------------------------------------------------------------

printf '== Dragon Product Visibility WP.org scenarios against %s ==\n' "$BASE"
LOG_START="$(debug_size)"
printf 'debug.log starts at %s bytes\n' "$LOG_START"

remove_data
install_mu

IDS="$(wp_php <<PHP
add_role( '$VIP_ROLE', 'DPV Scn Wholesale', array( 'read' => true ) );

\$out = array();
foreach ( array( 'sub' => 'subscriber', 'cust' => 'customer', 'vip' => '$VIP_ROLE' ) as \$key => \$role ) {
	\$out[ \$key ] = wp_insert_user(
		array(
			'user_login' => 'dpv-scn-' . \$key,
			'user_pass'  => '$PASSWORD',
			'user_email' => 'dpv-scn-' . \$key . '@example.test',
			'role'       => \$role,
		)
	);
}

\$cat = wp_insert_term( 'dpv-scn-cat', 'product_cat', array( 'slug' => 'dpv-scn-cat' ) );
\$tag = wp_insert_term( 'dpv-scn-tag', 'product_tag', array( 'slug' => 'dpv-scn-tag' ) );
\$out['term_cat'] = (int) \$cat['term_id'];
\$out['term_tag'] = (int) \$tag['term_id'];

foreach ( array( 'hidden', 'viponly', 'catprod', 'tagprod', 'open', 'ajax' ) as \$key ) {
	\$product = new WC_Product_Simple();
	\$product->set_name( 'dpv-scn-' . \$key );
	\$product->set_slug( 'dpv-scn-' . \$key );
	\$product->set_status( 'publish' );
	\$product->set_regular_price( '9' );
	if ( 'catprod' === \$key ) {
		\$product->set_category_ids( array( \$out['term_cat'] ) );
	}
	if ( 'tagprod' === \$key ) {
		\$product->set_tag_ids( array( \$out['term_tag'] ) );
	}
	\$out[ \$key ]          = \$product->save();
	\$out[ 'url_' . \$key ] = get_permalink( \$out[ \$key ] );
}
\$grouped = new WC_Product_Grouped();
\$grouped->set_name( 'dpv-scn-grouped' );
\$grouped->set_slug( 'dpv-scn-grouped' );
\$grouped->set_status( 'publish' );
\$grouped->set_children( array( \$out['open'] ) );
\$out['grouped'] = \$grouped->save();
\$out['shop'] = wc_get_page_permalink( 'shop' );
echo wp_json_encode( \$out, JSON_UNESCAPED_SLASHES );
PHP
)"
SUB_ID="$(json_get "$IDS" sub)"
CUST_ID="$(json_get "$IDS" cust)"
VIP_ID="$(json_get "$IDS" vip)"
CAT_ID="$(json_get "$IDS" term_cat)"
TAG_ID="$(json_get "$IDS" term_tag)"
P_HIDDEN="$(json_get "$IDS" hidden)"
P_VIP="$(json_get "$IDS" viponly)"
P_CAT="$(json_get "$IDS" catprod)"
P_TAG="$(json_get "$IDS" tagprod)"
P_OPEN="$(json_get "$IDS" open)"
P_AJAX="$(json_get "$IDS" ajax)"
P_GROUPED="$(json_get "$IDS" grouped)"
SHOP_URL="$(json_get "$IDS" shop)"
PAGE_ID="$(wp_cli post list --post_type=page --post_status=publish --field=ID --posts_per_page=1 | head -1)"
if [ -n "$SUB_ID" ] && [ -n "$P_HIDDEN" ] && [ -n "$P_AJAX" ] && [ -n "$CAT_ID" ]; then
	pass "fixtures created (users $SUB_ID/$CUST_ID/$VIP_ID, products $P_HIDDEN/$P_VIP/$P_CAT/$P_TAG/$P_OPEN/$P_AJAX, terms $CAT_ID/$TAG_ID)"
else
	fail "fixtures created ($IDS)"
fi

login "$ADMIN_JAR" admin password && pass 'admin logs in' || fail 'admin logs in'
login "$SUB_JAR" dpv-scn-sub "$PASSWORD" && pass 'subscriber logs in' || fail 'subscriber logs in'
login "$CUST_JAR" dpv-scn-cust "$PASSWORD" && pass 'shop customer logs in' || fail 'shop customer logs in'
login "$VIP_JAR" dpv-scn-vip "$PASSWORD" && pass "user with the role \"$VIP_ROLE\" logs in" || fail "user with the role \"$VIP_ROLE\" logs in"

EMPTY_STATE='{"mode":"","roles":"","customers":[]}'

# --- product edit screen: the Visibility Restrictions tab ----------------------

page "$ADMIN_JAR" "post.php?post=$P_HIDDEN&action=edit" > "$TMP/edit-hidden.html"
html="$(cat "$TMP/edit-hidden.html")"
contains "$html" 'name="dragonproductvisibility_visibility_nonce"' 'product edit screen carries the visibility nonce field'
contains "$html" "<option value=\"$VIP_ROLE\"" 'role picker offers the mixed-case role under its exact id'
AJAX_NONCE="$(grep -oE 'var dragonproductvisibility_admin = \{.*\}' "$TMP/edit-hidden.html" | grep -oE '"nonce":"[0-9a-f]+"' | head -1 | sed -E 's/.*:"([0-9a-f]+)"/\1/')"
[ -n "$AJAX_NONCE" ] && pass 'AJAX nonce scraped from the localized script' || fail 'AJAX nonce scraped from the localized script'
check "$(mint_nonce "$ADMIN_JAR" dragonproductvisibility_admin_nonce)" "$AJAX_NONCE" 'nonce minted from the session cookie matches the page nonce (minting is sound)'

check "$(state_of "$P_HIDDEN")" "$EMPTY_STATE" 'a new product has no stored rules'

# Refused: the form submitted without the plugin's nonce field.
php "$HERE/form-body.php" '#post' save=Update '-dragonproductvisibility_visibility_nonce' \
	'dragonproductvisibility_restriction_mode=blacklist' 'dragonproductvisibility_roles[]=customer' \
	"dragonproductvisibility_customers[]=$VIP_ID" < "$TMP/edit-hidden.html" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '302' 'edit form without the visibility nonce: core still saves the product'
check "$(state_of "$P_HIDDEN")" "$EMPTY_STATE" 'edit form without the visibility nonce: no rules stored'

# Refused: a wrong value in the plugin's nonce field.
php "$HERE/form-body.php" '#post' save=Update 'dragonproductvisibility_visibility_nonce=0123456789' \
	'dragonproductvisibility_restriction_mode=blacklist' 'dragonproductvisibility_roles[]=customer' \
	"dragonproductvisibility_customers[]=$VIP_ID" < "$TMP/edit-hidden.html" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
check "$(state_of "$P_HIDDEN")" "$EMPTY_STATE" 'edit form with a bad visibility nonce: no rules stored'

# Refused: users who cannot edit the product, each with nonces valid for their own session.
for who in sub cust; do
	if [ "$who" = sub ]; then jar="$SUB_JAR"; label='subscriber'; else jar="$CUST_JAR"; label='shop customer'; fi
	n_post="$(mint_nonce "$jar" "update-post_$P_HIDDEN")"
	n_wc="$(mint_nonce "$jar" woocommerce_save_data)"
	n_own="$(mint_nonce "$jar" dragonproductvisibility_save_visibility)"
	out="$(admin_send "$jar" post.php \
		--data-urlencode 'action=editpost' --data-urlencode "post_ID=$P_HIDDEN" --data-urlencode 'post_type=product' \
		--data-urlencode "_wpnonce=$n_post" --data-urlencode "woocommerce_meta_nonce=$n_wc" \
		--data-urlencode "dragonproductvisibility_visibility_nonce=$n_own" \
		--data-urlencode 'dragonproductvisibility_restriction_mode=blacklist' \
		--data-urlencode 'dragonproductvisibility_roles[]=customer')"
	lacks "$out" 'message=' "$label posting the edit form: not saved ($out)"
	check "$(state_of "$P_HIDDEN")" "$EMPTY_STATE" "$label posting the edit form: no rules stored"
done

# Happy path: hide from the customer role and from one named customer.
php "$HERE/form-body.php" '#post' save=Update \
	'dragonproductvisibility_restriction_mode=blacklist' 'dragonproductvisibility_roles[]=customer' \
	"dragonproductvisibility_customers[]=$VIP_ID" < "$TMP/edit-hidden.html" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '302' 'edit form save redirects'
contains "$out" 'message=' 'edit form save reports the product updated'
lacks "$out" 'dragonproductvisibility_save_error' 'edit form save carries no error flag'
check "$(state_of "$P_HIDDEN")" "{\"mode\":\"blacklist\",\"roles\":[\"customer\"],\"customers\":[$VIP_ID]}" 'edit form save stores the mode, the role and the customer'

# Happy path: show only to the mixed-case role and to one named customer.
page "$ADMIN_JAR" "post.php?post=$P_VIP&action=edit" > "$TMP/edit-vip.html"
php "$HERE/form-body.php" '#post' save=Update \
	'dragonproductvisibility_restriction_mode=whitelist' "dragonproductvisibility_roles[]=$VIP_ROLE" \
	"dragonproductvisibility_customers[]=$SUB_ID" "dragonproductvisibility_customers[]=$SUB_ID" < "$TMP/edit-vip.html" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'message=' 'edit form save with the mixed-case role reports the product updated'
check "$(state_of "$P_VIP")" "{\"mode\":\"whitelist\",\"roles\":[\"$VIP_ROLE\"],\"customers\":[$SUB_ID]}" 'mixed-case role id stored byte for byte; a repeated customer stored once'

html="$(page "$ADMIN_JAR" "post.php?post=$P_VIP&action=edit")"
if grep -qE "<option value=\"$VIP_ROLE\" +selected" <<<"$html"; then
	pass 'reload shows the mixed-case role selected'
else
	fail 'reload shows the mixed-case role selected'
fi
contains "$html" "<option value=\"$SUB_ID\" selected=\"selected\">" 'reload shows the saved customer selected'

# Saving the screen again as it was rendered keeps the rules.
php "$HERE/form-body.php" '#post' save=Update <<<"$html" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
check "$(state_of "$P_VIP")" "{\"mode\":\"whitelist\",\"roles\":[\"$VIP_ROLE\"],\"customers\":[$SUB_ID]}" 'saving the reloaded form unchanged keeps the same rules'

for flag in 1 2; do
	html="$(page "$ADMIN_JAR" "post.php?post=$P_OPEN&action=edit&dragonproductvisibility_save_error=$flag")"
	contains "$html" 'Visibility restrictions could not be saved' "edit screen renders the save-error notice for flag $flag"
done
html="$(page "$ADMIN_JAR" "post.php?post=$P_OPEN&action=edit")"
lacks "$html" 'Visibility restrictions could not be saved' 'edit screen without the flag shows no save-error notice'

# --- AJAX: save visibility rules ------------------------------------------------

save_fields=(
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules'
	--data-urlencode "product_id=$P_AJAX"
	--data-urlencode 'restriction_mode=whitelist'
	--data-urlencode "customer_ids[]=$CUST_ID"
	--data-urlencode 'role_ids[]=customer'
)

out="$(ajax "$ADMIN_JAR" "${save_fields[@]}")"
contains "$out" '"success":false' 'AJAX save without a nonce: refused'
contains "$out" 'Security check failed' 'AJAX save without a nonce: security message'
check "$(state_of "$P_AJAX")" "$EMPTY_STATE" 'AJAX save without a nonce: nothing stored'

out="$(ajax "$ADMIN_JAR" --data-urlencode 'nonce=0123456789' "${save_fields[@]}")"
contains "$out" 'Security check failed' 'AJAX save with a bad nonce: refused'
check "$(state_of "$P_AJAX")" "$EMPTY_STATE" 'AJAX save with a bad nonce: nothing stored'

out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce[]=$AJAX_NONCE" "${save_fields[@]}")"
contains "$out" '"success":false' 'AJAX save with the nonce sent as an array: refused'
check "$(state_of "$P_AJAX")" "$EMPTY_STATE" 'AJAX save with the nonce sent as an array: nothing stored'

SUB_AJAX_NONCE="$(mint_nonce "$SUB_JAR" dragonproductvisibility_admin_nonce)"
CUST_AJAX_NONCE="$(mint_nonce "$CUST_JAR" dragonproductvisibility_admin_nonce)"
out="$(ajax "$SUB_JAR" --data-urlencode "nonce=$SUB_AJAX_NONCE" "${save_fields[@]}")"
contains "$out" 'Permission denied' 'AJAX save as a subscriber with a valid nonce: refused by the capability check'
check "$(state_of "$P_AJAX")" "$EMPTY_STATE" 'AJAX save as a subscriber: nothing stored'
out="$(ajax "$CUST_JAR" --data-urlencode "nonce=$CUST_AJAX_NONCE" "${save_fields[@]}")"
contains "$out" 'Permission denied' 'AJAX save as a shop customer with a valid nonce: refused by the capability check'
check "$(state_of "$P_AJAX")" "$EMPTY_STATE" 'AJAX save as a shop customer: nothing stored'

out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce=$AJAX_NONCE" \
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules' \
	--data-urlencode "product_id=$PAGE_ID" --data-urlencode 'restriction_mode=whitelist')"
contains "$out" 'Invalid product ID' 'AJAX save on a post that is not a product: refused'

out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce=$AJAX_NONCE" \
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules' \
	--data-urlencode "product_id=$P_AJAX" --data-urlencode 'restriction_mode=whitelist' \
	--data-urlencode "customer_ids[]=$CUST_ID" --data-urlencode "customer_ids[]=$SUB_ID" --data-urlencode "customer_ids[]=$CUST_ID" \
	--data-urlencode "role_ids[]=$VIP_ROLE" --data-urlencode 'role_ids[]=customer')"
contains "$out" '"success":true' 'AJAX save with a valid nonce: saved'
check "$(state_of "$P_AJAX")" "{\"mode\":\"whitelist\",\"roles\":[\"$VIP_ROLE\",\"customer\"],\"customers\":[$SUB_ID,$CUST_ID]}" 'AJAX save stores the mode, both roles byte for byte and each customer once'

QUOTED_ROLE="O'Brien \"Trade\" & Co"
out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce=$AJAX_NONCE" \
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules' \
	--data-urlencode "product_id=$P_AJAX" --data-urlencode 'restriction_mode=blacklist' \
	--data-urlencode "customer_ids=$SUB_ID" \
	--data-urlencode "role_ids[]=$QUOTED_ROLE" --data-urlencode 'role_ids[]=Größe 日本')"
contains "$out" '"success":true' 'AJAX save with quotes, an ampersand and multibyte role ids: saved'
check "$(state_of "$P_AJAX")" "{\"mode\":\"blacklist\",\"roles\":[\"O'Brien \\\"Trade\\\" & Co\",\"Größe 日本\"],\"customers\":[$SUB_ID]}" 'quoted and multibyte role ids stored exactly; a single customer id sent as a string is taken as one id'

LOG_MID="$(debug_size)"
out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce=$AJAX_NONCE" \
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules' \
	--data-urlencode "product_id=$P_AJAX" --data-urlencode 'restriction_mode=whitelist' \
	--data-urlencode "customer_ids[0][]=$CUST_ID" --data-urlencode "customer_ids[1]=$SUB_ID" \
	--data-urlencode 'role_ids[0][]=nested' --data-urlencode 'role_ids[1]=customer')"
contains "$out" '"success":true' 'AJAX save with nested arrays in both lists: saved, no error'
check "$(state_of "$P_AJAX")" "{\"mode\":\"whitelist\",\"roles\":[\"\",\"customer\"],\"customers\":[$SUB_ID]}" 'a nested customer entry is not an id and is dropped; a nested role entry stores an empty role'
lacks "$(debug_since "$LOG_MID" | grep -F "$SLUG_RE" || true)" 'Array to string conversion' 'nested arrays raise no PHP warning'

out="$(ajax "$ADMIN_JAR" --data-urlencode "nonce=$AJAX_NONCE" \
	--data-urlencode 'action=dragonproductvisibility_save_visibility_rules' \
	--data-urlencode "product_id=$P_AJAX" --data-urlencode 'restriction_mode=none')"
contains "$out" '"success":true' 'AJAX save with no lists at all: saved'
check "$(state_of "$P_AJAX")" '{"mode":"none","roles":[],"customers":[]}' 'AJAX save with no lists clears both'

# --- AJAX: customer search -------------------------------------------------------

search="$BASE/wp-admin/admin-ajax.php?action=dragonproductvisibility_search_customers&search_term=dpv-scn-"
out="$(curl -s -b "$ADMIN_JAR" "$search&nonce=$AJAX_NONCE")"
contains "$out" 'dpv-scn-cust@example.test' 'customer search (GET, as Select2 sends it) finds the customer'
contains "$out" "\"id\":\"$SUB_ID\"" 'customer search returns ids'
out="$(ajax "$ADMIN_JAR" --data-urlencode 'action=dragonproductvisibility_search_customers' --data-urlencode "nonce=$AJAX_NONCE" --data-urlencode 'search_term=dpv-scn-vip')"
contains "$out" 'dpv-scn-vip@example.test' 'customer search by POST finds the user'
lacks "$out" 'dpv-scn-cust@example.test' 'customer search only returns matches'
out="$(curl -s -b "$ADMIN_JAR" "$search")"
contains "$out" 'Security check failed' 'customer search without a nonce: refused'
lacks "$out" '@example.test' 'customer search without a nonce: no addresses'
out="$(curl -s -b "$ADMIN_JAR" "$search&nonce=0123456789")"
contains "$out" 'Security check failed' 'customer search with a bad nonce: refused'
out="$(curl -s -b "$SUB_JAR" "$search&nonce=$SUB_AJAX_NONCE")"
contains "$out" 'Permission denied' 'customer search as a subscriber with a valid nonce: refused'
lacks "$out" '@example.test' 'customer search as a subscriber: no addresses'
out="$(curl -s -b "$CUST_JAR" "$search&nonce=$CUST_AJAX_NONCE")"
contains "$out" 'Permission denied' 'customer search as a shop customer with a valid nonce: refused'
lacks "$out" '@example.test' 'customer search as a shop customer: no addresses'
out="$(curl -s "$search&nonce=$AJAX_NONCE")"
lacks "$out" '@example.test' 'customer search logged out: no addresses'

# --- category and tag rules screen -------------------------------------------------

RULES_PAGE='edit.php?post_type=product&page=dragonproductvisibility-rules'
page "$ADMIN_JAR" "$RULES_PAGE" > "$TMP/rules.html"
html="$(cat "$TMP/rules.html")"
contains "$html" 'Product Visibility Rules' 'rules screen renders'
contains "$html" "name=\"dpv_roles[]\" value=\"$VIP_ROLE\"" 'rules screen offers the mixed-case role under its exact id'
RULES_SNAP="$(rules_json)"

add_body() { php "$HERE/form-body.php" 'action:dragonproductvisibility_add_bulk_rule' "$@" < "$TMP/rules.html"; }

add_body '-_wpnonce' "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles[]=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '403' 'add rule without a nonce: 403'
contains "$(cat "$TMP/body")" 'The link you followed has expired.' 'add rule without a nonce: nonce failure page'
check "$(rules_json)" "$RULES_SNAP" 'add rule without a nonce: rules unchanged'

add_body '_wpnonce=0123456789' "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles[]=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '403' 'add rule with a bad nonce: 403'
check "$(rules_json)" "$RULES_SNAP" 'add rule with a bad nonce: rules unchanged'

for who in sub cust; do
	if [ "$who" = sub ]; then jar="$SUB_JAR"; label='subscriber'; else jar="$CUST_JAR"; label='shop customer'; fi
	n_own="$(mint_nonce "$jar" dragonproductvisibility_add_bulk_rule)"
	out="$(admin_send "$jar" admin-post.php \
		--data-urlencode 'action=dragonproductvisibility_add_bulk_rule' --data-urlencode "_wpnonce=$n_own" \
		--data-urlencode "dpv_term=product_cat:$CAT_ID" --data-urlencode 'dpv_mode=blacklist' --data-urlencode 'dpv_roles[]=customer')"
	contains "$(cat "$TMP/body")" 'Permission denied.' "add rule as a $label with a valid nonce: refused by the capability check"
	lacks "$out" 'dpv_msg=added' "add rule as a $label: not reported as added"
	check "$(rules_json)" "$RULES_SNAP" "add rule as a $label: rules unchanged"
done

add_body "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=roles' 'add rule with no role: asks for a role'
add_body "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles[]=dpv-scn-no-such-role' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=roles' 'add rule with a role that does not exist: asks for a role'
add_body "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=roles' 'add rule with the roles sent as a string: asks for a role'
add_body 'dpv_term=product_cat:999999999' 'dpv_mode=blacklist' 'dpv_roles[]=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=term' 'add rule on a term that does not exist: asks for a term'
add_body "dpv_term[]=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles[]=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=term' 'add rule with the term sent as an array: asks for a term'
add_body "dpv_term=product_cat:$CAT_ID" 'dpv_mode=sideways' 'dpv_roles[]=customer' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=mode' 'add rule with an unknown rule type: asks for a rule type'
check "$(rules_json)" "$RULES_SNAP" 'refused rules: nothing stored'

LOG_MID="$(debug_size)"
add_body "dpv_term=product_cat:$CAT_ID" 'dpv_mode=blacklist' 'dpv_roles[]=customer' 'dpv_roles[5][]=nested' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '302' 'add category rule redirects'
contains "$out" 'dpv_msg=added' 'add category rule (with a stray nested role entry) reports the rule added'
CAT_RULE="$(rule_id_for_term "$CAT_ID")"
[ -n "$CAT_RULE" ] && pass 'category rule stored' || fail 'category rule stored'
contains "$(rules_json)" "{\"id\":\"$CAT_RULE\",\"taxonomy\":\"product_cat\",\"term_id\":$CAT_ID,\"mode\":\"blacklist\",\"roles\":[\"customer\"]}" 'category rule stored with the term, the type and only the real role'
lacks "$(debug_since "$LOG_MID" | grep -F "$SLUG_RE" || true)" 'Array to string conversion' 'nested role entry raises no PHP warning'

add_body "dpv_term=product_tag:$TAG_ID" 'dpv_mode=whitelist' "dpv_roles[]=$VIP_ROLE" > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_msg=added' 'add tag rule for the mixed-case role reports the rule added'
TAG_RULE="$(rule_id_for_term "$TAG_ID")"
contains "$(rules_json)" "\"term_id\":$TAG_ID,\"mode\":\"whitelist\",\"roles\":[\"$VIP_ROLE\"]}" 'tag rule stores the mixed-case role id byte for byte'

page "$ADMIN_JAR" "$RULES_PAGE&dpv_msg=added" > "$TMP/rules.html"
html="$(cat "$TMP/rules.html")"
contains "$html" 'Rule added.' 'rules screen shows the added notice'
contains "$html" "name=\"dpv_rule_id\" value=\"$CAT_RULE\"" 'rules screen lists the category rule with its delete form'
contains "$html" 'DPV Scn Wholesale' 'rules screen names the mixed-case role on its rule'
for q in '&dpv_msg=deleted' '&dpv_error=term' '&dpv_error=mode' '&dpv_error=roles' '&dpv_error=save' '&dpv_error=delete' '&dpv_msg=%3Cscript%3E' '&dpv_error[]=x'; do
	code="$(curl -s -g -o "$TMP/p" -w '%{http_code}' -b "$ADMIN_JAR" "$BASE/wp-admin/$RULES_PAGE$q")"
	if [ "$code" = 200 ] && grep -q 'Product Visibility Rules' "$TMP/p" && ! grep -q 'Fatal error\|<b>Warning</b>\|<b>Notice</b>' "$TMP/p"; then
		pass "rules screen renders with $q"
	else
		fail "rules screen renders with $q (HTTP $code)"
	fi
done
code="$(curl -s -o "$TMP/p" -w '%{http_code}' -b "$SUB_JAR" "$BASE/wp-admin/$RULES_PAGE")"
lacks "$(cat "$TMP/p")" 'name="dpv_rule_id"' "rules screen is not shown to a subscriber (HTTP $code)"

# --- what each visitor sees ---------------------------------------------------------

# yes when the visitor may see the product, no otherwise.
#   hidden   blacklist: role customer, customer dpv-scn-vip      (product edit screen)
#   viponly  whitelist: the mixed-case role, customer dpv-scn-sub (product edit screen)
#   catprod  category rule: hidden from customer
#   tagprod  tag rule: only the mixed-case role
expected() {
	case "$1:$2" in
		guest:hidden|guest:catprod|guest:open) echo yes ;;
		cust:open) echo yes ;;
		sub:hidden|sub:viponly|sub:catprod|sub:open) echo yes ;;
		vip:viponly|vip:catprod|vip:tagprod|vip:open) echo yes ;;
		admin:*) echo yes ;;
		*) echo no ;;
	esac
}

product_id() {
	case "$1" in
		hidden) echo "$P_HIDDEN" ;;
		viponly) echo "$P_VIP" ;;
		catprod) echo "$P_CAT" ;;
		tagprod) echo "$P_TAG" ;;
		open) echo "$P_OPEN" ;;
	esac
}

# GET a front-end or REST URL as a visitor; prints the status, body in $TMP/v.
visit() {
	local jar="$1" nonce="$2" url="$3"
	if [ -z "$jar" ]; then
		curl -s -g -o "$TMP/v" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$url"
	elif [ -z "$nonce" ]; then
		curl -s -g -o "$TMP/v" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -b "$jar" "$url"
	else
		curl -s -g -o "$TMP/v" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -H "X-WP-Nonce: $nonce" -b "$jar" "$url"
	fi
}

# Assert every product is listed, or not, in the body just fetched.
listing() {
	local who="$1" surface="$2" key want
	for key in hidden viponly catprod tagprod open; do
		want="$(expected "$who" "$key")"
		if grep -qF -- "dpv-scn-$key" "$TMP/v"; then got=yes; else got=no; fi
		check "$got" "$want" "$who, $surface: dpv-scn-$key listed=$want"
	done
}

for who in guest cust sub vip admin; do
	case "$who" in
		guest) jar='' ;;
		cust) jar="$CUST_JAR" ;;
		sub) jar="$SUB_JAR" ;;
		vip) jar="$VIP_JAR" ;;
		admin) jar="$ADMIN_JAR" ;;
	esac
	rest=''
	[ -n "$jar" ] && rest="$(mint_nonce "$jar" wp_rest)"
	ensure_mu

	code="$(visit "$jar" '' "$BASE/?post_type=product&orderby=date")"
	check "$code" 200 "$who, shop page: HTTP 200"
	listing "$who" 'shop page'

	visit "$jar" '' "$BASE/?s=dpv-scn-&post_type=product" >/dev/null
	listing "$who" 'product search'

	visit "$jar" '' "$BASE/?s=dpv-scn-" >/dev/null
	listing "$who" 'site search'

	code="$(visit "$jar" "$rest" "$BASE/?rest_route=/wc/store/v1/products&search=dpv-scn-&per_page=100")"
	check "$code" 200 "$who, Store API product list: HTTP 200"
	listing "$who" 'Store API product list'

	visit "$jar" "$rest" "$BASE/?rest_route=/wp/v2/product&search=dpv-scn-&per_page=100" >/dev/null
	listing "$who" 'REST wp/v2 product list'

	for key in hidden viponly catprod tagprod open; do
		id="$(product_id "$key")"
		want="$(expected "$who" "$key")"

		code="$(visit "$jar" "$rest" "$BASE/?rest_route=/wc/store/v1/products/$id")"
		if [ "$want" = yes ]; then check "$code" 200 "$who, Store API single dpv-scn-$key: served"; else check "$code" 404 "$who, Store API single dpv-scn-$key: 404"; fi
		[ "$want" = no ] && lacks "$(cat "$TMP/v")" "dpv-scn-$key" "$who, Store API single dpv-scn-$key: body does not name it"

		code="$(visit "$jar" "$rest" "$BASE/?rest_route=/wp/v2/product/$id")"
		if [ "$want" = yes ]; then check "$code" 200 "$who, REST wp/v2 single dpv-scn-$key: served"; else check "$code" 404 "$who, REST wp/v2 single dpv-scn-$key: 404"; fi

		url="$(json_get "$IDS" "url_$key")"
		if [ -z "$jar" ]; then
			code="$(curl -s -o "$TMP/v" -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$url")"
		else
			code="$(curl -s -o "$TMP/v" -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -b "$jar" "$url")"
		fi
		if [ "$want" = yes ]; then
			check "$code" 200 "$who, direct URL dpv-scn-$key: served"
			contains "$(cat "$TMP/v")" "dpv-scn-$key" "$who, direct URL dpv-scn-$key: page names the product"
		else
			check "$code" 302 "$who, direct URL dpv-scn-$key: redirected"
			contains "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" "$SHOP_URL" "$who, direct URL dpv-scn-$key: sent to the shop"
		fi
	done
done

# --- what a guest can learn about a product hidden from them -------------------------

# dpv-scn-viponly is hidden from guests; dpv-scn-open is not.
ensure_mu
GUEST_JAR="$TMP/guest.jar"
rm -f "$GUEST_JAR"
curl -s -o /dev/null -D "$TMP/cart-h" -c "$GUEST_JAR" -b "$GUEST_JAR" "$BASE/?rest_route=/wc/store/v1/cart"
STORE_NONCE="$(grep -i '^nonce:' "$TMP/cart-h" | tr -d '\r' | awk '{print $2}')"
[ -n "$STORE_NONCE" ] && pass 'guest Store API nonce read from the cart response' || fail 'guest Store API nonce read from the cart response'

store_post() {
	local route="$1" body="$2"
	curl -s -o "$TMP/v" -w '%{http_code}' -c "$GUEST_JAR" -b "$GUEST_JAR" -H "Nonce: $STORE_NONCE" \
		-H 'Content-Type: application/json' --data-binary "$body" "$BASE/?rest_route=$route"
}

for qty in 0 999999; do
	code="$(store_post /wc/store/v1/cart/add-item "{\"id\":$P_VIP,\"quantity\":$qty}")"
	body="$(cat "$TMP/v")"
	check "$code" 404 "guest Store API add-item of the hidden product (quantity $qty): refused"
	contains "$body" 'woocommerce_rest_product_invalid_id' "guest Store API add-item (quantity $qty): the plugin's generic error"
	lacks "$body" 'dpv-scn-viponly' "guest Store API add-item (quantity $qty): the product is not named"
done
code="$(store_post /wc/store/v1/cart/items "{\"id\":$P_VIP,\"quantity\":0}")"
check "$code" 404 'guest Store API cart/items POST of the hidden product: refused'
lacks "$(cat "$TMP/v")" 'dpv-scn-viponly' 'guest Store API cart/items POST: the product is not named'
code="$(store_post /wc/store/v1/batch "{\"requests\":[{\"path\":\"/wc/store/v1/cart/add-item\",\"method\":\"POST\",\"body\":{\"id\":$P_VIP,\"quantity\":0}}]}")"
body="$(cat "$TMP/v")"
contains "$body" 'woocommerce_rest_product_invalid_id' "guest Store API batch add-item of the hidden product: refused (HTTP $code)"
lacks "$body" 'dpv-scn-viponly' 'guest Store API batch add-item: the product is not named'
code="$(store_post /wc/store/v1/cart/add-item "{\"id\":$P_OPEN,\"quantity\":1}")"
check "$code" 201 'guest Store API add-item of a visible product: added'
contains "$(cat "$TMP/v")" 'dpv-scn-open' 'guest Store API add-item of a visible product: the cart lists it'

code="$(curl -s -o "$TMP/v" -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/?p=$P_VIP")"
lacks "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-viponly' "guest ?p=<hidden id>: not redirected to its address (HTTP $code)"
lacks "$(cat "$TMP/v")" 'dpv-scn-viponly' 'guest ?p=<hidden id>: the page does not name it'
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/?p=$P_OPEN")"
contains "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-open' "guest ?p=<visible id>: still redirected to its address (HTTP $code)"
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/product/dpv-scn-vipo/")"
lacks "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-viponly' "guest mistyped product URL: not guessed to the hidden product (HTTP $code)"
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/product/dpv-scn-ope/")"
contains "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-open' "guest mistyped product URL: still guessed to a visible product (HTTP $code)"
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -b "$VIP_JAR" "$BASE/?p=$P_VIP")"
contains "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-viponly' "allowed user ?p=<id>: redirected to the product as before (HTTP $code)"

IMG_ID="$(wp_php <<PHP
echo wp_insert_attachment( array( 'post_title' => 'dpv-scn-vipimage', 'post_name' => 'dpv-scn-vipimage', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, $P_VIP );
PHP
)"
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/?attachment_id=$IMG_ID")"
lacks "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-viponly' "guest ?attachment_id=<image of the hidden product>: not redirected under its address (HTTP $code)"
wp_cli post delete "$IMG_ID" --force >/dev/null

code="$(curl -s -o "$TMP/v" -w '%{http_code}' -c "$GUEST_JAR" -b "$GUEST_JAR" -H 'X-Dpv-Scn: 1' \
	--data-urlencode "product_id=$P_VIP" --data-urlencode 'quantity=1' "$BASE/?wc-ajax=add_to_cart")"
body="$(cat "$TMP/v")"
contains "$body" '"error":true' "guest wc-ajax add_to_cart of the hidden product: refused (HTTP $code)"
lacks "$body" 'dpv-scn-viponly' 'guest wc-ajax add_to_cart: the reply does not carry its address'
contains "$(php -r 'echo json_decode( $argv[1], true )["product_url"] ?? "";' "$body")" "$SHOP_URL" 'guest wc-ajax add_to_cart: sent to the shop instead'

comment_count() {
	wp_php <<PHP
global \$wpdb;
echo (int) \$wpdb->get_var( \$wpdb->prepare( "SELECT COUNT(*) FROM {\$wpdb->comments} WHERE comment_post_ID = %d", $1 ) );
PHP
}
code="$(curl -s -o "$TMP/v" -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' \
	--data-urlencode "comment_post_ID=$P_VIP" --data-urlencode 'comment=dpv-scn guest review' \
	--data-urlencode 'author=dpv-scn guest' --data-urlencode 'email=dpv-scn-guest@example.test' --data-urlencode 'rating=5' \
	"$BASE/wp-comments-post.php")"
check "$code" 403 'guest review posted to the hidden product: refused'
lacks "$(grep -i '^location:' "$TMP/vh" | tr -d '\r')" 'dpv-scn-viponly' 'guest review on the hidden product: no redirect to its address'
check "$(comment_count "$P_VIP")" 0 'guest review on the hidden product: nothing stored'
# Core's flood guard keys a guest on the IP, which other scenario scripts
# share; a signed-in customer is keyed on the user instead.
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -b "$CUST_JAR" \
	--data-urlencode "comment_post_ID=$P_OPEN" --data-urlencode 'comment=dpv-scn customer review of an open product' \
	--data-urlencode 'rating=5' "$BASE/wp-comments-post.php")"
check "$code" 302 'customer review posted to a visible product: accepted'

# REST comment creation does not pass through the review form's hook.
CUST_REST="$(mint_nonce "$CUST_JAR" wp_rest)"
VIP_REST="$(mint_nonce "$VIP_JAR" wp_rest)"
BEFORE="$(comment_count "$P_VIP")"
code="$(curl -s -o "$TMP/v" -w '%{http_code}' -b "$CUST_JAR" -H "X-WP-Nonce: $CUST_REST" \
	--data-urlencode "post=$P_VIP" --data-urlencode 'content=dpv-scn rest review by a customer' "$BASE/?rest_route=/wp/v2/comments")"
check "$code" 404 'customer REST comment on the product hidden from them: refused'
contains "$(cat "$TMP/v")" 'woocommerce_rest_product_invalid_id' "customer REST comment on the hidden product: the plugin's generic error"
lacks "$(cat "$TMP/v")" 'dpv-scn-viponly' 'customer REST comment on the hidden product: no link to it'
check "$(comment_count "$P_VIP")" "$BEFORE" 'customer REST comment on the hidden product: nothing stored'
code="$(curl -s -o "$TMP/v" -w '%{http_code}' -b "$VIP_JAR" -H "X-WP-Nonce: $VIP_REST" \
	--data-urlencode "post=$P_VIP" --data-urlencode 'content=dpv-scn rest review by an allowed user' "$BASE/?rest_route=/wp/v2/comments")"
check "$code" 201 'allowed user REST comment on the same product: created'

# WooCommerce checkout links add products through the Store API cart controller.
STOCK_ID="$(wp_php <<'PHP'
$product = new WC_Product_Simple();
$product->set_name( 'dpv-scn-stockhidden' );
$product->set_slug( 'dpv-scn-stockhidden' );
$product->set_status( 'publish' );
$product->set_regular_price( '9' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 2 );
$id = $product->save();
update_post_meta( $id, '_dpv_restriction_mode', 'whitelist' );
echo $id;
PHP
)"
LINK_JAR="$TMP/link.jar"
for products in "$STOCK_ID:999" "$P_VIP:1" "$P_OPEN:1,$STOCK_ID:999"; do
	rm -f "$LINK_JAR"
	code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -c "$LINK_JAR" -b "$LINK_JAR" "$BASE/?checkout-link=true&products=$products")"
	location="$(grep -i '^location:' "$TMP/vh" | tr -d '\r')"
	decoded="$(php -r 'echo rawurldecode( urldecode( $argv[1] ) );' "$location")"
	contains "$decoded" 'wc_error=' "guest checkout link with a hidden product ($products): refused (HTTP $code)"
	lacks "$decoded" 'dpv-scn-' "guest checkout link with a hidden product ($products): no product named in the redirect"
	curl -s -o "$TMP/v" -c "$LINK_JAR" -b "$LINK_JAR" "$BASE/?rest_route=/wc/store/v1/cart"
	contains "$(cat "$TMP/v")" '"items_count":0' "guest checkout link with a hidden product ($products): nothing added"
done
rm -f "$LINK_JAR"
code="$(curl -s -o /dev/null -D "$TMP/vh" -w '%{http_code}' -H 'X-Dpv-Scn: 1' -c "$LINK_JAR" -b "$LINK_JAR" "$BASE/?checkout-link=true&products=$P_OPEN:2")"
location="$(grep -i '^location:' "$TMP/vh" | tr -d '\r')"
lacks "$location" 'wc_error' "guest checkout link with a visible product: not refused (HTTP $code)"
curl -s -o "$TMP/v" -c "$LINK_JAR" -b "$LINK_JAR" "$BASE/?rest_route=/wc/store/v1/cart"
contains "$(cat "$TMP/v")" '"name":"dpv-scn-open"' 'guest checkout link with a visible product: the cart holds it'

wp_php <<PHP >/dev/null
foreach ( array( $P_VIP => 'dpv-scn-review-secret', $P_OPEN => 'dpv-scn-review-open' ) as \$post_id => \$text ) {
	wp_insert_comment( array( 'comment_post_ID' => \$post_id, 'comment_content' => \$text, 'comment_author' => 'dpv-scn reviewer', 'comment_author_email' => 'dpv-scn-reviewer@example.test', 'comment_approved' => 1, 'comment_type' => 'review' ) );
}
PHP
curl -s -o "$TMP/v" -H 'X-Dpv-Scn: 1' "$BASE/?feed=comments-rss2"
feed="$(cat "$TMP/v")"
contains "$feed" 'dpv-scn-review-open' 'guest comments feed lists the review of a visible product'
lacks "$feed" 'dpv-scn-review-secret' 'guest comments feed leaves out the review of the hidden product'
lacks "$feed" 'dpv-scn-viponly' 'guest comments feed does not name the hidden product'
curl -s -o "$TMP/v" -H 'X-Dpv-Scn: 1' -b "$VIP_JAR" "$BASE/?feed=comments-rss2"
contains "$(cat "$TMP/v")" 'dpv-scn-review-secret' 'allowed user comments feed still lists that review'

LOG_MID="$(debug_size)"
code="$(curl -s -g -o "$TMP/v" -w '%{http_code}' -H 'X-Dpv-Scn: 1' "$BASE/?add-to-cart=$P_GROUPED&quantity[abc]=1")"
if [ "$code" != 500 ]; then pass "grouped add-to-cart with a non-numeric item: no fatal (HTTP $code)"; else fail 'grouped add-to-cart with a non-numeric item: HTTP 500'; fi
lacks "$(debug_since "$LOG_MID" | grep -F "$SLUG_RE" || true)" 'TypeError' 'grouped add-to-cart with a non-numeric item: no TypeError logged'
GROUP_JAR="$TMP/group.jar"
rm -f "$GROUP_JAR"
code="$(curl -s -g -o /dev/null -w '%{http_code}' -H 'X-Dpv-Scn: 1' -c "$GROUP_JAR" -b "$GROUP_JAR" "$BASE/?add-to-cart=$P_GROUPED&quantity[$P_OPEN]=1")"
curl -s -o "$TMP/v" -c "$GROUP_JAR" -b "$GROUP_JAR" "$BASE/?rest_route=/wc/store/v1/cart"
contains "$(cat "$TMP/v")" '"name":"dpv-scn-open"' "grouped add-to-cart of a visible child: the cart holds it (HTTP $code)"

# --- delete a rule -------------------------------------------------------------------

RULES_SNAP="$(rules_json)"
del_body() { php "$HERE/form-body.php" "action:dragonproductvisibility_delete_bulk_rule:dpv_rule_id=$CAT_RULE" "$@" < "$TMP/rules.html"; }

del_body '-_wpnonce' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '403' 'delete rule without a nonce: 403'
check "$(rules_json)" "$RULES_SNAP" 'delete rule without a nonce: rules unchanged'

del_body '_wpnonce=0123456789' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" '403' 'delete rule with a bad nonce: 403'
check "$(rules_json)" "$RULES_SNAP" 'delete rule with a bad nonce: rules unchanged'

for who in sub cust; do
	if [ "$who" = sub ]; then jar="$SUB_JAR"; label='subscriber'; else jar="$CUST_JAR"; label='shop customer'; fi
	n_own="$(mint_nonce "$jar" dragonproductvisibility_delete_bulk_rule)"
	out="$(admin_send "$jar" admin-post.php \
		--data-urlencode 'action=dragonproductvisibility_delete_bulk_rule' --data-urlencode "_wpnonce=$n_own" \
		--data-urlencode "dpv_rule_id=$CAT_RULE")"
	contains "$(cat "$TMP/body")" 'Permission denied.' "delete rule as a $label with a valid nonce: refused by the capability check"
	check "$(rules_json)" "$RULES_SNAP" "delete rule as a $label: rules unchanged"
done

del_body 'dpv_rule_id=rdoesnotexist' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=delete' 'delete an unknown rule: reported as not deleted'
del_body '-dpv_rule_id' 'dpv_rule_id[]=x' > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_error=delete' 'delete with the rule id sent as an array: reported as not deleted'
check "$(rules_json)" "$RULES_SNAP" 'refused deletes: rules unchanged'

del_body > "$TMP/form"
out="$(admin_send "$ADMIN_JAR" admin-post.php -H 'Content-Type: application/x-www-form-urlencoded' --data-binary "@$TMP/form")"
contains "$out" 'dpv_msg=deleted' 'delete rule reports the rule deleted'
check "$(rule_id_for_term "$CAT_ID")" '' 'the category rule is gone'
[ -n "$TAG_RULE" ] && check "$(rule_id_for_term "$TAG_ID")" "$TAG_RULE" 'the tag rule is still there'

visit "$CUST_JAR" "$(mint_nonce "$CUST_JAR" wp_rest)" "$BASE/?rest_route=/wc/store/v1/products&search=dpv-scn-&per_page=100" >/dev/null
contains "$(cat "$TMP/v")" 'dpv-scn-catprod' 'with the category rule deleted, the customer sees the category product again'

# --- debug.log -------------------------------------------------------------------------

mine="$(debug_since "$LOG_START" | grep -F "$SLUG_RE" || true)"
if [ -z "$mine" ]; then
	pass 'no new debug.log lines from dragon-product-visibility'
else
	fail 'new debug.log lines from dragon-product-visibility'
	printf '%s\n' "$mine" | head -20
fi

TOTAL=$((PASS + FAIL))
printf '%s/%s passed\n' "$PASS" "$TOTAL"
[ "$FAIL" -eq 0 ]
