import { __, sprintf } from '@wordpress/i18n';

/**
 * Gets the products currently exposed by the saved repository configuration.
 *
 * @param {Array} products Saved product catalogue.
 * @return {Array} Published products sorted by package name.
 */
export const getPublishedPackages = ( products ) =>
	( Array.isArray( products ) ? products : [] )
		.filter(
			( product ) =>
				product.enabled && product.can_enable && product.package_name
		)
		.sort( ( first, second ) =>
			first.package_name.localeCompare( second.package_name )
		);

/**
 * Builds the shared values used by the guide preview and Markdown handoff.
 *
 * @param {Object} options                Guide options.
 * @param {string} options.repositoryName Saved repository title.
 * @param {string} options.repositoryUrl  Public Composer repository URL.
 * @param {Array}  options.products       Saved product catalogue.
 * @return {Object} Normalized installation-guide data.
 */
export const getInstallationGuideData = ( {
	repositoryName,
	repositoryUrl,
	products,
} ) => {
	const repositoryHost = new URL( repositoryUrl ).host;
	const publishedPackages = getPublishedPackages( products );
	const packageNames = publishedPackages.map(
		( product ) => product.package_name
	);
	const authentication = {
		'http-basic': {
			[ repositoryHost ]: {
				username: 'your-license-key',
				password: 'https://your-site.example',
			},
		},
	};

	return {
		repositoryName:
			String( repositoryName )
				.replace( /[\r\n]+/g, ' ' )
				.trim() || __( 'Composer packages', 'edd-composer' ),
		repositoryUrl,
		repositoryHost,
		publishedPackages,
		authenticationJson: JSON.stringify( authentication, null, 2 ),
		composerAuth: JSON.stringify( authentication ),
		repositoryCommand: `composer config repositories.edd-composer composer ${ repositoryUrl }`,
		authenticationCommand: `composer config --auth http-basic.${ repositoryHost } your-license-key https://your-site.example`,
		installerCommand:
			'composer config allow-plugins.composer/installers true',
		installCommand: `composer require ${ packageNames.join( ' ' ) }`,
	};
};

/**
 * Formats a customer-ready Markdown installation guide.
 *
 * @param {Object} data Normalized installation-guide data.
 * @return {string} Markdown handoff.
 */
export const buildInstallationGuide = ( data ) => {
	const packageList = data.publishedPackages
		.map(
			( product ) =>
				`- \`${ product.package_name }\` — ${ product.title }`
		)
		.join( '\n' );

	return `# ${ sprintf(
		/* translators: %s: Composer repository title. */
		__( 'Install packages from %s with Composer', 'edd-composer' ),
		data.repositoryName
	) }

${ __(
	'This repository provides licensed WordPress plugins as Composer packages. Follow these steps in the root of your Composer project.',
	'edd-composer'
) }

## ${ __( 'Prerequisites', 'edd-composer' ) }

- ${ __( 'Composer 2 is installed.', 'edd-composer' ) }
- ${ __( 'You have a valid license key for the packages you need.', 'edd-composer' ) }
- ${ __( 'The website URL used below is activated against that license.', 'edd-composer' ) }

## 1. ${ __( 'Add the repository', 'edd-composer' ) }

\`\`\`sh
${ data.repositoryCommand }
\`\`\`

## 2. ${ __( 'Configure authentication', 'edd-composer' ) }

${ __(
	'Create `auth.json` beside `composer.json`. Use the license key as the username and its activated website URL as the password:',
	'edd-composer'
) }

\`\`\`json
${ data.authenticationJson }
\`\`\`

${ __(
	'Keep `auth.json` private and add it to `.gitignore`. You can create the same project-level configuration with:',
	'edd-composer'
) }

\`\`\`sh
${ data.authenticationCommand }
\`\`\`

## 3. ${ __( 'Install packages', 'edd-composer' ) }

${ __( 'Packages currently available from this repository:', 'edd-composer' ) }

${ packageList }

${ __(
	'Allow the standard Composer installer used by WordPress packages, then require the packages:',
	'edd-composer'
) }

\`\`\`sh
${ data.installerCommand }
${ data.installCommand }
\`\`\`

${ __(
	'Commit `composer.json` and `composer.lock`, but never commit `auth.json` or a license key.',
	'edd-composer'
) }

## ${ __( 'CI/CD authentication', 'edd-composer' ) }

${ __(
	'Store the following JSON as a protected secret named `COMPOSER_AUTH`, replacing both placeholders:',
	'edd-composer'
) }

\`\`\`json
${ data.composerAuth }
\`\`\`

${ __(
	'Expose that secret as the `COMPOSER_AUTH` environment variable when running `composer install`.',
	'edd-composer'
) }

## ${ __( 'Troubleshooting', 'edd-composer' ) }

- **401 Unauthorized:** ${ __(
		'The credentials are missing or invalid. Check the license key and activated website URL.',
		'edd-composer'
	) }
- **403 Forbidden:** ${ __(
		'The license cannot download the requested package, is unavailable, or is not activated for the supplied website URL.',
		'edd-composer'
	) }
- **${ __( 'Package or version unavailable', 'edd-composer' ) }:** ${ __(
		'Check the package name and choose a version published by the repository.',
		'edd-composer'
	) }
`;
};
