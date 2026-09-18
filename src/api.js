import apiFetch from '@wordpress/api-fetch';

const API_BASE = '/edd-composer/v1';

/**
 * Loads settings, repository status, and the EDD product catalogue.
 *
 * @return {Promise<Object>} Administration bootstrap response.
 */
export const fetchAdminData = () =>
	apiFetch( { path: `${ API_BASE }/settings` } );

/**
 * Persists the complete settings document.
 *
 * @param {Object} settings Settings document.
 * @return {Promise<Object>} Updated settings, repository, and products.
 */
export const saveSettings = ( settings ) =>
	apiFetch( {
		path: `${ API_BASE }/settings`,
		method: 'POST',
		data: settings,
	} );
