( function () {
	'use strict';

	const { registerPaymentMethod }     = wc.wcBlocksRegistry;
	const { getSetting }                = wc.wcSettings;
	const { createElement }             = wp.element;
	const { decodeEntities }            = wp.htmlEntities;

	const settings    = getSetting( 'coincircuit_data', {} );
	const title       = decodeEntities( settings.title || 'CoinCircuit' );
	var iconUrl       = settings.icon || '';
	var cryptoIcons   = settings.cryptoIcons || [];
	var badgeStyle    = { width: '20px', height: '20px', objectFit: 'contain' };

	var Label = function () {
		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
			createElement(
				'span',
				{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
				iconUrl
					? createElement( 'img', { src: iconUrl, alt: title, style: { width: '24px', height: '24px', objectFit: 'contain' } } )
					: null,
				title
			),
			createElement(
				'span',
				{ style: { display: 'flex', alignItems: 'center', gap: '4px' } },
				cryptoIcons.map( function ( coin ) {
					return createElement( 'img', { key: coin.alt, src: coin.src, alt: coin.alt, style: badgeStyle } );
				} ),
				createElement( 'span', { style: { fontSize: '12px', color: '#666', marginLeft: '2px' } }, '+8' )
			)
		);
	};

	var Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	registerPaymentMethod( {
		name: 'coincircuit',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
