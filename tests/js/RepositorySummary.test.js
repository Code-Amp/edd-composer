import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';

import RepositorySummary from '../../src/components/RepositorySummary';

jest.mock( '@wordpress/components', () => {
	const { forwardRef } = jest.requireActual( 'react' );

	return {
		Button: forwardRef( ( { children, ...props }, ref ) => (
			<button ref={ ref } { ...props }>
				{ children }
			</button>
		) ),
		Modal: ( { children, onRequestClose, title } ) => (
			<div role="dialog" aria-label={ title }>
				<button onClick={ onRequestClose }>Dismiss modal</button>
				{ children }
			</div>
		),
	};
} );

const repository = {
	url: 'https://packages.example.org/composer',
	package_count: 2,
	cache_state: 'warm',
};
const products = [
	{
		id: 10,
		title: 'Example plugin',
		enabled: true,
		can_enable: true,
		package_name: 'example/example-plugin',
	},
	{
		id: 11,
		title: 'Hidden plugin',
		enabled: false,
		can_enable: true,
		package_name: 'example/hidden-plugin',
	},
];

describe( 'repository summary', () => {
	test( 'copies quick setup values using the WordPress clipboard hook', async () => {
		const user = userEvent.setup();
		const writeText = jest
			.fn()
			.mockResolvedValueOnce()
			.mockRejectedValueOnce( new Error( 'Clipboard unavailable.' ) );
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		render(
			<RepositorySummary
				repository={ repository }
				repositoryName="Example packages"
				products={ products }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Copy URL' } ) );
		expect( writeText ).toHaveBeenCalledWith( repository.url );
		expect(
			screen.getByRole( 'button', { name: 'Copied' } )
		).toBeInTheDocument();

		await user.click(
			screen.getAllByRole( 'button', { name: 'Copy' } )[ 0 ]
		);
		await waitFor( () => expect( writeText ).toHaveBeenCalledTimes( 2 ) );
		expect(
			screen.getAllByRole( 'button', { name: 'Copy' } )[ 0 ]
		).toBeInTheDocument();
	} );

	test( 'previews and copies the saved customer installation guide', async () => {
		const user = userEvent.setup();
		const writeText = jest.fn().mockResolvedValue();
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		render(
			<RepositorySummary
				repository={ repository }
				repositoryName="Example packages"
				products={ products }
			/>
		);

		await user.click(
			screen.getByRole( 'button', {
				name: 'Share installation guide',
			} )
		);
		const modal = screen.getByRole( 'dialog', {
			name: 'Customer installation guide',
		} );

		expect( modal ).toHaveTextContent( 'example/example-plugin' );
		expect( modal ).not.toHaveTextContent( 'example/hidden-plugin' );
		await user.click(
			screen.getByRole( 'button', { name: 'Copy Markdown' } )
		);
		await waitFor( () => expect( writeText ).toHaveBeenCalledTimes( 1 ) );
		expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain(
			'# Install packages from Example packages with Composer'
		);
		expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain(
			'composer require example/example-plugin'
		);
		expect(
			screen.getByRole( 'button', { name: 'Copied' } )
		).toBeInTheDocument();
	} );

	test( 'requires a published package before sharing a guide', () => {
		render(
			<RepositorySummary
				repository={ repository }
				repositoryName="Example packages"
				products={ [] }
			/>
		);

		expect(
			screen.getByRole( 'button', {
				name: 'Share installation guide',
			} )
		).toBeDisabled();
		expect(
			screen.getByText( /Publish at least one valid package/ )
		).toBeInTheDocument();
	} );

	test( 'copies through the WordPress fallback without the Clipboard API', async () => {
		const user = userEvent.setup();
		const execCommand = jest.fn().mockReturnValue( true );
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: undefined,
		} );
		Object.defineProperty( document, 'execCommand', {
			configurable: true,
			value: execCommand,
		} );
		render(
			<RepositorySummary
				repository={ repository }
				repositoryName="Example packages"
				products={ products }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Copy URL' } ) );
		await waitFor( () =>
			expect( execCommand ).toHaveBeenCalledWith( 'copy' )
		);
		expect(
			screen.getByRole( 'button', { name: 'Copied' } )
		).toBeInTheDocument();
	} );
} );
