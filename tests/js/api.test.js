import apiFetch from '@wordpress/api-fetch';

import { fetchAdminData, saveSettings } from '../../src/api';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'admin REST transport', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	test( 'loads settings and products through plugin-owned routes', async () => {
		apiFetch
			.mockResolvedValueOnce( { settings: {} } )
			.mockResolvedValueOnce( { products: [] } );

		await expect( fetchAdminData() ).resolves.toEqual( [
			{ settings: {} },
			{ products: [] },
		] );
		expect( apiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/edd-composer/v1/settings',
		} );
		expect( apiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/edd-composer/v1/products',
		} );
	} );

	test( 'sends the complete settings document when saving', async () => {
		const settings = {
			schema_version: 1,
			repository_name: 'Code Amp Packages',
			vendor: 'code-amp',
			products: {},
		};
		apiFetch.mockResolvedValue( { settings } );

		await saveSettings( settings );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/edd-composer/v1/settings',
			method: 'POST',
			data: settings,
		} );
	} );

	test( 'passes failed requests back to the application', async () => {
		const error = new Error( 'REST request failed.' );
		apiFetch.mockRejectedValue( error );

		await expect( fetchAdminData() ).rejects.toBe( error );
	} );
} );
