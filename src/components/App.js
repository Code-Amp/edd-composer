import {
	Button,
	Notice,
	SnackbarList,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { fetchAdminData, saveSettings } from '../api';
import {
	applyBulkEnabled,
	cloneSettings,
	getVendorError,
	hasBlockingErrors,
	isSettingsDirty,
	mergeCatalogue,
	updateProductSettings,
} from '../utils/settings';
import ProductList from './ProductList';
import RepositorySummary from './RepositorySummary';

const App = () => {
	const [ savedSettings, setSavedSettings ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ products, setProducts ] = useState( [] );
	const [ repository, setRepository ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { createSuccessNotice, createErrorNotice, removeNotice } =
		useDispatch( noticesStore );

	const load = useCallback( async () => {
		setIsLoading( true );
		setLoadError( '' );

		try {
			const [ settingsResponse, productsResponse ] =
				await fetchAdminData();
			const loadedSettings = cloneSettings( settingsResponse.settings );

			setSavedSettings( loadedSettings );
			setSettings( cloneSettings( loadedSettings ) );
			setRepository( settingsResponse.repository );
			setProducts( productsResponse.products );
		} catch ( error ) {
			setLoadError(
				error?.message ||
					__(
						'The administration data could not be loaded.',
						'edd-composer'
					)
			);
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const catalogue = useMemo(
		() => ( settings ? mergeCatalogue( products, settings ) : [] ),
		[ products, settings ]
	);
	const isDirty = Boolean(
		savedSettings && settings && isSettingsDirty( savedSettings, settings )
	);
	const isBlocked = Boolean(
		savedSettings &&
		settings &&
		hasBlockingErrors( catalogue, savedSettings, settings )
	);
	const vendorError = settings ? getVendorError( settings.vendor ) : null;

	useEffect( () => {
		const warnBeforeUnload = ( event ) => {
			if ( ! isDirty ) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warnBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', warnBeforeUnload );
	}, [ isDirty ] );

	const updateProduct = useCallback( ( product, edits ) => {
		setSettings( ( current ) =>
			updateProductSettings( current, product, edits )
		);
	}, [] );

	const updateBulkEnabled = useCallback(
		( selectedProducts, enabled ) => {
			setSettings( ( current ) =>
				applyBulkEnabled(
					current,
					products,
					selectedProducts.map( ( product ) => product.id ),
					enabled
				)
			);
		},
		[ products ]
	);

	const save = async () => {
		setIsSaving( true );

		try {
			const response = await saveSettings( settings );
			const updatedSettings = cloneSettings( response.settings );

			setSavedSettings( updatedSettings );
			setSettings( cloneSettings( updatedSettings ) );
			setRepository( response.repository );
			setProducts( response.products );
			createSuccessNotice(
				__( 'Composer package settings saved.', 'edd-composer' ),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'Composer package settings could not be saved.',
						'edd-composer'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsSaving( false );
		}
	};

	if ( isLoading ) {
		return (
			<div className="edd-composer-loading" aria-live="polite">
				<Spinner />
				<span>
					{ __( 'Loading Composer products…', 'edd-composer' ) }
				</span>
			</div>
		);
	}

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>{ loadError }</p>
				<Button variant="secondary" onClick={ load }>
					{ __( 'Try again', 'edd-composer' ) }
				</Button>
			</Notice>
		);
	}

	return (
		<div className="edd-composer-app">
			<RepositorySummary repository={ repository } />

			<section
				className="edd-composer-card"
				aria-labelledby="product-catalogue-title"
			>
				<div className="edd-composer-card__heading">
					<div>
						<h2 id="product-catalogue-title">
							{ __( 'Product catalogue', 'edd-composer' ) }
						</h2>
						<p>
							{ __(
								'Choose which published Downloads become Composer packages and configure their package metadata.',
								'edd-composer'
							) }
						</p>
					</div>
					<div className="edd-composer-vendor">
						<TextControl
							label={ __( 'Composer vendor', 'edd-composer' ) }
							value={ settings.vendor }
							onChange={ ( vendor ) =>
								setSettings( ( current ) => ( {
									...current,
									vendor,
								} ) )
							}
							help={
								vendorError ||
								__(
									'Used before the slash in every package name.',
									'edd-composer'
								)
							}
							className={ vendorError ? 'has-error' : '' }
							__nextHasNoMarginBottom
						/>
					</div>
				</div>

				{ catalogue.some( ( product ) => product.identity_changed ) && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'One or more enabled package identities have changed. Existing Composer consumers will need the new package name.',
							'edd-composer'
						) }
					</Notice>
				) }

				<ProductList
					products={ catalogue }
					vendor={ settings.vendor }
					onUpdateProduct={ updateProduct }
					onBulkEnabled={ updateBulkEnabled }
				/>
			</section>

			<div className="edd-composer-save-bar">
				<span aria-live="polite">
					{ isDirty
						? __( 'You have unsaved changes.', 'edd-composer' )
						: __( 'All changes are saved.', 'edd-composer' ) }
				</span>
				<div>
					<Button
						variant="tertiary"
						disabled={ ! isDirty || isSaving }
						onClick={ () =>
							setSettings( cloneSettings( savedSettings ) )
						}
					>
						{ __( 'Discard changes', 'edd-composer' ) }
					</Button>
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ ! isDirty || isSaving || isBlocked }
						onClick={ save }
					>
						{ isSaving
							? __( 'Saving…', 'edd-composer' )
							: __( 'Save settings', 'edd-composer' ) }
					</Button>
				</div>
			</div>

			<SnackbarList notices={ notices } onRemove={ removeNotice } />
		</div>
	);
};

export default App;
