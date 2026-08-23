<?php
/**
 * Bulk visibility rules admin view.
 *
 * @package DragonProductVisibility
 *
 * @var array $rules Existing rules (from Bulk_Rules::all()).
 */

namespace DragonProductVisibility;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local view variables.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dragonproductvisibility_roles = get_editable_roles();
$dragonproductvisibility_names = wp_list_pluck( $dragonproductvisibility_roles, 'name' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag for a notice; no state change.
$dragonproductvisibility_msg = isset( $_GET['dpv_msg'] ) ? sanitize_key( wp_unslash( $_GET['dpv_msg'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag for a notice; no state change.
$dragonproductvisibility_err = isset( $_GET['dpv_error'] ) ? sanitize_key( wp_unslash( $_GET['dpv_error'] ) ) : '';
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Product Visibility Rules', 'dragon-product-visibility' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Hide or restrict whole product categories or tags by user role. A rule on a category also covers its sub-categories. A per-product rule set on an individual product always takes precedence over these.', 'dragon-product-visibility' ); ?>
	</p>

	<?php if ( 'added' === $dragonproductvisibility_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule added.', 'dragon-product-visibility' ); ?></p></div>
	<?php elseif ( 'deleted' === $dragonproductvisibility_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule deleted.', 'dragon-product-visibility' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'term' === $dragonproductvisibility_err ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please choose a valid category or tag.', 'dragon-product-visibility' ); ?></p></div>
	<?php elseif ( 'mode' === $dragonproductvisibility_err ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please choose a valid rule type.', 'dragon-product-visibility' ); ?></p></div>
	<?php elseif ( 'roles' === $dragonproductvisibility_err ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please select at least one role.', 'dragon-product-visibility' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Active rules', 'dragon-product-visibility' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Applies to', 'dragon-product-visibility' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rule', 'dragon-product-visibility' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Roles', 'dragon-product-visibility' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'dragon-product-visibility' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rules ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No rules yet. Add one below.', 'dragon-product-visibility' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rules as $dragonproductvisibility_rule ) : ?>
					<?php
					$dragonproductvisibility_term  = get_term( $dragonproductvisibility_rule['term_id'], $dragonproductvisibility_rule['taxonomy'] );
					$dragonproductvisibility_label = ( $dragonproductvisibility_term && ! is_wp_error( $dragonproductvisibility_term ) )
						? $dragonproductvisibility_term->name
						/* translators: %d: term ID of a category or tag that no longer exists. */
						: sprintf( __( '(deleted term #%d)', 'dragon-product-visibility' ), (int) $dragonproductvisibility_rule['term_id'] );

					$dragonproductvisibility_tax_label = ( 'product_cat' === $dragonproductvisibility_rule['taxonomy'] )
						? __( 'Category', 'dragon-product-visibility' )
						: __( 'Tag', 'dragon-product-visibility' );

					$dragonproductvisibility_rule_label = ( 'whitelist' === $dragonproductvisibility_rule['mode'] )
						? __( 'Only these roles can see', 'dragon-product-visibility' )
						: __( 'Hidden from these roles', 'dragon-product-visibility' );

					$dragonproductvisibility_role_labels = array();
					foreach ( $dragonproductvisibility_rule['roles'] as $dragonproductvisibility_role_key ) {
						$dragonproductvisibility_role_labels[] = $dragonproductvisibility_names[ $dragonproductvisibility_role_key ] ?? $dragonproductvisibility_role_key;
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $dragonproductvisibility_label ); ?></strong>
							<span class="description">(<?php echo esc_html( $dragonproductvisibility_tax_label ); ?>)</span>
						</td>
						<td><?php echo esc_html( $dragonproductvisibility_rule_label ); ?></td>
						<td><?php echo esc_html( implode( ', ', $dragonproductvisibility_role_labels ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'dragonproductvisibility_delete_bulk_rule' ); ?>
								<input type="hidden" name="action" value="dragonproductvisibility_delete_bulk_rule">
								<input type="hidden" name="dpv_rule_id" value="<?php echo esc_attr( $dragonproductvisibility_rule['id'] ); ?>">
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'dragon-product-visibility' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Add a rule', 'dragon-product-visibility' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dragonproductvisibility_add_bulk_rule' ); ?>
		<input type="hidden" name="action" value="dragonproductvisibility_add_bulk_rule">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dpv_term"><?php esc_html_e( 'Category or tag', 'dragon-product-visibility' ); ?></label></th>
				<td>
					<select name="dpv_term" id="dpv_term" required>
						<option value=""><?php esc_html_e( '— Select —', 'dragon-product-visibility' ); ?></option>
						<?php
						foreach ( array(
							'product_cat' => __( 'Categories', 'dragon-product-visibility' ),
							'product_tag' => __( 'Tags', 'dragon-product-visibility' ),
						) as $dragonproductvisibility_tax => $dragonproductvisibility_group ) :
							$dragonproductvisibility_terms = get_terms(
								array(
									'taxonomy'   => $dragonproductvisibility_tax,
									'hide_empty' => false,
									'number'     => 500,
								)
							);
							if ( empty( $dragonproductvisibility_terms ) || is_wp_error( $dragonproductvisibility_terms ) ) {
								continue;
							}
							?>
							<optgroup label="<?php echo esc_attr( $dragonproductvisibility_group ); ?>">
								<?php foreach ( $dragonproductvisibility_terms as $dragonproductvisibility_t ) : ?>
									<option value="<?php echo esc_attr( $dragonproductvisibility_tax . ':' . $dragonproductvisibility_t->term_id ); ?>">
										<?php echo esc_html( $dragonproductvisibility_t->name ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dpv_mode"><?php esc_html_e( 'Rule type', 'dragon-product-visibility' ); ?></label></th>
				<td>
					<select name="dpv_mode" id="dpv_mode">
						<option value="blacklist"><?php esc_html_e( 'Hide from the selected roles', 'dragon-product-visibility' ); ?></option>
						<option value="whitelist"><?php esc_html_e( 'Show only to the selected roles', 'dragon-product-visibility' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( '“Show only to” also hides the products from logged-out visitors.', 'dragon-product-visibility' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles', 'dragon-product-visibility' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( $dragonproductvisibility_names as $dragonproductvisibility_role_key => $dragonproductvisibility_role_name ) : ?>
							<label style="display:inline-block; min-width:180px; margin-bottom:4px;">
								<input type="checkbox" name="dpv_roles[]" value="<?php echo esc_attr( $dragonproductvisibility_role_key ); ?>">
								<?php echo esc_html( $dragonproductvisibility_role_name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Add rule', 'dragon-product-visibility' ) ); ?>
	</form>
</div>
