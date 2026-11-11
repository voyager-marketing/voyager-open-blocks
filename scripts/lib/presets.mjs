/**
 * The presets gate. DESIGN.md says pattern markup takes every colour, font
 * size, font family, radius and shadow from the theme's presets, uses spacing
 * slugs 20 to 60 only, and holds no `core/html`. This reads that from markup.
 *
 * It reads each block's attributes from its parsed block comment. The saved
 * HTML's inline styles are written from those attributes, so they say nothing
 * more. Whether a slug exists on a given theme is not checked: a slug is the
 * theme's own value by construction, and the gate reads markup only.
 *
 * A value takes a preset when it is a preset reference of the right kind,
 * `var:preset|color|accent` (what the editor writes) or the CSS form
 * `var(--wp--preset--color--accent)`, or when the block's slug attribute
 * (`textColor`, `fontSize`, `borderColor` and so on) names it instead.
 */
import { parse } from '@wordpress/block-serialization-default-parser';

/**
 * Records one finding for the block being checked.
 *
 * @callback AddFinding
 * @param {string}  rule      One of RULES.
 * @param {string}  attribute Attribute path, such as `style.color.text`.
 * @param {unknown} value     The value that breaks the rule.
 * @return {void}
 */

/**
 * The rules, in the order DESIGN.md names them. tests/invalid/presets/ plants
 * one file for each, named `<rule>.html`.
 */
export const RULES = [
	'colour',
	'font-size',
	'font-family',
	'radius',
	'shadow',
	'spacing',
	'html',
];

/**
 * Twenty Twenty-Four defines spacing slugs 10 to 60, Twenty Twenty-Five and
 * core's default scale 20 and up. A slug a theme does not define renders as
 * no space at all, so a pattern keeps to the slugs all of them share.
 */
export const SPACING = { min: 20, max: 60 };

const REASONS = {
	colour: 'is not a colour preset',
	'font-size': 'is not a font size preset',
	'font-family': 'is not a font family preset',
	radius: 'is not a border radius preset',
	shadow: 'is not a shadow preset',
	spacing: `is not a spacing slug from ${ SPACING.min } to ${ SPACING.max }`,
	html: 'is raw HTML, not core blocks',
};

const SLUG = '[A-Za-z0-9_-]+';

/**
 * Is a value a reference to a preset of one kind?
 *
 * @param {unknown} value Attribute value.
 * @param {string}  infix Preset kind, as in `var:preset|<infix>|<slug>`.
 * @return {boolean} True for `var:preset|<infix>|<slug>` or `var(--wp--preset--<infix>--<slug>)`.
 */
function isPreset( value, infix ) {
	return (
		typeof value === 'string' &&
		( new RegExp( `^var:preset\\|${ infix }\\|${ SLUG }$` ).test( value ) ||
			new RegExp( `^var\\(--wp--preset--${ infix }--${ SLUG }\\)$` ).test(
				value
			) )
	);
}

/**
 * Is an attribute set at all? An empty string or object is what the editor
 * leaves behind when a control is cleared, and sets nothing.
 *
 * @param {unknown} value Attribute value.
 * @return {boolean} False for undefined, null, '' and {}.
 */
function isSet( value ) {
	if ( value === undefined || value === null || value === '' ) {
		return false;
	}
	return ! (
		typeof value === 'object' &&
		! Array.isArray( value ) &&
		Object.keys( value ).length === 0
	);
}

/**
 * Check the colours under one `color` key: `style.color`,
 * `style.elements.link.color`, `style.border.color`, `style.border.top.color`.
 *
 * @param {unknown}    value Value under the key.
 * @param {string}     at    Attribute path, such as `style.color`.
 * @param {AddFinding} add   Records a finding.
 */
