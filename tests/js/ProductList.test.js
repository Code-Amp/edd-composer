import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';

import ProductList from '../../src/components/ProductList';

jest.mock( '@wordpress/dataviews/wp', () => ( {
	filterSortAndPaginate: ( data ) => ( {
		data,
		paginationInfo: { totalItems: data.length, totalPages: 1 },
	} ),
	DataViews: ( { actions, data, fields } ) => {
		const enabledField = fields.find( ( field ) => field.id === 'enabled' );

		return (
			<div>
				{ enabledField.render( { item: data[ 0 ] } ) }
				<button
					onClick={ () =>
						actions
							.find( ( action ) => action.id === 'configure' )
							.callback( [ data[ 0 ] ], {} )
					}
				>
					Configure
				</button>
				<button
					onClick={ () =>
						actions
							.find( ( action ) => action.id === 'enable' )
							.callback( [ data[ 0 ] ], {} )
					}
				>
					Bulk enable
				</button>
			</div>
		);
	},
} ) );

jest.mock( '@wordpress/components', () => ( {
	Modal: ( { children, onRequestClose, title } ) => (
		<dialog
			aria-label={ title }
			open
			onKeyDown={ ( event ) => {
				if ( event.key === 'Escape' ) {
					onRequestClose();
				}
			} }
			tabIndex="-1"
		>
			{ children }
		</dialog>
	),
	TextControl: () => null,
	ToggleControl: ( { checked, disabled, label, onChange } ) => (
		<label htmlFor={ `toggle-${ label }` }>
			{ label }
			<input
				aria-label={ label }
				checked={ checked }
				disabled={ disabled }
				id={ `toggle-${ label }` }
				onChange={ ( event ) => onChange( event.target.checked ) }
				type="checkbox"
			/>
		</label>
	),
} ) );

jest.mock( '../../src/components/ProductSettings', () => ( { onApply } ) => (
	<button onClick={ () => onApply( { description: 'Updated.' } ) }>
		Apply configuration
	</button>
) );

const product = {
	id: 10,
	title: 'Test product',
	enabled: false,
	can_enable: true,
	package_slug: 'test-product',
	package_name: 'vendor/test-product',
	status: 'publish',
	status_label: 'Published',
	versioned_file_count: 1,
	validation_messages: [],
};

describe( 'product catalogue behavior', () => {
	test( 'supports accessible enable and bulk actions', async () => {
		const user = userEvent.setup();
		const onUpdateProduct = jest.fn();
		const onBulkEnabled = jest.fn();
		render(
			<ProductList
				products={ [ product ] }
				vendor="vendor"
				onUpdateProduct={ onUpdateProduct }
				onBulkEnabled={ onBulkEnabled }
			/>
		);

		await user.click(
			screen.getByRole( 'checkbox', {
				name: 'Publish Test product as a Composer package',
			} )
		);
		expect( onUpdateProduct ).toHaveBeenCalledWith( product, {
			enabled: true,
		} );

		await user.click(
			screen.getByRole( 'button', { name: 'Bulk enable' } )
		);
		expect( onBulkEnabled ).toHaveBeenCalledWith( [ product ], true );
	} );

	test( 'opens, applies, and keyboard-closes the configuration modal', async () => {
		const user = userEvent.setup();
		const onUpdateProduct = jest.fn();
		render(
			<ProductList
				products={ [ product ] }
				vendor="vendor"
				onUpdateProduct={ onUpdateProduct }
				onBulkEnabled={ jest.fn() }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Configure' } ) );
		let dialog = screen.getByRole( 'dialog', {
			name: 'Configure Test product',
		} );
		expect( dialog ).toBeInTheDocument();
		await user.keyboard( '{Escape}' );
		if ( screen.queryByRole( 'dialog' ) ) {
			dialog.focus();
			await user.keyboard( '{Escape}' );
		}
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();

		await user.click( screen.getByRole( 'button', { name: 'Configure' } ) );
		dialog = screen.getByRole( 'dialog' );
		await user.click(
			within( dialog ).getByRole( 'button', {
				name: 'Apply configuration',
			} )
		);
		expect( onUpdateProduct ).toHaveBeenCalledWith( product, {
			description: 'Updated.',
		} );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
