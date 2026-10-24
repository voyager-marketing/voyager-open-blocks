/**
 * voyager-open/icon: editor UI.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import iconSet from '../../assets/icons.json';

const ANIMATIONS = [
	{ label: __( 'None', 'voyager-open-blocks' ), value: '' },
	{ label: __( 'Bounce', 'voyager-open-blocks' ), value: 'bounce' },
	{ label: __( 'Pulse', 'voyager-open-blocks' ), value: 'pulse' },
	{ label: __( 'Spin', 'voyager-open-blocks' ), value: 'spin' },
	{ label: __( 'Float', 'voyager-open-blocks' ), value: 'float' },
	{ label: __( 'Wiggle', 'voyager-open-blocks' ), value: 'wiggle' },
	{ label: __( 'Fade in', 'voyager-open-blocks' ), value: 'fade-in' },
	{ label: __( 'Zoom in', 'voyager-open-blocks' ), value: 'zoom-in' },
];

const TRIGGERS = [
	{ label: __( 'On hover', 'voyager-open-blocks' ), value: 'hover' },
	{ label: __( 'On scroll', 'voyager-open-blocks' ), value: 'scroll' },
	{ label: __( 'Always', 'voyager-open-blocks' ), value: 'always' },
];

function IconSvg( { name, size } ) {
	const path = iconSet.icons[ name ];

	if ( ! path ) {
		return null;
	}

	return (
		<svg
			className="wp-block-voyager-open-icon__svg"
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 24 24"
			width={ size }
			height={ size }
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			aria-hidden="true"
			focusable="false"
		>
			<path strokeLinecap="round" strokeLinejoin="round" d={ path } />
		</svg>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		icon,
		size,
		label,
		linkUrl,
		linkTarget,
		animation,
		animationTrigger,
	} = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Icon', 'voyager-open-blocks' ) }>
					{ Object.entries( iconSet.categories ).map(
						( [ key, category ] ) => (
							<fieldset
								key={ key }
								className="voyager-open-icon-picker"
							>
								<legend>{ category.label }</legend>
								{ category.icons.map( ( name ) => (
									<Button
										key={ name }
										label={ name }
										showTooltip
										isPressed={ name === icon }
										onClick={ () =>
											setAttributes( { icon: name } )
										}
									>
										<IconSvg name={ name } size={ 20 } />
									</Button>
								) ) }
							</fieldset>
						)
					) }
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Size (px)', 'voyager-open-blocks' ) }
						value={ size }
						min={ 16 }
						max={ 200 }
						onChange={ ( value ) =>
							setAttributes( { size: value ?? 48 } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Accessible label',
							'voyager-open-blocks'
						) }
						help={ __(
							'Leave empty when the icon is decorative. Set it when the icon carries meaning or is a link.',
							'voyager-open-blocks'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Link', 'voyager-open-blocks' ) }
					initialOpen={ false }
				>
					<TextControl
						__next40pxDefaultSize
						type="url"
						label={ __( 'URL', 'voyager-open-blocks' ) }
						value={ linkUrl }
						onChange={ ( value ) =>
							setAttributes( { linkUrl: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Open in a new tab',
							'voyager-open-blocks'
						) }
						checked={ linkTarget }
						onChange={ ( value ) =>
							setAttributes( { linkTarget: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Animation', 'voyager-open-blocks' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Animation', 'voyager-open-blocks' ) }
						value={ animation }
						options={ ANIMATIONS }
						onChange={ ( value ) =>
							setAttributes( { animation: value } )
						}
					/>
					{ animation && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Trigger', 'voyager-open-blocks' ) }
							value={ animationTrigger }
							options={ TRIGGERS }
							onChange={ ( value ) =>
								setAttributes( { animationTrigger: value } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<IconSvg name={ icon } size={ size } />
			</div>
		</>
	);
}