function checkColour( value, at, add ) {
	if ( ! isSet( value ) ) {
		return;
	}
	if ( typeof value === 'object' && ! Array.isArray( value ) ) {
		for ( const [ key, inner ] of Object.entries( value ) ) {
			const here = `${ at }.${ key }`;
			if ( key === 'gradient' ) {
				if ( isSet( inner ) && ! isPreset( inner, 'gradient' ) ) {
					add( 'colour', here, inner );
				}
			} else if ( key === 'duotone' ) {
				// "unset" turns the theme's duotone off and sets no colour.
				if (
					isSet( inner ) &&
					inner !== 'unset' &&
					! isPreset( inner, 'duotone' )
				) {
					add( 'colour', here, inner );
				}
			} else {
				checkColour( inner, here, add );
			}
		}
		return;
	}
	if ( ! isPreset( value, 'color' ) ) {
		add( 'colour', at, value );
	}
}

/**
 * Check a border radius: one value, or one per corner.
 *
 * @param {unknown}    value Value of `radius`.
 * @param {string}     at    Attribute path.
 * @param {AddFinding} add   Records a finding.
 */
function checkRadius( value, at, add ) {
	if ( ! isSet( value ) ) {
		return;
	}
	if ( typeof value === 'object' && ! Array.isArray( value ) ) {
		for ( const [ corner, inner ] of Object.entries( value ) ) {
			checkRadius( inner, `${ at }.${ corner }`, add );
		}
		return;
	}
	if ( ! isPreset( value, 'border-radius' ) ) {
		add( 'radius', at, value );
	}
}

/**
 * Walk a block's `style` attribute, and everything under it: elements,
 * their states, borders and their sides.
 *
 * @param {unknown}    value Value at this point of `style`.
 * @param {string}     at    Attribute path.
 * @param {AddFinding} add   Records a finding.
 */
function checkStyle( value, at, add ) {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return;
	}
	for ( const [ key, inner ] of Object.entries( value ) ) {
		const here = `${ at }.${ key }`;
		if ( key === 'color' ) {
			checkColour( inner, here, add );
		} else if ( key === 'fontSize' ) {
			if ( isSet( inner ) && ! isPreset( inner, 'font-size' ) ) {
				add( 'font-size', here, inner );
			}
		} else if ( key === 'fontFamily' ) {
			if ( isSet( inner ) && ! isPreset( inner, 'font-family' ) ) {
				add( 'font-family', here, inner );
			}
		} else if ( key === 'radius' ) {
			checkRadius( inner, here, add );
		} else if ( key === 'shadow' ) {
			if ( isSet( inner ) && ! isPreset( inner, 'shadow' ) ) {
				add( 'shadow', here, inner );
			}
		} else if ( key === 'textShadow' ) {
			if ( isSet( inner ) && ! isPreset( inner, 'text-shadow' ) ) {
				add( 'shadow', here, inner );
			}
		} else {
			checkStyle( inner, here, add );
		}
	}
}

/**
 * Colours some core blocks keep outside `style`, read from their block.json
 * in `@wordpress/block-library` 12: `customOverlayColor` and `customGradient`
 * (cover, post featured image), `customTextColor`, `rgbTextColor` and the
 * like (navigation), and `customIconColor` (social links). Each holds a raw
 * value; its preset twin (`overlayColor`, `gradient`, `iconColor`) holds a
 * slug.
 */
const RAW_COLOUR_ATTRIBUTE = /^(custom|rgb)[A-Za-z]*(Color|Gradient)$/;

/**
 * Social links also store the colour a preset resolves to, in
 * `iconColorValue` beside the `iconColor` slug, so a theme switch keeps it.
 * Only an `iconColorValue` with no slug beside it is a raw colour.
 */
const RESOLVED_COLOUR_ATTRIBUTE = /^([A-Za-z]+Color)Value$/;

/**
 * Spacing presets in any attribute, in either form.
 */
