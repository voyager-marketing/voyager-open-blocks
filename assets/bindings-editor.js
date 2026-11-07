/**
 * Editor half of voyager/site-data and voyager/post-meta-text.
 *
 * Data comes from window.voyagerOpenBlocksBindings (inc/bindings-editor.php).
 * A source is null there when another plugin owns it, and is then skipped.
 *
 * This script also adds describe() to that object. assets/editor/hints.js
 * calls it to name a binding in plain words and to tell whether its value is
 * empty, so both files read values the same way the front end does.
 */

/**
 * @typedef {Object} BindingDescription
 * @property {string} label Field label, or the key when the field is unknown.
 * @property {string} value Current value, '' when there is none.
 * @property {string} state 'set', 'empty', 'unknown' (no such field) or
 *                          'no-post' (post meta with no post to read).
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
	const has = ( object, key ) =>
		Object.prototype.hasOwnProperty.call( object, key );
	const stateOf = ( value ) => ( value.trim() !== '' ? 'set' : 'empty' );
	const describers = {};

	if ( data.siteData ) {
		const { fields, values } = data.siteData;
		const labels = new Map(
			fields.map( ( field ) => [ field.key, field.label ] )
		);

		/**
		 * @param {Object} args Binding args, `{ key }`.
		 * @return {BindingDescription} The binding, described.
		 */
		describers[ 'voyager/site-data' ] = ( args ) => {
			const key = String( args?.key ?? '' );
			if ( ! labels.has( key ) ) {
				return { label: key, value: '', state: 'unknown' };
			}
			const value = has( values, key ) ? String( values[ key ] ) : '';
			return { label: labels.get( key ), value, state: stateOf( value ) };
		};

		registerBlockBindingsSource( {
			name: 'voyager/site-data',
			getValues( { bindings } ) {
				const result = {};
				for ( const [ attribute, binding ] of Object.entries(
					bindings
				) ) {
					const { value, state } = describers[ 'voyager/site-data' ](
						binding?.args
					);
					// An empty value keeps the authored placeholder, as on the front end.
					result[ attribute ] = state === 'set' ? value : undefined;
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
				( postType && allowed[ postType ] ) || {}
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

		/**
		 * Same rules as PostMetaText\resolve(): an allowlisted base key, a
		 * non-blank stored string, and a dot path into its JSON.
		 *
		 * @param {Object} args   Binding args, `{ key }`.
		 * @param {Object} [post] `{ postType, meta }` of the post shown.
		 * @return {BindingDescription} The binding, described.
		 */
		describers[ 'voyager/post-meta-text' ] = ( args, post ) => {
			const key = String( args?.key ?? '' );
			if ( ! post?.postType ) {
				return { label: key, value: '', state: 'no-post' };
			}
			const base = key.split( '.' )[ 0 ];
			const fields = fieldsFor( post.postType );
			if ( ! has( fields, base ) ) {
				return { label: key, value: '', state: 'unknown' };
			}
			const path = key.split( '.' ).slice( 1 );
			const name = fields[ base ] || base;
			const raw = post.meta?.[ base ];
			const resolved =
				typeof raw === 'string' && raw.trim() !== ''
					? readPath( raw, path )
					: undefined;
			const value = resolved === undefined ? '' : resolved;
			return {
				label: path.length ? `${ name } (${ path.join( '.' ) })` : name,
				value,
				state: stateOf( value ),
			};
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
				const post = {
					postType: context?.postType,
					meta: record?.meta,
				};
				const fields = fieldsFor( context?.postType );

				for ( const [ attribute, binding ] of Object.entries(
					bindings
				) ) {
					const key = String( binding?.args?.key || '' );
					const [ base ] = key.split( '.' );
					const { value, state } = describers[
						'voyager/post-meta-text'
					]( binding?.args, post );
					// The editor shows the field's label until it has a value.
					result[ attribute ] =
						state === 'set'
							? value
							: ( has( fields, base ) && fields[ base ] ) || key;
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
					! has( fieldsFor( context.postType ), key )
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

	/**
	 * Describe a binding to a source this plugin registered.
	 *
	 * @param {string} source Source name.
	 * @param {Object} args   Binding args.
	 * @param {Object} [post] `{ postType, meta }`, for voyager/post-meta-text.
	 * @return {BindingDescription|null} Null for a source another plugin owns.
	 */
	data.describe = ( source, args, post ) =>
		has( describers, source ) ? describers[ source ]( args, post ) : null;
} )( window.wp, window.voyagerOpenBlocksBindings );
