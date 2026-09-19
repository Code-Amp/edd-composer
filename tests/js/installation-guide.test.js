import {
	buildInstallationGuide,
	getInstallationGuideData,
	getPublishedPackages,
} from '../../src/utils/installation-guide';

const products = [
	{
		id: 1,
		title: 'Second plugin',
		enabled: true,
		can_enable: true,
		package_name: 'vendor/second',
	},
	{
		id: 2,
		title: 'Disabled plugin',
		enabled: false,
		can_enable: true,
		package_name: 'vendor/disabled',
	},
	{
		id: 3,
		title: 'Invalid plugin',
		enabled: true,
		can_enable: false,
		package_name: 'vendor/invalid',
	},
	{
		id: 4,
		title: 'First plugin',
		enabled: true,
		can_enable: true,
		package_name: 'vendor/first',
	},
];

describe( 'customer installation guide', () => {
	test( 'includes only valid published packages in stable order', () => {
		expect(
			getPublishedPackages( products ).map(
				( product ) => product.package_name
			)
		).toEqual( [ 'vendor/first', 'vendor/second' ] );
	} );

	test( 'builds a credential-safe copyable Markdown handoff', () => {
		const data = getInstallationGuideData( {
			repositoryName: 'Example Packages\n',
			repositoryUrl: 'https://packages.example.org/composer',
			products,
		} );
		const markdown = buildInstallationGuide( data );

		expect( data.repositoryName ).toBe( 'Example Packages' );
		expect( data.installCommand ).toBe(
			'composer require vendor/first vendor/second'
		);
		expect( markdown ).toContain(
			'composer config repositories.edd-composer composer https://packages.example.org/composer'
		);
		expect( markdown ).toContain(
			'composer config --auth http-basic.packages.example.org your-license-key https://your-site.example'
		);
		expect( markdown ).toContain( '"username": "your-license-key"' );
		expect( markdown ).toContain( 'COMPOSER_AUTH' );
		expect( markdown ).toContain( '401 Unauthorized' );
		expect( markdown ).toContain( '403 Forbidden' );
		expect( markdown ).not.toContain( 'vendor/disabled' );
		expect( markdown ).not.toContain( 'vendor/invalid' );
	} );
} );
