/**
 * Editor hints for Voyager Open Blocks.
 *
 * Editor only. Nothing here changes saved markup or the front end. It adds:
 *
 * - A "Connected data" panel for a block bound to voyager/site-data or
 *   voyager/post-meta-text: the field and source in plain words, the current
 *   value, and a warning when the value is empty, because visitors then see
 *   the text saved in the block instead.
 * - A "Placeholder content" panel for the pack's placeholder content: an
 *   image whose URL ends in assets/placeholders/<one of the pack's files>
 *   (the logo marquee's logos, and the image slots' art), on any host and
 *   under any plugin folder, so content copied from another site still
 *   counts, whether it is a core/image's url, a core/media-text's mediaUrl
 *   or a core/cover's background url; and the contact section's form slot
 *   fallback (a group named "Form" in a column named "Form slot", the names
 *   patterns/intents/contact-section.json gives them). Both are found from
 *   data, never from the text on screen.
 * - A "Still to fill in" panel on a selected section that holds any of the
 *   above, so a freshly inserted pattern says what is left to do.
 * - An editor-only class on those blocks in the canvas (assets/editor/hints.css
 *   draws a dashed outline), added through editor.BlockListBlock.
 *
 * The panels go in the inspector's content group: core's image block puts its
 * content controls there, and it is the only group shown for blocks inside a
 * content-only section, which is how an inserted pattern is edited.
 *
 * Data: window.voyagerOpenBlocksHints (inc/bindings-editor.php), and
 * describe() on window.voyagerOpenBlocksBindings (assets/bindings-editor.js)
 * for the sources this plugin registered. A source another plugin registered
 * gets no hints, since this plugin cannot tell what it resolves to.
 */

/**
 * @typedef {(store: string|Object) => Object} SelectFn A registry's select().
 */

/**
 * @param {Object}      wp       The wp global.
 * @param {Object|null} hints    Data printed by inc/bindings-editor.php.
 * @param {Object|null} bindings Source data and describe(), when this plugin
 *                               registered at least one source.
 */