const SPACING_REFERENCE =
	/var:preset\|spacing\|([^|"\s)]+)|var\(--wp--preset--spacing--([A-Za-z0-9_-]+)\)/g;

/**
 * Find every spacing slug outside the shared range, anywhere in a value.
 *
 * @param {unknown}    value Attribute value.
 * @param {string}     at    Attribute path.
 * @param {AddFinding} add   Records a finding.
 */
function checkSpacing( value, at, add ) {
	if ( typeof value === 'string' ) {
		for ( const match of value.matchAll( SPACING_REFERENCE ) ) {
			const slug = match[ 1 ] ?? match[ 2 ];
			const size = /^\d+$/.test( slug ) ? Number( slug ) : NaN;
			if ( ! ( size >= SPACING.min && size <= SPACING.max ) ) {
				add( 'spacing', at, value );
				return;
			}
		}
		return;
	}
	if ( value && typeof value === 'object' ) {
		for ( const [ key, inner ] of Object.entries( value ) ) {
			checkSpacing(
				inner,
				Array.isArray( value )
					? `${ at }[${ key }]`
					: `${ at }.${ key }`,
				add
			);
		}
	}
}

/**
 * Check one block's own attributes and name.
 *
 * @param {Object}     block Parsed block.
 * @param {AddFinding} add   Records a finding.
 */
function checkBlock( block, add ) {
	const attrs = block.attrs || {};
	if (
		block.blockName === 'core/html' ||
		block.blockName === 'core/freeform'
	) {
		add( 'html', 'block', block.blockName );
	}
	if ( block.blockName === null ) {
		add( 'html', 'markup outside a block', block.innerHTML.trim() );
	}
	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( RAW_COLOUR_ATTRIBUTE.test( key ) && isSet( value ) ) {
			add( 'colour', key, value );
		}
		const resolved = key.match( RESOLVED_COLOUR_ATTRIBUTE );
		if ( resolved && isSet( value ) && ! isSet( attrs[ resolved[ 1 ] ] ) ) {
			add( 'colour', key, value );
		}
	}
	checkStyle( attrs.style, 'style', add );
	for ( const [ key, value ] of Object.entries( attrs ) ) {
		checkSpacing( value, key, add );
	}
}

/**
 * Check a piece of block markup.
 *
 * Paths are written as inc/block-tree.php writes them, "2/0/1": indices from
 * the top level down, where the top level skips the whitespace between
 * blocks, so they match the abilities and what an editor shows.
 *
 * @param {string} markup Block markup.
 * @return {{top: number, blocks: number, findings: Object[]}} The number of
 *         top-level blocks, of blocks in all, and one finding per value that
 *         breaks a rule: rule, path, block, label, attribute and value.
 */
export function checkMarkup( markup ) {
	const findings = [];
	let blocks = 0;
	const walk = ( list, prefix ) => {
		list.forEach( ( block, index ) => {
			const path =
				prefix === '' ? `${ index }` : `${ prefix }/${ index }`;
			const label = block.attrs?.metadata?.name;
			blocks++;
			checkBlock( block, ( rule, attribute, value ) =>
				findings.push( {
					rule,
					path,
					block: block.blockName ?? 'core/freeform',
					label: typeof label === 'string' ? label : '',
					attribute,
					value,
				} )
			);
			walk( block.innerBlocks || [], path );
		} );
	};
	const top = parse( markup ).filter(
		( block ) => block.blockName !== null || block.innerHTML.trim() !== ''
	);
	walk( top, '' );
	return { top: top.length, blocks, findings };
}

/**
 * One line for a finding, naming the file, the block's path and the attribute.
 *
 * @param {string} file    File the markup came from.
 * @param {Object} finding A finding from checkMarkup().
 * @return {string} Such as `patterns/cta-band.html: block 0/1 core/paragraph
 *         "Body": style.color.text "#c00" is not a colour preset`.
 */
export function describeFinding( file, finding ) {
	const label = finding.label ? ` "${ finding.label }"` : '';
	let value = JSON.stringify( finding.value );
	if ( value.length > 60 ) {
		value = `${ value.slice( 0, 57 ) }...`;
	}
	return `${ file }: block ${ finding.path } ${ finding.block }${ label }: ${
		finding.attribute
	} ${ value } ${ REASONS[ finding.rule ] } [${ finding.rule }]`;
}
