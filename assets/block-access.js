/**
 * The Access panel on every block: who the block is shown to. The decision
 * is made on the server (RedeemID\Access\BlockAccess); this only edits
 * the redeemidAccess attribute and warns about settings that can't work.
 *
 * No build step: plain script on the editor's own globals.
 */
( function ( wp, config ) {
	if ( ! wp || ! config ) {
		return;
	}

	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, CheckboxControl, Notice } = wp.components;
	const { useSelect } = wp.data;
	const ATTR = config.attr;

	const SHOWS = [
		{ value: 'everyone', label: __( 'Everyone who sees this part of the item', 'redeemid' ) },
		{ value: 'readers', label: __( 'Readers: visitors who can read this item', 'redeemid' ) },
		{ value: 'levels', label: __( 'Readers who hold one of these levels', 'redeemid' ) },
		{ value: 'locked', label: __( 'Locked visitors only (upgrade prompts)', 'redeemid' ) },
		{ value: 'without', label: __( 'Visitors without these levels', 'redeemid' ) },
	];

	const HELP = {
		everyone: __( 'No condition of its own. The item\'s rule and the paywall break still apply.', 'redeemid' ),
		readers: __( 'Hidden from anyone this item locks out. Never used in search, excerpts or SEO descriptions.', 'redeemid' ),
		levels: __( 'Narrows the item\'s rule: a reader also needs one of these levels. Never public text.', 'redeemid' ),
		locked: __( 'Shown only to visitors who can\'t read this item. Counts as public text.', 'redeemid' ),
		without: __( 'Shown to anyone holding none of these levels, readers included. Counts as public text.', 'redeemid' ),
	};

	const skip = ( name ) => config.skip.indexOf( name ) !== -1;

	addFilter( 'blocks.registerBlockType', 'redeemid/block-access/attribute', ( settings, name ) => {
		if ( skip( name ) || ( settings.attributes && settings.attributes[ ATTR ] ) ) {
			return settings;
		}

		return {
			...settings,
			attributes: { ...settings.attributes, [ ATTR ]: { type: 'object' } },
		};
	} );

	/**
	 * Is the block at or below a top-level block that comes after the break?
	 */
	const useAfterBreak = ( clientId ) =>
		useSelect(
			( select ) => {
				const store = select( 'core/block-editor' );
				let top = clientId;

				while ( store.getBlockRootClientId( top ) ) {
					top = store.getBlockRootClientId( top );
				}

				const order = store.getBlockOrder();
				const breakAt = order.findIndex( ( id ) => store.getBlockName( id ) === config.break );

				return breakAt !== -1 && order.indexOf( top ) > breakAt;
			},
			[ clientId ]
		);

	const FEATURES = config.features || [];

	const Panel = ( { attributes, setAttributes, clientId } ) => {
		const value = attributes[ ATTR ] || {};
		const show = value.show || 'everyone';
		const levels = Array.isArray( value.levels ) ? value.levels : [];
		const feature = value.feature || '';
		const when = value.when === 'lacks' ? 'lacks' : 'has';
		const afterBreak = useAfterBreak( clientId );
		const set = ( next ) => {
			const merged = { show, levels, feature, when, ...next };

			if ( ! merged.feature ) {
				delete merged.feature;
				delete merged.when;
			}

			setAttributes( { [ ATTR ]: merged.show === 'everyone' && ! merged.feature ? undefined : merged } );
		};
		const needsLevels = show === 'levels' || show === 'without';
		const known = FEATURES.find( ( f ) => f.slug === feature );

		return el(
			PanelBody,
			{ title: __( 'Access', 'redeemid' ), initialOpen: show !== 'everyone' || !! feature },
			el( SelectControl, {
				label: __( 'Show this block to', 'redeemid' ),
				value: show,
				options: SHOWS,
				help: HELP[ show ],
				onChange: ( next ) => set( { show: next, levels: next === 'levels' || next === 'without' ? levels : [] } ),
				__nextHasNoMarginBottom: true,
			} ),
			needsLevels &&
				el(
					'fieldset',
					{ style: { marginTop: '12px' } },
					el( 'legend', null, __( 'Levels', 'redeemid' ) ),
					config.levels.map( ( level ) =>
						el( CheckboxControl, {
							key: level.slug,
							label: level.label,
							checked: levels.indexOf( level.slug ) !== -1,
							onChange: ( on ) =>
								set( {
									levels: on
										? levels.concat( level.slug )
										: levels.filter( ( slug ) => slug !== level.slug ),
								} ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
			show === 'levels' &&
				! levels.length &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'Pick at least one level. Until then nobody sees this block.', 'redeemid' )
				),
			( show === 'locked' || show === 'without' ) &&
				afterBreak &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'This block is after the paywall break, so locked visitors never reach it. Move it above the break.', 'redeemid' )
				),
			( FEATURES.length > 0 || feature ) &&
				el( SelectControl, {
					label: __( 'Feature', 'redeemid' ),
					value: feature,
					options: [ { value: '', label: __( 'No feature rule', 'redeemid' ) } ]
						.concat( FEATURES.map( ( f ) => ( { value: f.slug, label: f.label } ) ) )
						.concat( feature && ! known ? [ { value: feature, label: feature + ' ' + __( '(not registered)', 'redeemid' ) } ] : [] ),
					help: __( 'Features are site-wide behaviours from RedeemID → Access → Features. Decided on the server: a hidden block is never sent.', 'redeemid' ),
					onChange: ( next ) => set( { feature: next } ),
					__nextHasNoMarginBottom: true,
				} ),
			feature &&
				el( SelectControl, {
					label: __( 'Show when the visitor', 'redeemid' ),
					value: when,
					options: [
						{ value: 'has', label: __( 'holds this feature', 'redeemid' ) },
						{ value: 'lacks', label: __( 'does not hold it (upgrade prompts)', 'redeemid' ) },
					],
					onChange: ( next ) => set( { when: next } ),
					__nextHasNoMarginBottom: true,
				} ),
			feature &&
				! known &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'This feature is not registered for blocks, so nobody holds it here.', 'redeemid' )
				)
		);
	};

	addFilter(
		'editor.BlockEdit',
		'redeemid/block-access/panel',
		createHigherOrderComponent(
			( BlockEdit ) => ( props ) => {
				if ( skip( props.name ) || ! props.isSelected ) {
					return el( BlockEdit, props );
				}

				return el(
					wp.element.Fragment,
					null,
					el( BlockEdit, props ),
					el( InspectorControls, null, el( Panel, props ) )
				);
			},
			'withRedeemidAccess'
		)
	);

	// Bindings to the Access source show their key in the editor; the value is decided per visitor on the page.
	if ( wp.blocks && wp.blocks.registerBlockBindingsSource ) {
		wp.blocks.registerBlockBindingsSource( {
			name: config.source,
			label: __( 'Access', 'redeemid' ),
			getValues: ( { bindings } ) => {
				const values = {};

				Object.keys( bindings ).forEach( ( attribute ) => {
					values[ attribute ] = '[' + __( 'Access', 'redeemid' ) + ': ' + ( ( bindings[ attribute ].args || {} ).key || 'level' ) + ']';
				} );

				return values;
			},
			canUserEditValue: () => false,
		} );
	}

	addFilter(
		'editor.BlockListBlock',
		'redeemid/block-access/outline',
		createHigherOrderComponent(
			( BlockListBlock ) => ( props ) => {
				const value = props.attributes && props.attributes[ ATTR ];

				if ( ! value || ( ( ! value.show || value.show === 'everyone' ) && ! value.feature ) ) {
					return el( BlockListBlock, props );
				}

				return el( BlockListBlock, {
					...props,
					className: [ props.className, 'redeemid-access-limited', 'redeemid-access-' + ( value.show || 'everyone' ), value.feature ? 'redeemid-access-feature' : '' ].filter( Boolean ).join( ' ' ),
				} );
			},
			'withRedeemidAccessOutline'
		)
	);
} )( window.wp, window.redeemidBlockAccess );