( function ( wp, hints, bindings ) {
	if (
		! wp ||
		! wp.hooks ||
		! wp.blockEditor ||
		! wp.components ||
		! wp.data ||
		! hints
	) {
		return;
	}

	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { createElement: el, Fragment, useMemo } = wp.element;
	const { InspectorControls, store: blockEditorStore } = wp.blockEditor;
	const { ExternalLink, Notice, PanelBody } = wp.components;
	const { useSelect } = wp.data;
	const { __, _n, sprintf } = wp.i18n;

	const SITE_DATA = 'voyager/site-data';
	const POST_META = 'voyager/post-meta-text';

	const describe =
		bindings && typeof bindings.describe === 'function'
			? bindings.describe
			: null;
	const ownsSiteData = !! ( describe && bindings.siteData );
	const ownsPostMeta = !! ( describe && bindings.postMeta );
	const links = hints.siteDataLinks || {};
	const formSlot = hints.formSlot || {};
	const canManageOptions = !! hints.canManageOptions;
	const hintClass = {
		unset: 'voyager-open-hint-unset',
		placeholder: 'voyager-open-hint-placeholder',
	};

	// Editing one of these, a post meta binding resolves on whichever post is
	// viewed, so there is no single value to show.
	const notPosts = [
		'wp_template',
		'wp_template_part',
		'wp_block',
		'wp_navigation',
	];

	const has = ( object, key ) =>
		!! object && Object.prototype.hasOwnProperty.call( object, key );

	/**
	 * The post a post meta binding reads, for describe().
	 *
	 * @param {string|undefined} postType Post type.
	 * @param {number|string}    postId   Post ID.
	 * @param {Object|undefined} meta     The post's edited meta.
	 * @return {Object|undefined} `{ postType, meta }`, or undefined when there
	 *                            is no single post.
	 */
	function postFor( postType, postId, meta ) {
		return postType && postId && ! notPosts.includes( postType )
			? { postType, meta }
			: undefined;
	}

	/**
	 * Path of a URL, without its scheme, host or query.
	 *
	 * @param {unknown} url URL to read.
	 * @return {string} Its path, or '' when it is not a URL.
	 */
	function pathOf( url ) {
		if ( typeof url !== 'string' || url === '' ) {
			return '';
		}
		try {
			return new window.URL( url, window.location.href ).pathname;
		} catch {
			return '';
		}
	}

	/**
	 * The part of a URL that names one of the pack's placeholder images,
	 * whatever host, site path or plugin folder it was saved under:
	 * `placeholders/<file>` below a folder called `assets`, or below the
	 * `voyager-open-blocks-assets` token that raw pattern markup carries
	 * (inc/patterns.php swaps it for the plugin's assets URL).
	 *
	 * @param {unknown} url URL to read.
	 * @return {string} `placeholders/<file>`, or '' for any other URL.
	 */
	function placeholderName( url ) {
		const segments = pathOf( url ).split( '/' );
		if ( segments.length < 3 ) {
			return '';
		}
		const [ folder, placeholders, file ] = segments.slice( -3 );
		return placeholders === 'placeholders' &&
			file !== '' &&
			( folder === 'assets' || folder === 'voyager-open-blocks-assets' )
			? `${ placeholders }/${ file }`
			: '';
	}

	const placeholderNames = new Set(
		( hints.placeholderImages || [] )
			.map( placeholderName )
			.filter( Boolean )
	);

	/**
	 * @param {string} source Source name.
	 * @return {boolean} Whether this plugin registered the source.
	 */
	function owns( source ) {
		return (
			( source === SITE_DATA && ownsSiteData ) ||
			( source === POST_META && ownsPostMeta )
		);
	}

	/**
	 * @param {Object} attributes Block attributes.
	 * @return {boolean} Whether any binding uses a source this plugin owns.
	 */
	function hasPackBinding( attributes ) {
		const all = attributes?.metadata?.bindings;
		if ( ! all || typeof all !== 'object' ) {
			return false;
		}
		return Object.values( all ).some( ( binding ) =>
			owns( binding?.source )
		);
	}

	/**
	 * @param {Object} attributes Block attributes.
	 * @return {Array<{attribute: string, source: string, args: Object}>} The
	 *         bindings to sources this plugin owns.
	 */
	function packBindings( attributes ) {
		const all = attributes?.metadata?.bindings;
		if ( ! all || typeof all !== 'object' ) {
			return [];
		}
		return Object.entries( all )
			.filter( ( [ , binding ] ) => owns( binding?.source ) )
			.map( ( [ attribute, binding ] ) => ( {
				attribute,
				source: binding.source,
				args: binding.args || {},
			} ) );
	}

	/**
	 * Which of the pack's placeholder images a block shows: a core/image's
	 * url, a core/media-text's mediaUrl or a core/cover's background url. A
	 * media and text or cover block set to show the post's featured image
	 * shows that instead, so it shows none.
	 *
	 * @param {string} name       Block name.
	 * @param {Object} attributes Block attributes.
	 * @return {string} `placeholders/<file>`, or '' when it shows none of them.
	 */
	function placeholderOf( name, attributes ) {
		let url;
		if ( name === 'core/image' ) {
			url = attributes?.url;
		} else if ( name === 'core/media-text' || name === 'core/cover' ) {
			url = attributes?.useFeaturedImage
				? ''
				: attributes?.[ name === 'core/cover' ? 'url' : 'mediaUrl' ];
		} else {
			return '';
		}
		const placeholder = placeholderName( url );
		return placeholder !== '' && placeholderNames.has( placeholder )
			? placeholder
			: '';
	}

	/**
	 * @param {string} name       Block name.
	 * @param {Object} attributes Block attributes.
	 * @return {boolean} Whether the block shows one of the pack's placeholder images.
	 */
	function isPlaceholderImage( name, attributes ) {
		return placeholderOf( name, attributes ) !== '';
	}

	/**
	 * What a placeholder image stands in for. The logo marquee's files are
	 * logo-<n>.svg; the rest are the image slots' art. A media and text
	 * block and a cover crop their image, so theirs also needs a focal point.
	 *
	 * @param {string} name       Block name.
	 * @param {Object} attributes Block attributes.
	 * @return {string} 'logo', 'image', 'focal', or '' for no placeholder.
	 */
	function placeholderKind( name, attributes ) {
		const placeholder = placeholderOf( name, attributes );
		if ( placeholder === '' ) {
			return '';
		}
		if ( placeholder.startsWith( 'placeholders/logo-' ) ) {
			return 'logo';
		}
		return name === 'core/image' ? 'image' : 'focal';
	}

	/**
	 * @param {string} name       Block name.
	 * @param {Object} attributes Block attributes.
	 * @return {boolean} Whether the block carries the form fallback's name.
	 */
	function isNamedFormFallback( name, attributes ) {
		return (
			name === 'core/group' &&
			!! formSlot.fallback &&
			attributes?.metadata?.name === formSlot.fallback
		);
	}

	/**
	 * @param {SelectFn} select   Registry select.
	 * @param {string}   clientId Block client ID.
	 * @return {boolean} Whether the block is the fallback still in the form slot.
	 */
	function isFormFallback( select, clientId ) {
		const editor = select( blockEditorStore );
		if (
			! isNamedFormFallback(
				editor.getBlockName( clientId ),
				editor.getBlockAttributes( clientId )
			)
		) {
			return false;
		}
		const parent = editor.getBlockRootClientId( clientId );
		return (
			!! parent &&
			editor.getBlockName( parent ) === 'core/column' &&
			editor.getBlockAttributes( parent )?.metadata?.name ===
				formSlot.slot
		);
	}

	/**
	 * The post a canvas block shows, read from the editor rather than from
	 * block context, which the canvas filter cannot reach.
	 *
	 * @param {SelectFn} select   Registry select.
	 * @param {string}   clientId Block client ID.
	 * @return {Object|null} `{ postType, meta }`, or null in a query loop or
	 *                       where there is no current post.
	 */
	function editorPost( select, clientId ) {
		// Each post in a query loop has its own value; the canvas cannot tell which.
		if (
			select( blockEditorStore ).getBlockParentsByBlockName(
				clientId,
				'core/post-template'
			).length
		) {
			return null;
		}
		const editor = select( 'core/editor' );
		const postType = editor?.getCurrentPostType?.();
		const postId = editor?.getCurrentPostId?.();
		if ( ! postFor( postType, postId ) ) {
			return null;
		}
		return postFor(
			postType,
			postId,
			select( 'core' ).getEditedEntityRecord(
				'postType',
				postType,
				postId
			)?.meta
		);
	}

	/**
	 * @param {Object|null} description From describe().
	 * @return {boolean} Whether visitors see the block's own text instead.
	 */
	function fallsBack( description ) {
		return (
			!! description &&
			( description.state === 'empty' || description.state === 'unknown' )
		);
	}

	/**
	 * The bindings of one block that fall back, described.
	 *
	 * @param {SelectFn} select     Registry select.
	 * @param {string}   clientId   Block client ID.
	 * @param {Object}   attributes Block attributes.
	 * @return {Array<Object>} `{ source, key, label }` for each.
	 */
	function fallingBack( select, clientId, attributes ) {
		let post;
		return packBindings( attributes )
			.map( ( binding ) => {
				if ( binding.source === POST_META && post === undefined ) {
					post = editorPost( select, clientId );
				}
				const description = describe(
					binding.source,
					binding.args,
					binding.source === POST_META ? post : undefined
				);
				return fallsBack( description )
					? {
							source: binding.source,
							key: String( binding.args.key ?? '' ),
							label: description.label,
						}
					: null;
			} )
			.filter( Boolean );
	}

	/**
	 * Which hint a block gets in the canvas.
	 *
	 * @param {SelectFn} select     Registry select.
	 * @param {string}   clientId   Block client ID.
	 * @param {string}   name       Block name.
	 * @param {Object}   attributes Block attributes.
	 * @return {string|null} 'placeholder', 'unset' or null.
	 */
	function canvasHint( select, clientId, name, attributes ) {
		// Pattern previews in the inserter stay clean.
		if ( select( blockEditorStore ).getSettings().isPreviewMode ) {
			return null;
		}
		if (
			isPlaceholderImage( name, attributes ) ||
			( isNamedFormFallback( name, attributes ) &&
				isFormFallback( select, clientId ) )
		) {
			return 'placeholder';
		}
		return fallingBack( select, clientId, attributes ).length
			? 'unset'
			: null;
	}

	/**
	 * What still needs filling in below a block, as a string so useSelect can
	 * compare it.
	 *
	 * @param {SelectFn} select   Registry select.
	 * @param {string}   clientId Block client ID.
	 * @return {string} JSON `{ unset, logos, images, form }`, or '' when nothing is left.
	 */
	function sectionSummary( select, clientId ) {
		const editor = select( blockEditorStore );
		const unset = [];
		let logos = 0;
		let images = 0;
		let form = false;

		for ( const id of editor.getClientIdsOfDescendants( clientId ) ) {
			const name = editor.getBlockName( id );
			const attributes = editor.getBlockAttributes( id );
			const kind = placeholderKind( name, attributes );
			if ( kind === 'logo' ) {
				logos++;
			} else if ( kind ) {
				images++;
			} else if ( isFormFallback( select, id ) ) {
				form = true;
			} else if ( hasPackBinding( attributes ) ) {
				for ( const item of fallingBack( select, id, attributes ) ) {
					// Phone number and Phone link are one setting: list it once.
					const setting =
						item.source === SITE_DATA && has( links, item.key )
							? links[ item.key ].url
							: `${ item.source } ${ item.label }`;
					if (
						! unset.some( ( seen ) => seen.setting === setting )
					) {
						unset.push( { ...item, setting } );
					}
				}
			}
		}

		return unset.length || logos || images || form
			? JSON.stringify( { unset, logos, images, form } )
			: '';
	}

	/**
	 * Plain text of an attribute value, which may be rich text HTML.
	 *
	 * @param {unknown} value Attribute value.
	 * @return {string} Its text.
	 */
	function plainText( value ) {
		if ( value === undefined || value === null ) {
			return '';
		}
		const text = String( value );
		if ( ! /[<&]/.test( text ) ) {
			return text.trim();
		}
		const doc = new window.DOMParser().parseFromString( text, 'text/html' );
		return ( doc.body.textContent || '' ).trim();
	}

	/**
	 * @param {string} attribute Bound attribute.
	 * @return {string} Its name in plain words.
	 */
	function attributeName( attribute ) {
		switch ( attribute ) {
			case 'content':
			case 'text':
				return __( 'Text', 'voyager-open-blocks' );
			case 'url':
				return __( 'Link', 'voyager-open-blocks' );
			case 'alt':
				return __( 'Alternative text', 'voyager-open-blocks' );
			case 'title':
				return __( 'Title', 'voyager-open-blocks' );
			case 'caption':
				return __( 'Caption', 'voyager-open-blocks' );
			default:
				return attribute;
		}
	}

	/**
	 * Where to set a Site Data key, for someone who may or may not manage options.
	 *
	 * @param {string} key   Site Data key.
	 * @param {number} count How many values the link is for.
	 * @return {Object|null} An element, or null for a key with no screen.
	 */
	function settingsLink( key, count = 1 ) {
		const link = has( links, key ) ? links[ key ] : null;
		if ( ! link ) {
			return null;
		}
		const general = link.screen === 'general';
		if ( ! canManageOptions ) {
			return el(
				'p',
				null,
				general
					? __(
							'Ask an administrator to add it in Settings > General.',
							'voyager-open-blocks'
						)
					: __(
							'Ask an administrator to add it in Settings > Site Data.',
							'voyager-open-blocks'
						)
			);
		}
		return el(
			'p',
			null,
			el(
				ExternalLink,
				{ href: link.url },
				general
					? _n(
							'Add it in Settings > General',
							'Add them in Settings > General',
							count,
							'voyager-open-blocks'
						)
					: _n(
							'Add it in Settings > Site Data',
							'Add them in Settings > Site Data',
							count,
							'voyager-open-blocks'
						)
			)
		);
	}

	/**
	 * Values are read when the editor loads, so say so beside a settings link.
	 *
	 * @return {Object|null} An element, or null for someone with no link.
	 */
	function reloadHelp() {
		return canManageOptions
			? el(
					'p',
					{ className: 'voyager-open-hints__help' },
					__(
						'After you save, reload this page to see the new value here.',
						'voyager-open-blocks'
					)
				)
			: null;
	}

	/**
	 * A label and value pair.
	 *
	 * @param {Object} props       Component props.
	 * @param {string} props.label Label.
	 * @param {string} props.value Value.
	 * @return {Object} Element.
	 */
	function Value( { label, value } ) {
		return el(
			'div',
			{ className: 'voyager-open-hints__pair' },
			el( 'p', { className: 'voyager-open-hints__label' }, label ),
			el( 'p', { className: 'voyager-open-hints__value' }, value )
		);
	}

	/**
	 * One binding: what it reads, what it holds, and what to do when empty.
	 *
	 * @param {Object}  props               Component props.
	 * @param {Object}  props.binding       `{ attribute, source, args }`.
	 * @param {Object}  props.description   From describe().
	 * @param {string}  props.authored      The block's own text for the attribute.
	 * @param {boolean} props.showAttribute Name the attribute (several are bound).
	 * @param {boolean} props.editable      The value can be typed in the block.
	 * @return {Object} Element.
	 */
	function BindingItem( {
		binding,
		description,
		authored,
		showAttribute,
		editable,
	} ) {
		const { label, value, state } = description;
		const key = String( binding.args.key ?? '' );
		const isLink = binding.attribute === 'url';
		const fallback = isLink
			? __(
					'Not set yet. Visitors get the link you set here.',
					'voyager-open-blocks'
				)
			: __(
					'Not set yet. Visitors see the text you typed here.',
					'voyager-open-blocks'
				);
		const unknownFallback = isLink
			? __( 'Visitors get the link you set here.', 'voyager-open-blocks' )
			: __(
					'Visitors see the text you typed here.',
					'voyager-open-blocks'
				);
		const message =
			state === 'unknown'
				? sprintf(
						/* translators: %s: binding key. */
						__( 'No field called “%s”.', 'voyager-open-blocks' ),
						key
					) +
					' ' +
					unknownFallback
				: fallback;
		const source =
			binding.source === SITE_DATA
				? __( 'Site Data', 'voyager-open-blocks' )
				: __( 'Post meta', 'voyager-open-blocks' );

		let status;
		if ( state === 'set' ) {
			status = el( Value, {
				label: __( 'Current value', 'voyager-open-blocks' ),
				value,
			} );
		} else if ( state === 'no-post' ) {
			status = el(
				'p',
				{ className: 'voyager-open-hints__help' },
				__(
					'Each post shows its own value for this field.',
					'voyager-open-blocks'
				)
			);
		} else {
			let action = null;
			if ( state === 'empty' && binding.source === SITE_DATA ) {
				const link = settingsLink( key );
				action = link && el( Fragment, null, link, reloadHelp() );
			} else if ( state === 'empty' && editable ) {
				action = el(
					'p',
					null,
					__(
						'Type in the block to add it for this post.',
						'voyager-open-blocks'
					)
				);
			}
			status = el(
				Notice,
				{
					status: 'warning',
					isDismissible: false,
					spokenMessage: message,
				},
				el( 'p', null, message ),
				action
			);
		}

		return el(
			'div',
			{ className: 'voyager-open-hints__item' },
			showAttribute &&
				el(
					'p',
					{ className: 'voyager-open-hints__label' },
					attributeName( binding.attribute )
				),
			el(
				'p',
				{ className: 'voyager-open-hints__field' },
				el(
					'strong',
					null,
					sprintf(
						/* translators: 1: field name, for example "Phone number". 2: where it comes from, "Site Data" or "Post meta". */
						__( '%1$s, from %2$s', 'voyager-open-blocks' ),
						label,
						source
					)
				),
				' ',
				el( 'code', null, key )
			),
			status,
			state !== 'set' &&
				authored !== '' &&
				el( Value, {
					label: __( 'What visitors see now', 'voyager-open-blocks' ),
					value: authored,
				} )
		);
	}

	/**
	 * The "Connected data" panel.
	 *
	 * @param {Object} props          Component props.
	 * @param {string} props.clientId Block client ID.
	 * @param {Array}  props.bound    From packBindings().
	 * @param {Object} props.context  Block context.
	 * @return {Object} Element.
	 */
	function BindingsPanel( { clientId, bound, context } ) {
		const postId = context?.postId;
		const postType = context?.postType;
		const needsMeta = bound.some(
			( binding ) => binding.source === POST_META
		);

		const meta = useSelect(
			( select ) =>
				needsMeta && postId && postType
					? select( 'core' ).getEditedEntityRecord(
							'postType',
							postType,
							postId
						)?.meta
					: undefined,
			[ needsMeta, postId, postType ]
		);

		// The stored attributes: what the block holds before bindings apply.
		const authoredAttributes = useSelect(
			( select ) =>
				select( blockEditorStore ).getBlockAttributes( clientId ),
			[ clientId ]
		);

		const editable = useSelect(
			( select ) => {
				const source = wp.blocks?.getBlockBindingsSource?.( POST_META );
				return bound
					.filter(
						( binding ) =>
							binding.source === POST_META &&
							!! source?.canUserEditValue?.( {
								select,
								context: context || {},
								args: binding.args,
							} )
					)
					.map( ( binding ) => binding.attribute )
					.join( ' ' );
			},
			[ bound, context ]
		);

		const post = useMemo(
			() => postFor( postType, postId, meta ),
			[ postType, postId, meta ]
		);

		return el(
			PanelBody,
			{
				title: __( 'Connected data', 'voyager-open-blocks' ),
				className: 'voyager-open-hints',
			},
			bound.map( ( binding ) =>
				el( BindingItem, {
					key: binding.attribute,
					binding,
					description: describe(
						binding.source,
						binding.args,
						binding.source === POST_META ? post : undefined
					),
					authored: plainText(
						authoredAttributes?.[ binding.attribute ]
					),
					showAttribute: bound.length > 1,
					editable: editable
						.split( ' ' )
						.includes( binding.attribute ),
				} )
			)
		);
	}

	/**
	 * The "Placeholder content" panel.
	 *
	 * @param {Object} props      Component props.
	 * @param {string} props.kind 'logo', 'image', 'focal' or 'form'.
	 * @return {Object} Element.
	 */
	function PlaceholderPanel( { kind } ) {
		const messages = {
			logo: __(
				'Placeholder. Replace it with your own logo.',
				'voyager-open-blocks'
			),
			image: __(
				'Placeholder. Replace it with your own image.',
				'voyager-open-blocks'
			),
			focal: __(
				'Placeholder. Replace it with your own image, then set its focal point on the subject.',
				'voyager-open-blocks'
			),
			form: __(
				'Replace this with your form block.',
				'voyager-open-blocks'
			),
		};
		const message = messages[ kind ] || messages.form;
		return el(
			PanelBody,
			{
				title: __( 'Placeholder content', 'voyager-open-blocks' ),
				className: 'voyager-open-hints',
			},
			el(
				Notice,
				{
					status: 'warning',
					isDismissible: false,
					spokenMessage: message,
				},
				el( 'p', null, message )
			)
		);
	}

	/**
	 * The "Still to fill in" panel for a block with hints below it.
	 *
	 * @param {Object} props         Component props.
	 * @param {string} props.summary From sectionSummary().
	 * @return {Object} Element.
	 */
	function SummaryPanel( { summary } ) {
		const { unset, logos, images, form } = JSON.parse( summary );
		const items = unset.map( ( item ) =>
			sprintf(
				/* translators: %s: field name, for example "Phone number". */
				__( '%s: not set yet', 'voyager-open-blocks' ),
				item.label
			)
		);
		if ( logos ) {
			items.push(
				sprintf(
					/* translators: %d: number of placeholder logos. */
					_n(
						'%d placeholder logo to replace',
						'%d placeholder logos to replace',
						logos,
						'voyager-open-blocks'
					),
					logos
				)
			);
		}
		if ( images ) {
			items.push(
				sprintf(
					/* translators: %d: number of placeholder images. */
					_n(
						'%d placeholder image to replace',
						'%d placeholder images to replace',
						images,
						'voyager-open-blocks'
					),
					images
				)
			);
		}
		if ( form ) {
			items.push(
				__(
					'The form slot to fill with your form block',
					'voyager-open-blocks'
				)
			);
		}

		const siteData = unset.filter(
			( item ) => item.source === SITE_DATA && has( links, item.key )
		);
		const screens = [ 'site-data', 'general' ]
			.map( ( screen ) =>
				siteData.filter(
					( item ) => links[ item.key ].screen === screen
				)
			)
			.filter( ( group ) => group.length );
		const intro = __(
			'Visitors see the pattern’s own text and images until these are done.',
			'voyager-open-blocks'
		);

		return el(
			PanelBody,
			{
				title: __( 'Still to fill in', 'voyager-open-blocks' ),
				className: 'voyager-open-hints',
			},
			el(
				Notice,
				{
					status: 'warning',
					isDismissible: false,
					spokenMessage: [ intro, ...items ].join( '. ' ),
				},
				el( 'p', null, intro ),
				el(
					'ul',
					{ className: 'voyager-open-hints__list' },
					items.map( ( item ) => el( 'li', { key: item }, item ) )
				),
				screens.map( ( group ) =>
					el(
						Fragment,
						{ key: links[ group[ 0 ].key ].screen },
						settingsLink( group[ 0 ].key, group.length )
					)
				),
				screens.length > 0 && reloadHelp()
			)
		);
	}

	/**
	 * Every hint panel for the selected block.
	 *
	 * @param {Object} props            Component props.
	 * @param {string} props.clientId   Block client ID.
	 * @param {string} props.name       Block name.
	 * @param {Object} props.attributes Block attributes.
	 * @param {Object} props.context    Block context.
	 * @return {Object|null} Element.
	 */
	function HintsPanels( { clientId, name, attributes, context } ) {
		const bindingsAttribute = attributes?.metadata?.bindings;
		const bound = useMemo(
			() => packBindings( { metadata: { bindings: bindingsAttribute } } ),
			[ bindingsAttribute ]
		);

		const placeholder = useSelect(
			( select ) => {
				const kind = placeholderKind( name, attributes );
				if ( kind ) {
					return kind;
				}
				// Inside a content-only section the fallback group cannot be
				// selected, only its blocks, so look up the parents too.
				const editor = select( blockEditorStore );
				const ids = [ clientId, ...editor.getBlockParents( clientId ) ];
				return ids.some( ( id ) => isFormFallback( select, id ) )
					? 'form'
					: '';
			},
			[ clientId, name, attributes ]
		);

		const summary = useSelect(
			( select ) =>
				select( blockEditorStore ).getBlockCount( clientId )
					? sectionSummary( select, clientId )
					: '',
			[ clientId ]
		);

		if ( ! bound.length && ! placeholder && ! summary ) {
			return null;
		}

		return el(
			InspectorControls,
			{ group: 'content' },
			bound.length > 0 &&
				el( BindingsPanel, { clientId, bound, context } ),
			!! placeholder && el( PlaceholderPanel, { kind: placeholder } ),
			!! summary && el( SummaryPanel, { summary } )
		);
	}

	addFilter(
		'editor.BlockEdit',
		'voyager-open-blocks/hints-panels',
		createHigherOrderComponent(
			( BlockEdit ) =>
				function VoyagerOpenHintsPanels( props ) {
					return el(
						Fragment,
						null,
						el( BlockEdit, props ),
						props.isSelected &&
							el( HintsPanels, {
								clientId: props.clientId,
								name: props.name,
								attributes: props.attributes,
								context: props.context,
							} )
					);
				},
			'withVoyagerOpenHintsPanels'
		)
	);

	addFilter(
		'editor.BlockListBlock',
		'voyager-open-blocks/canvas-hints',
		createHigherOrderComponent(
			( BlockListBlock ) =>
				function VoyagerOpenCanvasHint( props ) {
					const { clientId, name, attributes } = props;
					const candidate =
						hasPackBinding( attributes ) ||
						isPlaceholderImage( name, attributes ) ||
						isNamedFormFallback( name, attributes );
					const hint = useSelect(
						( select ) =>
							candidate
								? canvasHint(
										select,
										clientId,
										name,
										attributes
									)
								: null,
						[ candidate, clientId, name, attributes ]
					);
					return el(
						BlockListBlock,
						hint
							? {
									...props,
									className: [
										props.className,
										hintClass[ hint ],
									]
										.filter( Boolean )
										.join( ' ' ),
								}
							: props
					);
				},
			'withVoyagerOpenCanvasHint'
		)
	);
} )(
	window.wp,
	window.voyagerOpenBlocksHints,
	window.voyagerOpenBlocksBindings
);
