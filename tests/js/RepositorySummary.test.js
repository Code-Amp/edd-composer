import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';

import RepositorySummary from '../../src/components/RepositorySummary';

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, ...props } ) => (
		<button { ...props }>{ children }</button>
	),
} ) );

const repository = {
	url: 'https://packages.example.org/composer',
	package_count: 2,
	cache_state: 'warm',
};

describe( 'repository summary', () => {
	test( 'copies commands and exposes clipboard failure accessibly', async () => {
		const user = userEvent.setup();
		const writeText = jest
			.fn()
			.mockResolvedValueOnce()
			.mockRejectedValueOnce( new Error( 'Clipboard unavailable.' ) );
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		render( <RepositorySummary repository={ repository } /> );

		await user.click( screen.getByRole( 'button', { name: 'Copy URL' } ) );
		expect( writeText ).toHaveBeenCalledWith( repository.url );
		expect(
			screen.getByRole( 'button', { name: 'Copied' } )
		).toBeInTheDocument();

		await user.click(
			screen.getAllByRole( 'button', { name: 'Copy' } )[ 0 ]
		);
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Could not copy to the clipboard.'
		);
		await waitFor( () => expect( writeText ).toHaveBeenCalledTimes( 2 ) );
	} );
} );
