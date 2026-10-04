<?php
/**
 * Prints the urlencoded body a browser would submit for one form of an HTML
 * page read from stdin, with the given fields replaced.
 *
 * Usage: php form-body.php <selector> [name=value ...] [-name ...] < page.html
 *
 * <selector> is "#id" for the form with that id, or "action:<value>[:<name>=<value>]"
 * for the form holding a hidden "action" input with that value (and, when
 * given, another input with that name and value).
 * "name=value" drops every field of that name the form carried and adds this
 * one; repeat it to send several values. "-name" drops the field.
 *
 * Exit: 0 on success, 3 when the form is not on the page.
 *
 * @package DragonProductVisibility
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

$dpv_selector  = $argv[1] ?? '';
$dpv_overrides = array_slice( $argv, 2 );
$dpv_html      = (string) stream_get_contents( STDIN );

$dpv_doc = new DOMDocument();
@$dpv_doc->loadHTML( '<?xml encoding="UTF-8">' . $dpv_html );
$dpv_xpath = new DOMXPath( $dpv_doc );

/**
 * The form the selector names, or null.
 *
 * @param DOMXPath $xpath    Page.
 * @param string   $selector Selector.
 * @return DOMElement|null
 */
function dpv_form( DOMXPath $xpath, string $selector ): ?DOMElement {
	if ( '#' === substr( $selector, 0, 1 ) ) {
		$found = $xpath->query( '//form[@id="' . substr( $selector, 1 ) . '"]' );
		return $found->length ? $found->item( 0 ) : null;
	}

	$parts  = explode( ':', $selector, 3 );
	$action = $parts[1] ?? '';
	$extra  = isset( $parts[2] ) ? explode( '=', $parts[2], 2 ) : null;

	foreach ( $xpath->query( '//form' ) as $form ) {
		if ( ! $xpath->query( './/input[@name="action"][@value="' . $action . '"]', $form )->length ) {
			continue;
		}
		if ( $extra && ! $xpath->query( './/input[@name="' . $extra[0] . '"][@value="' . ( $extra[1] ?? '' ) . '"]', $form )->length ) {
			continue;
		}
		return $form;
	}

	return null;
}

$dpv_node = dpv_form( $dpv_xpath, $dpv_selector );
if ( ! $dpv_node ) {
	fwrite( STDERR, "form not found: $dpv_selector\n" );
	exit( 3 );
}

$dpv_pairs = array();
foreach ( $dpv_xpath->query( './/input | .//select | .//textarea', $dpv_node ) as $dpv_field ) {
	$dpv_name = $dpv_field->getAttribute( 'name' );
	if ( '' === $dpv_name || $dpv_field->hasAttribute( 'disabled' ) ) {
		continue;
	}

	if ( 'select' === $dpv_field->nodeName ) {
		$dpv_options  = $dpv_xpath->query( './/option', $dpv_field );
		$dpv_selected = $dpv_xpath->query( './/option[@selected]', $dpv_field );
		if ( $dpv_selected->length ) {
			foreach ( $dpv_selected as $dpv_option ) {
				$dpv_pairs[] = array( $dpv_name, $dpv_option->hasAttribute( 'value' ) ? $dpv_option->getAttribute( 'value' ) : trim( $dpv_option->textContent ) );
			}
		} elseif ( ! $dpv_field->hasAttribute( 'multiple' ) && $dpv_options->length ) {
			$dpv_option  = $dpv_options->item( 0 );
			$dpv_pairs[] = array( $dpv_name, $dpv_option->hasAttribute( 'value' ) ? $dpv_option->getAttribute( 'value' ) : trim( $dpv_option->textContent ) );
		}
		continue;
	}

	if ( 'textarea' === $dpv_field->nodeName ) {
		$dpv_pairs[] = array( $dpv_name, $dpv_field->textContent );
		continue;
	}

	$dpv_type = strtolower( $dpv_field->getAttribute( 'type' ) );
	if ( in_array( $dpv_type, array( 'submit', 'button', 'image', 'reset', 'file' ), true ) ) {
		continue;
	}
	if ( in_array( $dpv_type, array( 'checkbox', 'radio' ), true ) && ! $dpv_field->hasAttribute( 'checked' ) ) {
		continue;
	}
	$dpv_pairs[] = array( $dpv_name, $dpv_field->hasAttribute( 'value' ) ? $dpv_field->getAttribute( 'value' ) : ( in_array( $dpv_type, array( 'checkbox', 'radio' ), true ) ? 'on' : '' ) );
}

$dpv_dropped = array();
foreach ( $dpv_overrides as $dpv_override ) {
	if ( '-' === substr( $dpv_override, 0, 1 ) ) {
		$dpv_name  = substr( $dpv_override, 1 );
		$dpv_value = null;
	} else {
		list( $dpv_name, $dpv_value ) = array_pad( explode( '=', $dpv_override, 2 ), 2, '' );
	}

	if ( ! isset( $dpv_dropped[ $dpv_name ] ) ) {
		$dpv_dropped[ $dpv_name ] = true;
		$dpv_pairs                = array_values(
			array_filter(
				$dpv_pairs,
				static function ( $pair ) use ( $dpv_name ) {
					return $pair[0] !== $dpv_name;
				}
			)
		);
	}

	if ( null !== $dpv_value ) {
		$dpv_pairs[] = array( $dpv_name, $dpv_value );
	}
}

$dpv_out = array();
foreach ( $dpv_pairs as $dpv_pair ) {
	$dpv_out[] = rawurlencode( $dpv_pair[0] ) . '=' . rawurlencode( $dpv_pair[1] );
}
echo implode( '&', $dpv_out );
