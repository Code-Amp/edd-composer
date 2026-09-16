import {
	applyBulkEnabled,
	getPackageSlugError,
	getPhpConstraintError,
	getVendorError,
	hasBlockingErrors,
	isSettingsDirty,
	mergeCatalogue,
	updateProductSettings,
} from '../../src/utils/settings';

const products = [
	{
		id: 10,
		title: 'First product',
		default_package_slug: 'first-product',
		default_description: 'First description',
		enabled: false,
		package_slug: 'first-product',
		can_enable: true,
		file_validation_messages: [],
	},
	{
		id: 20,
		title: 'Second product',
		default_package_slug: 'second-product',
		default_description: 'Second description',
		enabled: false,
		package_slug: 'second-product',
		can_enable: false,
		file_validation_messages: [ 'Add a versioned file.' ],
	},
];

const settings = {
	schema_version: 1,
	vendor: 'code-amp',
	products: {},
};

describe( 'settings utilities', () => {
	test( 'validates Composer vendor, package, and PHP inputs', () => {
		expect( getVendorError( 'code-amp' ) ).toBeNull();
		expect( getVendorError( 'Code Amp' ) ).not.toBeNull();
		expect( getPackageSlugError( 'sample-plugin' ) ).toBeNull();
		expect( getPackageSlugError( 'Sample Plugin' ) ).not.toBeNull();
		expect( getPhpConstraintError( '^8.1 || ^8.2' ) ).toBeNull();
		expect( getPhpConstraintError( 'latest' ) ).not.toBeNull();
	} );

	test( 'merges product defaults without mutating saved settings', () => {
		const catalogue = mergeCatalogue( products, settings );

		expect( catalogue[ 0 ] ).toMatchObject( {
			enabled: false,
			package_slug: 'first-product',
			package_name: 'code-amp/first-product',
			type: 'wordpress-plugin',
			require_php: '>=7.4',
			can_enable: true,
		} );
		expect( settings.products ).toEqual( {} );
	} );

	test( 'stores a complete product configuration when one field changes', () => {
		const updated = updateProductSettings( settings, products[ 0 ], {
			package_slug: 'custom-package',
		} );

		expect( updated.products[ 10 ] ).toEqual( {
			enabled: false,
			package_slug: 'custom-package',
			type: 'wordpress-plugin',
			description: 'First description',
			require_php: '>=7.4',
		} );
		expect( settings.products ).toEqual( {} );
	} );

	test( 'marks enabled package-slug collisions on every affected product', () => {
		let updated = updateProductSettings( settings, products[ 0 ], {
			enabled: true,
			package_slug: 'shared',
		} );
		updated = updateProductSettings( updated, products[ 1 ], {
			enabled: true,
			package_slug: 'shared',
		} );

		const catalogue = mergeCatalogue( products, updated );

		expect( catalogue[ 0 ].can_enable ).toBe( false );
		expect( catalogue[ 1 ].can_enable ).toBe( false );
		expect( catalogue[ 0 ].validation_messages ).toContain(
			'Another enabled product uses this package slug.'
		);
	} );

	test( 'warns when the vendor changes an enabled package identity', () => {
		const enabledProduct = {
			...products[ 0 ],
			enabled: true,
			package_name: 'code-amp/first-product',
		};
		const catalogue = mergeCatalogue( [ enabledProduct ], {
			...settings,
			vendor: 'new-vendor',
			products: {
				10: {
					enabled: true,
					package_slug: 'first-product',
					type: 'wordpress-plugin',
					description: 'First description',
					require_php: '>=7.4',
				},
			},
		} );

		expect( catalogue[ 0 ].identity_changed ).toBe( true );
	} );

	test( 'bulk enable skips products that fail server-derived validation', () => {
		const updated = applyBulkEnabled(
			settings,
			products,
			[ 10, 20 ],
			true
		);

		expect( updated.products[ 10 ].enabled ).toBe( true );
		expect( updated.products[ 20 ] ).toBeUndefined();
	} );

	test( 'detects dirty settings and blocks newly enabled invalid products', () => {
		const draft = updateProductSettings( settings, products[ 1 ], {
			enabled: true,
		} );
		const catalogue = mergeCatalogue( products, draft );

		expect( isSettingsDirty( settings, draft ) ).toBe( true );
		expect( hasBlockingErrors( catalogue, settings, draft ) ).toBe( true );
		expect( isSettingsDirty( settings, settings ) ).toBe( false );
	} );
} );
