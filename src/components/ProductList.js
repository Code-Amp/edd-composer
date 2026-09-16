import { Modal, TextControl, ToggleControl } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ProductSettings from './ProductSettings';

const ProductList = ( {
	products,
	vendor,
	onUpdateProduct,
	onBulkEnabled,
} ) => {
	const [ selection, setSelection ] = useState( [] );
	const [ editingProduct, setEditingProduct ] = useState( null );
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 10,
		sort: { field: 'title', direction: 'asc' },
		titleField: 'title',
		fields: [
			'id',
			'status',
			'enabled',
			'package_slug',
			'package_name',
			'versioned_file_count',
			'validation',
		],
		layout: {
			density: 'balanced',
			enableMoving: true,
			styles: {
				id: { width: '70px', minWidth: '70px', maxWidth: '70px' },
				status: { width: '110px', minWidth: '110px' },
				enabled: { width: '90px', minWidth: '90px' },
				package_slug: { width: '220px', minWidth: '220px' },
				package_name: { width: '240px', minWidth: '240px' },
				versioned_file_count: {
					width: '90px',
					minWidth: '90px',
					align: 'end',
				},
				validation: { width: '220px', minWidth: '220px' },
			},
		},
	} );
	const fields = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Product', 'edd-composer' ),
				enableHiding: false,
				enableGlobalSearch: true,
				render: ( { item } ) =>
					item.edit_url ? (
						<a href={ item.edit_url }>{ item.title }</a>
					) : (
						item.title
					),
			},
			{
				id: 'id',
				type: 'integer',
				label: __( 'EDD ID', 'edd-composer' ),
			},
			{
				id: 'status',
				label: __( 'Publication', 'edd-composer' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => item.status_label,
				elements: [
					{
						value: 'publish',
						label: __( 'Published', 'edd-composer' ),
					},
					{ value: 'draft', label: __( 'Draft', 'edd-composer' ) },
					{
						value: 'pending',
						label: __( 'Pending', 'edd-composer' ),
					},
					{
						value: 'private',
						label: __( 'Private', 'edd-composer' ),
					},
					{
						value: 'future',
						label: __( 'Scheduled', 'edd-composer' ),
					},
				],
				filterBy: { operators: [ 'isAny' ] },
			},
			{
				id: 'enabled',
				type: 'boolean',
				label: __( 'Enabled', 'edd-composer' ),
				filterBy: { operators: [ 'is' ] },
				render: ( { item } ) => (
					<ToggleControl
						className="edd-composer-enable-toggle"
						label={ sprintf(
							/* translators: %s: EDD product title. */
							__(
								'Publish %s as a Composer package',
								'edd-composer'
							),
							item.title
						) }
						hideLabelFromVision
						checked={ item.enabled }
						disabled={ ! item.enabled && ! item.can_enable }
						onChange={ ( enabled ) =>
							onUpdateProduct( item, { enabled } )
						}
						__nextHasNoMarginBottom
					/>
				),
			},
			{
				id: 'package_slug',
				label: __( 'Package slug', 'edd-composer' ),
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<TextControl
						label={ sprintf(
							/* translators: %s: EDD product title. */
							__( 'Package slug for %s', 'edd-composer' ),
							item.title
						) }
						hideLabelFromVision
						value={ item.package_slug }
						onChange={ ( packageSlug ) =>
							onUpdateProduct( item, {
								package_slug: packageSlug,
							} )
						}
						__nextHasNoMarginBottom
					/>
				),
			},
			{
				id: 'package_name',
				label: __( 'Package name', 'edd-composer' ),
				enableGlobalSearch: true,
				render: ( { item } ) => <code>{ item.package_name }</code>,
			},
			{
				id: 'versioned_file_count',
				type: 'integer',
				label: __( 'Versions', 'edd-composer' ),
			},
			{
				id: 'validation',
				label: __( 'Validation', 'edd-composer' ),
				enableSorting: false,
				getValue: ( { item } ) => item.validation_messages.join( ' ' ),
				render: ( { item } ) =>
					item.validation_messages.length === 0 ? (
						<span className="edd-composer-status is-valid">
							{ __( 'Ready', 'edd-composer' ) }
						</span>
					) : (
						<span
							className="edd-composer-status is-invalid"
							title={ item.validation_messages.join( ' ' ) }
						>
							{ item.validation_messages[ 0 ] }
						</span>
					),
			},
		],
		[ onUpdateProduct ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'configure',
				label: __( 'Configure', 'edd-composer' ),
				callback: ( items, context ) => {
					setEditingProduct( items[ 0 ] );
					context.onActionPerformed?.( items );
				},
			},
			{
				id: 'enable',
				label: __( 'Enable', 'edd-composer' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.enabled && item.can_enable,
				callback: ( items, context ) => {
					onBulkEnabled( items, true );
					context.onActionPerformed?.( items );
				},
			},
			{
				id: 'disable',
				label: __( 'Disable', 'edd-composer' ),
				supportsBulk: true,
				isEligible: ( item ) => item.enabled,
				callback: ( items, context ) => {
					onBulkEnabled( items, false );
					context.onActionPerformed?.( items );
				},
			},
		],
		[ onBulkEnabled ]
	);
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( products, view, fields ),
		[ products, view, fields ]
	);

	return (
		<>
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ { table: {} } }
				actions={ actions }
				paginationInfo={ paginationInfo }
				selection={ selection }
				onChangeSelection={ setSelection }
				searchLabel={ __( 'Search downloads', 'edd-composer' ) }
				config={ { perPageSizes: [ 10, 20, 50, 100 ] } }
				empty={
					<p>
						{ __(
							'No EDD Downloads match the current view.',
							'edd-composer'
						) }
					</p>
				}
			/>

			{ editingProduct && (
				<Modal
					title={ sprintf(
						/* translators: %s: EDD product title. */
						__( 'Configure %s', 'edd-composer' ),
						editingProduct.title
					) }
					onRequestClose={ () => setEditingProduct( null ) }
					size="medium"
				>
					<ProductSettings
						product={ editingProduct }
						vendor={ vendor }
						onCancel={ () => setEditingProduct( null ) }
						onApply={ ( edits ) => {
							onUpdateProduct( editingProduct, edits );
							setEditingProduct( null );
						} }
					/>
				</Modal>
			) }
		</>
	);
};

export default ProductList;
