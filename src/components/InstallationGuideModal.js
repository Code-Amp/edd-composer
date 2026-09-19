import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	buildInstallationGuide,
	getInstallationGuideData,
} from '../utils/installation-guide';
import CopyButton from './CopyButton';

const InstallationGuideModal = ( {
	repositoryName,
	repositoryUrl,
	products,
	onClose,
} ) => {
	const guide = getInstallationGuideData( {
		repositoryName,
		repositoryUrl,
		products,
	} );
	const markdown = buildInstallationGuide( guide );

	return (
		<Modal
			title={ __( 'Customer installation guide', 'edd-composer' ) }
			onRequestClose={ onClose }
			size="large"
		>
			<div className="edd-composer-installation-guide">
				<p>
					{ __(
						'Share this guide with customers or developers who need to install your published packages.',
						'edd-composer'
					) }
				</p>

				<h3>{ __( 'Prerequisites', 'edd-composer' ) }</h3>
				<ul>
					<li>
						{ __( 'Composer 2 is installed.', 'edd-composer' ) }
					</li>
					<li>
						{ __(
							'A valid license key includes the required packages.',
							'edd-composer'
						) }
					</li>
					<li>
						{ __(
							'The customer website URL is activated against that license.',
							'edd-composer'
						) }
					</li>
				</ul>

				<h3>{ __( 'Add the repository', 'edd-composer' ) }</h3>
				<pre>
					<code>{ guide.repositoryCommand }</code>
				</pre>

				<h3>{ __( 'Configure authentication', 'edd-composer' ) }</h3>
				<p>
					{ __(
						'Create auth.json beside composer.json. The license key is the username and its activated website URL is the password.',
						'edd-composer'
					) }
				</p>
				<pre>
					<code>{ guide.authenticationJson }</code>
				</pre>
				<p>
					{ __(
						'Or create the same project-level authentication file with:',
						'edd-composer'
					) }
				</p>
				<pre>
					<code>{ guide.authenticationCommand }</code>
				</pre>
				<p>
					<strong>
						{ __(
							'Never commit auth.json or a license key.',
							'edd-composer'
						) }
					</strong>
				</p>

				<h3>{ __( 'Install packages', 'edd-composer' ) }</h3>
				<ul>
					{ guide.publishedPackages.map( ( product ) => (
						<li key={ product.id }>
							<code>{ product.package_name }</code> —{ ' ' }
							{ product.title }
						</li>
					) ) }
				</ul>
				<pre>
					<code>{ `${ guide.installerCommand }\n${ guide.installCommand }` }</code>
				</pre>

				<h3>{ __( 'CI/CD authentication', 'edd-composer' ) }</h3>
				<p>
					{ __(
						'Store the generated authentication JSON as a protected COMPOSER_AUTH secret and expose it when Composer runs.',
						'edd-composer'
					) }
				</p>
				<pre>
					<code>{ guide.composerAuth }</code>
				</pre>

				<h3>{ __( 'Troubleshooting', 'edd-composer' ) }</h3>
				<ul>
					<li>
						<strong>
							{ __( '401 Unauthorized:', 'edd-composer' ) }
						</strong>{ ' ' }
						{ __(
							'Check the license key and activated website URL.',
							'edd-composer'
						) }
					</li>
					<li>
						<strong>
							{ __( '403 Forbidden:', 'edd-composer' ) }
						</strong>{ ' ' }
						{ __(
							'Check package entitlement, license status, and site activation.',
							'edd-composer'
						) }
					</li>
					<li>
						<strong>
							{ __(
								'Package or version unavailable:',
								'edd-composer'
							) }
						</strong>{ ' ' }
						{ __(
							'Check the package name and choose a published version.',
							'edd-composer'
						) }
					</li>
				</ul>

				<div className="edd-composer-installation-guide__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close', 'edd-composer' ) }
					</Button>
					<CopyButton text={ markdown } variant="primary">
						{ __( 'Copy Markdown', 'edd-composer' ) }
					</CopyButton>
				</div>
			</div>
		</Modal>
	);
};

export default InstallationGuideModal;
