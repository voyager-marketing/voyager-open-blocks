<?php
/**
 * Scalar coercion for values WordPress hands back as mixed.
 *
 * get_option(), block attributes and ability input are untyped. These keep the
 * casts the code always made, and turn anything that is not a scalar into the
 * default instead of "Array" and a PHP warning.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A scalar as a string, the default for anything else.
 *
 * @param mixed  $value   Value.
 * @param string $default Returned when the value is not a scalar.
 */
function to_string( mixed $value, string $default = '' ): string {
	return is_scalar( $value ) ? (string) $value : $default;
}

/**
 * A scalar as an int, the default for anything else.
 *
 * @param mixed $value   Value.
 * @param int   $default Returned when the value is not a scalar.
 */
function to_int( mixed $value, int $default = 0 ): int {
	return is_scalar( $value ) ? (int) $value : $default;
}
