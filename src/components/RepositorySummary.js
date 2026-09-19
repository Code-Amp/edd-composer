import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getPublishedPackages } from '../utils/installation-guide';
import CopyButton from './CopyButton';
import InstallationGuideModal from './InstallationGuideModal';

const RepositorySummary = ( { repository, repositoryName, products } ) => {
	const [ isGuideOpen, setIsGuideOpen ] = useState( false );
	const repositoryUrl = new URL( repository.url );
	const repositoryCommand = `composer config repositories.edd-composer composer ${ repository.url }`;
	const authenticationCommand = `composer config --auth http-basic.${ repositoryUrl.host } your-license-key https://your-site.example`;
	const publishedPackages = getPublishedPackages( products );
	const canShareGuide = publishedPackages.length > 0;

	return (
		<section
			className="edd-composer-card"
			aria-labelledby="repository-title"
		>
			<div className="edd-composer-card__heading">
				<div>
					<h2 id="repository-title">
						{ __( 'Composer repository', 'edd-composer' ) }
					</h2>
					<p>
						{ __(
							'Add this repository and authenticate with an EDD license key and its activated site URL.',
							'edd-composer'
						) }
					</p>
				</div>
				<dl className="edd-composer-summary">
					<div>
						<dt>{ __( 'Packages', 'edd-composer' ) }</dt>
						<dd>{ repository.package_count }</dd>
					</div>
					<div>
						<dt>{ __( 'Index cache', 'edd-composer' ) }</dt>
						<dd>{ repository.cache_state }</dd>
					</div>
				</dl>
			</div>

			<div className="edd-composer-command edd-composer-command--repository">
				<code>{ repository.url }</code>
				<div className="edd-composer-command__actions">
					<CopyButton text={ repository.url } variant="secondary">
						{ __( 'Copy URL', 'edd-composer' ) }
					</CopyButton>
					<Button
						variant="secondary"
						disabled={ ! canShareGuide }
						aria-describedby="edd-composer-guide-description"
						onClick={ () => setIsGuideOpen( true ) }
					>
						{ __( 'Share installation guide', 'edd-composer' ) }
					</Button>
				</div>
			</div>
			<p
				id="edd-composer-guide-description"
				className="description edd-composer-guide-description"
			>
				{ canShareGuide
					? sprintf(
							/* translators: %d: Number of published packages. */
							_n(
								'Create a customer-ready Markdown guide for %d published package.',
								'Create a customer-ready Markdown guide for %d published packages.',
								publishedPackages.length,
								'edd-composer'
							),
							publishedPackages.length
						)
					: __(
							'Publish at least one valid package to create a customer installation guide.',
							'edd-composer'
						) }
			</p>

			<details>
				<summary>
					{ __( 'Composer setup commands', 'edd-composer' ) }
				</summary>
				<p>
					{ __(
						'Run these locally. Replace the placeholders without committing credentials to composer.json.',
						'edd-composer'
					) }
				</p>
				<div className="edd-composer-command">
					<code>{ repositoryCommand }</code>
					<CopyButton text={ repositoryCommand } variant="secondary">
						{ __( 'Copy', 'edd-composer' ) }
					</CopyButton>
				</div>
				<div className="edd-composer-command">
					<code>{ authenticationCommand }</code>
					<CopyButton
						text={ authenticationCommand }
						variant="secondary"
					>
						{ __( 'Copy', 'edd-composer' ) }
					</CopyButton>
				</div>
				<p className="description">
					{ sprintf(
						/* translators: %s: Repository hostname. */
						__(
							'Authentication is stored for %s.',
							'edd-composer'
						),
						repositoryUrl.host
					) }
				</p>
			</details>

			{ isGuideOpen && (
				<InstallationGuideModal
					repositoryName={ repositoryName }
					repositoryUrl={ repository.url }
					products={ publishedPackages }
					onClose={ () => setIsGuideOpen( false ) }
				/>
			) }
		</section>
	);
};

export default RepositorySummary;
