import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const RepositorySummary = ( { repository } ) => {
	const [ copied, setCopied ] = useState( '' );
	const repositoryUrl = new URL( repository.url );
	const repositoryCommand = `composer config repositories.edd-composer composer ${ repository.url }`;
	const authenticationCommand = `composer config --global --auth http-basic.${ repositoryUrl.host } <license-key> <activated-site-url>`;

	const copy = async ( value, key ) => {
		await navigator.clipboard.writeText( value );
		setCopied( key );
		window.setTimeout( () => setCopied( '' ), 2000 );
	};

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

			<div className="edd-composer-command">
				<code>{ repository.url }</code>
				<Button
					variant="secondary"
					onClick={ () => copy( repository.url, 'url' ) }
				>
					{ copied === 'url'
						? __( 'Copied', 'edd-composer' )
						: __( 'Copy URL', 'edd-composer' ) }
				</Button>
			</div>

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
					<Button
						variant="secondary"
						onClick={ () =>
							copy( repositoryCommand, 'repository' )
						}
					>
						{ copied === 'repository'
							? __( 'Copied', 'edd-composer' )
							: __( 'Copy', 'edd-composer' ) }
					</Button>
				</div>
				<div className="edd-composer-command">
					<code>{ authenticationCommand }</code>
					<Button
						variant="secondary"
						onClick={ () =>
							copy( authenticationCommand, 'authentication' )
						}
					>
						{ copied === 'authentication'
							? __( 'Copied', 'edd-composer' )
							: __( 'Copy', 'edd-composer' ) }
					</Button>
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
		</section>
	);
};

export default RepositorySummary;
