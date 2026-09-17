import { __ } from '@wordpress/i18n';

export const VENDOR_PATTERN = /^[a-z0-9]([_.-]?[a-z0-9]+)*$/;
export const PACKAGE_SLUG_PATTERN = /^[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/;
export const PHP_CONSTRAINT_PATTERN = /^[0-9A-Za-z.*<>=!~^|, +\-]+$/;

const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

export const cloneSettings = ( settings ) => clone( settings );

export const isSettingsDirty = ( saved, draft ) =>
	JSON.stringify( saved ) !== JSON.stringify( draft );

export const getDraftAfterSave = ( current, submitted, saved ) =>
	isSettingsDirty( submitted, current ) ? current : cloneSettings( saved );

export const getRepositoryNameError = ( name ) => {
	if (
		typeof name !== 'string' ||
		! name.trim() ||
		Array.from( name ).length > 100
	) {
		return __(
			'Enter a repository title containing no more than 100 characters.',
			'edd-composer'
		);
	}

	return null;
};

export const getVendorError = ( vendor ) => {
	if ( ! VENDOR_PATTERN.test( vendor ) ) {
		return __(
			'Use lowercase letters and numbers, optionally separated by dots, underscores, or hyphens.',
			'edd-composer'
		);
	}

	return null;
};

export const getPackageSlugError = ( slug ) => {
	if ( ! PACKAGE_SLUG_PATTERN.test( slug ) ) {
		return __(
			'Use a lowercase Composer package segment without spaces.',
			'edd-composer'
		);
	}

	return null;
};

export const getPhpConstraintError = ( constraint ) => {
	if (
		! constraint ||
		constraint.length > 100 ||
		! PHP_CONSTRAINT_PATTERN.test( constraint ) ||
		! /[0-9]/.test( constraint )
	) {
		return __(
			'Enter a Composer-style PHP constraint such as >=7.4 or ^8.1.',
			'edd-composer'
		);
	}

	return null;
};

export const getProductConfig = ( settings, product ) => ( {
	enabled: false,
	package_slug: product.default_package_slug,
	type: 'wordpress-plugin',
	description: product.default_description,
	require_php: '>=7.4',
	...( settings.products?.[ product.id ] ?? {} ),
} );

export const updateProductSettings = ( settings, product, edits ) => ( {
	...settings,
	products: {
		...settings.products,
		[ product.id ]: {
			...getProductConfig( settings, product ),
			...edits,
		},
	},
} );

export const mergeCatalogue = ( products, settings ) => {
	const vendorError = getVendorError( settings.vendor );
	const merged = products.map( ( product ) => {
		const config = getProductConfig( settings, product );
		const packageName = `${ settings.vendor }/${ config.package_slug }`;
		const metadataMessages = [
			getPackageSlugError( config.package_slug ),
			getPhpConstraintError( config.require_php ),
		].filter( Boolean );
		const validationMessages = [
			...( product.file_validation_messages ?? [] ),
			...metadataMessages,
		];

		if ( vendorError ) {
			validationMessages.push( vendorError );
		}

		return {
			...product,
			saved_enabled: product.enabled,
			saved_package_slug: product.package_slug,
			...config,
			package_name: packageName,
			metadata_messages: metadataMessages,
			validation_messages: validationMessages,
			can_enable:
				product.can_enable &&
				! vendorError &&
				metadataMessages.length === 0,
			identity_changed:
				product.enabled && packageName !== product.package_name,
		};
	} );

	const enabledBySlug = new Map();

	merged.forEach( ( product ) => {
		if ( ! product.enabled ) {
			return;
		}

		const matching = enabledBySlug.get( product.package_slug ) ?? [];
		matching.push( product.id );
		enabledBySlug.set( product.package_slug, matching );
	} );

	return merged.map( ( product ) => {
		const enabledMatches = enabledBySlug.get( product.package_slug ) ?? [];
		const hasCollision = product.enabled
			? enabledMatches.length > 1
			: enabledMatches.length > 0;

		if ( ! hasCollision ) {
			return product;
		}

		return {
			...product,
			can_enable: false,
			validation_messages: [
				...product.validation_messages,
				__(
					'Another enabled product uses this package slug.',
					'edd-composer'
				),
			],
		};
	} );
};

export const applyBulkEnabled = ( settings, products, productIds, enabled ) => {
	let nextSettings = cloneSettings( settings );

	for ( const productId of productIds ) {
		const catalogue = mergeCatalogue( products, nextSettings );
		const product = catalogue.find(
			( candidate ) => String( candidate.id ) === String( productId )
		);

		if ( ! product || ( enabled && ! product.can_enable ) ) {
			continue;
		}

		nextSettings = updateProductSettings( nextSettings, product, {
			enabled,
		} );
	}

	return nextSettings;
};

export const hasBlockingErrors = (
	catalogue,
	savedSettings,
	draftSettings
) => {
	if ( getVendorError( draftSettings.vendor ) ) {
		return true;
	}

	return catalogue.some( ( product ) => {
		if ( product.metadata_messages.length > 0 ) {
			return true;
		}

		const wasEnabled = Boolean(
			savedSettings.products?.[ product.id ]?.enabled
		);

		return product.enabled && ! wasEnabled && ! product.can_enable;
	} );
};
