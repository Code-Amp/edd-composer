import { Button, Notice } from '@wordpress/components';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getPackageSlugError, getPhpConstraintError } from '../utils/settings';

const ProductSettings = ( { product, vendor, onApply, onCancel } ) => {
	const [ draft, setDraft ] = useState( {
		package_slug: product.package_slug,
		type: product.type,
		description: product.description,
		require_php: product.require_php,
	} );
	const fields = useMemo(
		() => [
			{
				id: 'package_slug',
				type: 'text',
				label: __( 'Package slug', 'edd-composer' ),
				description: __(
					'The package segment used after the store-wide vendor.',
					'edd-composer'
				),
				isValid: {
					required: true,
					custom: ( item ) =>
						getPackageSlugError( item.package_slug ),
				},
			},
			{
				id: 'type',
				type: 'text',
				label: __( 'Package type', 'edd-composer' ),
				elements: [
					{
						value: 'wordpress-plugin',
						label: __( 'WordPress plugin', 'edd-composer' ),
					},
				],
				isValid: { required: true, elements: true },
			},
			{
				id: 'description',
				type: 'text',
				Edit: { control: 'textarea', rows: 4 },
				label: __( 'Description', 'edd-composer' ),
			},
			{
				id: 'require_php',
				type: 'text',
				label: __( 'Required PHP version', 'edd-composer' ),
				description: __(
					'A Composer constraint describing the distributed plugin.',
					'edd-composer'
				),
				isValid: {
					required: true,
					custom: ( item ) =>
						getPhpConstraintError( item.require_php ),
				},
			},
		],
		[]
	);
	const form = {
		layout: { type: 'regular', labelPosition: 'top' },
		fields: [ 'package_slug', 'type', 'description', 'require_php' ],
	};
	const { validity, isValid } = useFormValidity( draft, fields, form );
	const identityChanged =
		product.saved_enabled &&
		draft.package_slug !== product.saved_package_slug;

	return (
		<div className="edd-composer-product-settings">
			<p className="edd-composer-package-preview">
				{ sprintf(
					/* translators: %s: Derived Composer package name. */
					__( 'Package name: %s', 'edd-composer' ),
					`${ vendor }/${ draft.package_slug }`
				) }
			</p>
			{ identityChanged && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Changing an enabled package slug changes its public Composer identity.',
						'edd-composer'
					) }
				</Notice>
			) }
			<DataForm
				data={ draft }
				fields={ fields }
				form={ form }
				validity={ validity }
				onChange={ ( edits ) =>
					setDraft( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<div className="edd-composer-modal-actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'edd-composer' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ ! isValid }
					onClick={ () => onApply( draft ) }
				>
					{ __( 'Apply changes', 'edd-composer' ) }
				</Button>
			</div>
		</div>
	);
};

export default ProductSettings;
