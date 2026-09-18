import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';

import { fetchAdminData, saveSettings } from '../../src/api';
import App from '../../src/components/App';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockRemoveNotice = jest.fn();

jest.mock( '../../src/api', () => ( {
	fetchAdminData: jest.fn(),
	saveSettings: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: () => [],
	useDispatch: () => ( {
		createSuccessNotice: mockCreateSuccessNotice,
		createErrorNotice: mockCreateErrorNotice,
		removeNotice: mockRemoveNotice,
	} ),
} ) );

jest.mock( '@wordpress/notices', () => ( {
	store: {},
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, isBusy, ...props } ) => (
		<button aria-busy={ isBusy || undefined } { ...props }>
			{ children }
		</button>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	SnackbarList: () => null,
	Spinner: () => <span role="progressbar">Loading</span>,
	TextControl: ( { label, onChange, value } ) => (
		<label htmlFor={ `field-${ label }` }>
			{ label }
			<input
				aria-label={ label }
				id={ `field-${ label }` }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</label>
	),
} ) );

jest.mock( '../../src/components/RepositorySummary', () => () => (
	<div>Repository summary</div>
) );

jest.mock(
	'../../src/components/ProductList',
	() =>
		( { products, onUpdateProduct, onBulkEnabled } ) => (
			<div>
				<button
					onClick={ () =>
						onUpdateProduct( products[ 0 ], {
							enabled: ! products[ 0 ].enabled,
						} )
					}
				>
					Toggle product
				</button>
				<button
					onClick={ () => onBulkEnabled( [ products[ 0 ] ], true ) }
				>
					Bulk enable
				</button>
			</div>
		)
);

const bootstrap = {
	settings: {
		schema_version: 1,
		repository_name: 'Test repository',
		vendor: 'test-vendor',
		products: {},
	},
	repository: {
		url: 'https://example.org/composer',
		package_count: 0,
		cache_state: 'empty',
	},
	products: [
		{
			id: 10,
			title: 'Test product',
			default_package_slug: 'test-product',
			default_description: 'Test product.',
			enabled: false,
			package_slug: 'test-product',
			can_enable: true,
			file_validation_messages: [],
		},
	],
};

describe( 'administration application behavior', () => {
	beforeEach( () => {
		fetchAdminData.mockReset();
		saveSettings.mockReset();
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
	} );

	test( 'shows loading and recoverable REST error states', async () => {
		const user = userEvent.setup();
		fetchAdminData
			.mockRejectedValueOnce( new Error( 'Could not load.' ) )
			.mockResolvedValueOnce( bootstrap );

		render( <App /> );

		expect( screen.getByRole( 'progressbar' ) ).toBeInTheDocument();
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Could not load.'
		);
		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect(
			await screen.findByRole( 'heading', { name: 'Product catalogue' } )
		).toBeInTheDocument();
	} );

	test( 'supports enable, discard, and save without duplicate submissions', async () => {
		const user = userEvent.setup();
		let resolveSave;
		const pendingSave = new Promise( ( resolve ) => {
			resolveSave = resolve;
		} );
		fetchAdminData.mockResolvedValue( bootstrap );
		saveSettings.mockReturnValue( pendingSave );
		render( <App /> );
		await screen.findByRole( 'heading', { name: 'Product catalogue' } );

		await user.click(
			screen.getByRole( 'button', { name: 'Toggle product' } )
		);
		expect(
			screen.getByText( 'You have unsaved changes.' )
		).toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'Discard changes' } )
		);
		expect(
			screen.getByText( 'All changes are saved.' )
		).toBeInTheDocument();

		await user.click(
			screen.getByRole( 'button', { name: 'Bulk enable' } )
		);
		const saveButton = screen.getByRole( 'button', {
			name: 'Save settings',
		} );
		fireEvent.click( saveButton );
		fireEvent.click( saveButton );
		expect( saveSettings ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'button', { name: 'Saving…' } )
		).toBeDisabled();

		resolveSave( {
			...bootstrap,
			settings: saveSettings.mock.calls[ 0 ][ 0 ],
		} );
		await waitFor( () =>
			expect( mockCreateSuccessNotice ).toHaveBeenCalledTimes( 1 )
		);
	} );

	test( 'keeps edits made while an earlier save is pending', async () => {
		const user = userEvent.setup();
		let resolveSave;
		fetchAdminData.mockResolvedValue( bootstrap );
		saveSettings.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveSave = resolve;
			} )
		);
		render( <App /> );
		await screen.findByRole( 'heading', { name: 'Product catalogue' } );

		const title = screen.getByRole( 'textbox', {
			name: 'Repository title',
		} );
		await user.clear( title );
		await user.type( title, 'Submitted title' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		const vendor = screen.getByRole( 'textbox', {
			name: 'Composer vendor',
		} );
		await user.clear( vendor );
		await user.type( vendor, 'later-vendor' );
		resolveSave( {
			...bootstrap,
			settings: saveSettings.mock.calls[ 0 ][ 0 ],
		} );

		await waitFor( () =>
			expect(
				screen.getByText( 'You have unsaved changes.' )
			).toBeInTheDocument()
		);
		expect( vendor ).toHaveValue( 'later-vendor' );
	} );
} );
