<?php
// This file is generated. Do not modify it manually.
return array(
	'facts' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'voyager-open/facts',
		'version' => '0.1.0',
		'title' => 'Facts',
		'category' => 'text',
		'icon' => 'editor-ul',
		'description' => 'A list of short facts, one item per line. Bind it to a Site Data list, such as your services, service area or credentials, to show that list.',
		'keywords' => array(
			'list',
			'facts',
			'services',
			'service area',
			'credentials'
		),
		'textdomain' => 'voyager-open-blocks',
		'attributes' => array(
			'items' => array(
				'type' => 'string',
				'default' => '',
				'role' => 'content'
			)
		),
		'supports' => array(
			'html' => false,
			'anchor' => true,
			'color' => array(
				'text' => true,
				'background' => true
			),
			'spacing' => array(
				'margin' => true,
				'padding' => true
			),
			'typography' => array(
				'fontSize' => true,
				'lineHeight' => true
			)
		),
		'example' => array(
			'attributes' => array(
				'items' => 'Licensed and insured
Family owned
Same-day service'
			)
		),
		'editorScript' => 'file:./index.js',
		'render' => 'file:./render.php'
	),
	'icon' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'voyager-open/icon',
		'version' => '0.1.0',
		'title' => 'Icon',
		'category' => 'design',
		'icon' => 'star-filled',
		'description' => 'An outline SVG icon from a fixed set, with an optional link and a CSS-only animation.',
		'keywords' => array(
			'icon',
			'svg',
			'symbol',
			'heroicons'
		),
		'textdomain' => 'voyager-open-blocks',
		'attributes' => array(
			'icon' => array(
				'type' => 'string',
				'enum' => array(
					'arrow-down',
					'arrow-left',
					'arrow-right',
					'arrow-up',
					'bolt',
					'building-office',
					'chart-bar',
					'chat-bubble',
					'check-circle',
					'chevron-down',
					'chevron-right',
					'clock',
					'cog',
					'cursor-arrow-click',
					'document',
					'envelope',
					'exclamation-triangle',
					'globe',
					'heart',
					'home',
					'information-circle',
					'light-bulb',
					'link',
					'map-pin',
					'phone',
					'photo',
					'play-circle',
					'rocket-launch',
					'shield-check',
					'sparkles',
					'star',
					'users',
					'video-camera',
					'wrench',
					'x-circle'
				),
				'default' => 'star'
			),
			'size' => array(
				'type' => 'number',
				'default' => 48
			),
			'label' => array(
				'type' => 'string',
				'default' => ''
			),
			'linkUrl' => array(
				'type' => 'string',
				'default' => ''
			),
			'linkTarget' => array(
				'type' => 'boolean',
				'default' => false
			),
			'animation' => array(
				'type' => 'string',
				'enum' => array(
					'',
					'bounce',
					'pulse',
					'spin',
					'float',
					'wiggle',
					'fade-in',
					'zoom-in'
				),
				'default' => ''
			),
			'animationTrigger' => array(
				'type' => 'string',
				'enum' => array(
					'hover',
					'scroll',
					'always'
				),
				'default' => 'hover'
			)
		),
		'supports' => array(
			'html' => false,
			'anchor' => true,
			'align' => array(
				'left',
				'center',
				'right'
			),
			'color' => array(
				'text' => true,
				'background' => true
			),
			'spacing' => array(
				'margin' => true,
				'padding' => true
			)
		),
		'example' => array(
			'attributes' => array(
				'icon' => 'rocket-launch',
				'size' => 64
			)
		),
		'editorScript' => 'file:./index.js',
		'editorStyle' => 'file:./index.css',
		'style' => 'file:./style-index.css',
		'render' => 'file:./render.php'
	)
);
