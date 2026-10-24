/**
 * Editor half of voyager/site-data and voyager/post-meta-text.
 *
 * Data comes from window.voyagerOpenBlocksBindings (inc/bindings-editor.php).
 * A source is null there when another plugin owns it, and is then skipped.
 */

/**
 * @param {Object}      wp   The wp global.
 * @param {Object|null} data Source data printed by inc/bindings-editor.php.
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.blocks || ! data ) {
		return;
	}

	const { registerBlockBindingsSource } = wp.blocks;
	const coreStore = 'core';

	if ( data.siteData ) {
		const { fields, values } = data.siteData;

		registerBlockBindingsSource( {
			name: 'voyager/site-data',
			getValues( { bindings } ) {
				const result = {};
				for ( const [ attribute, binding ] of Object.entries(
					bindings
				) ) {
					const key = binding?.args?.key;
					// An empty value keeps the authored placeholder, as on the front end.
					result[ attribute ] = values[ key ] || undefined;
				}
				return result;
			},
			canUserEditValue() {
				return false;
			},
			getFieldsList() {
				return fields.map( ( field ) => ( {
					label: field.label,
					type: 'string',
					args: { key: field.key },
				} ) );
			},
		} );
	}

	if ( data.postMeta ) {
		const allowed = data.postMeta.fields || {};

		const fieldsFor = ( postType ) =>
			Object.assign(
				{},
				allowed[ '*' ] || {},
				allowed[ postType ] || {}
			);

		const readPath = ( raw, path ) => {
			if ( ! path.length ) {
				return raw;
			}
			let current;
			try {
				current = JSON.parse( raw );
			} catch {
				return undefined;
			}
			for ( const segment of path ) {
				if (
					current === null ||
					typeof current !== 'object' ||
					! ( segment in current )
				) {
					return undefined;
				}
				current = current[ segment ];
			}
			return current === null || typeof current === 'object'
				? undefined
				: String( current );
		};

		registerBlockBindingsSource( {
			name: 'voyager/post-meta-text',
			usesContext: [ 'postId', 'postType' ],
			getValues( { select, context, bindings } ) {
				const result = {};
				const record =
					context?.postId && context?.postType
						? select( coreStore ).getEditedEntityRecord(
								'postType',
								context.postType,
								context.postId
							)
						: null;
				const fields = fieldsFor( context?.postType );

				for ( const [ attribute, binding ] of Object.entries(
					bindings
				) ) {
					const key = String( binding?.args?.key || '' );
					const [ base, ...path ] = key.split( '.' );
					const value = record?.meta?.[ base ];
					const resolved =
						typeof value === 'string' && value !== ''
							? readPath( value, path )
							: undefined;
					result[ attribute ] =
						resolved !== undefined && resolved !== ''
							? resolved
							: fields[ base ] || key;
				}
				return result;
			},
			setValues( { dispatch, context, bindings } ) {
				const meta = {};
				for ( const { args, newValue } of Object.values( bindings ) ) {
					meta[ args.key ] = newValue;
				}
				dispatch( coreStore ).editEntityRecord(
					'postType',
					context.postType,
					context.postId,
					{ meta }
				);
			},
			canUserEditValue( { select, context, args } ) {
				if (
					! context?.postId ||
					! context?.postType ||
					context?.query ||
					context?.queryId
				) {
					return false;
				}
				const key = String( args?.key || '' );
				// A dot path points into JSON; editing it would rewrite the whole field.
				if (
					key.includes( '.' ) ||
					! ( key in fieldsFor( context.postType ) )
				) {
					return false;
				}
				return !! select( coreStore ).canUser( 'update', {
					kind: 'postType',
					name: context.postType,
					id: context.postId,
				} );
			},
			getFieldsList( { context } ) {
				return Object.entries( fieldsFor( context?.postType ) ).map(
					( [ key, label ] ) => ( {
						label,
						type: 'string',
						args: { key },
					} )
				);
			},
		} );
	}
} )( window.wp, window.voyagerOpenBlocksBindings );
