const isRecord = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

/**
 * Builds a GitHub Contents API URL pinned to a repository revision.
 *
 * @param {string} repository Repository in owner/name form.
 * @param {string} ref        Commit SHA.
 * @param {string} path       Repository-relative file path.
 * @return {string} Contents API URL.
 */
export const buildGitHubContentsUrl = ( repository, ref, path ) => {
	const repositoryParts = repository.split( '/' );

	if (
		repositoryParts.length !== 2 ||
		repositoryParts.some( ( part ) => ! /^[A-Za-z0-9_.-]+$/.test( part ) )
	) {
		throw new Error( 'The dependency catalogue repository is invalid.' );
	}

	if ( ! /^[a-f0-9]{40}$/.test( ref ) ) {
		throw new Error( 'The dependency catalogue ref must be a commit SHA.' );
	}

	const encodedPath = path
		.split( '/' )
		.map( ( segment ) => encodeURIComponent( segment ) )
		.join( '/' );

	return `https://api.github.com/repos/${ repository }/contents/${ encodedPath }?ref=${ ref }`;
};

/**
 * Selects the source used to install a licensed test plugin.
 *
 * @param {Object}  options                 Source options.
 * @param {string}  options.overrideUrl     Plugin-specific archive URL.
 * @param {string}  options.catalogToken    Private catalogue token.
 * @param {boolean} options.isGitHubActions Whether the command runs in Actions.
 * @return {string|null} Selected source, or null when none is available.
 */
export const selectPluginInstallSource = ( {
	overrideUrl,
	catalogToken,
	isGitHubActions,
} ) => {
	if ( overrideUrl ) {
		return 'url';
	}

	if ( catalogToken ) {
		return 'token';
	}

	return isGitHubActions ? null : 'github-cli';
};

/**
 * Resolves and validates one plugin release from a dependency catalogue.
 *
 * @param {Object} catalog     Parsed dependency catalogue.
 * @param {string} packageSlug Catalogue package slug.
 * @param {string} version     Required plugin version.
 * @param {string} entryFile   Expected WordPress plugin entry filename.
 * @return {Object} Validated release metadata.
 */
export const getCatalogRelease = (
	catalog,
	packageSlug,
	version,
	entryFile
) => {
	const dependency = isRecord( catalog?.packages )
		? catalog.packages[ packageSlug ]
		: null;
	const release = isRecord( dependency?.versions )
		? dependency.versions[ version ]
		: null;
	const expectedFile = `plugins/${ packageSlug }/${ version }/${ packageSlug }-${ version }.zip`;

	if ( dependency?.type !== 'wordpress-plugin' || ! isRecord( release ) ) {
		throw new Error(
			`The dependency catalogue does not contain ${ packageSlug } ${ version }.`
		);
	}

	if (
		release.file !== expectedFile ||
		release.entryFile !== entryFile ||
		! /^[a-f0-9]{64}$/.test( release.sha256 ?? '' )
	) {
		throw new Error(
			`The dependency catalogue metadata for ${ packageSlug } ${ version } is invalid.`
		);
	}

	return release;
};

/**
 * Validates archive entry paths before extraction.
 *
 * @param {string[]} paths Archive entry paths.
 * @return {void}
 */
export const validateArchivePaths = ( paths ) => {
	if ( paths.length === 0 ) {
		throw new Error( 'The plugin archive is empty.' );
	}

	for ( const path of paths ) {
		const normalizedSeparators = path.replaceAll( '\\', '/' );

		if (
			normalizedSeparators.startsWith( '/' ) ||
			/^[A-Za-z]:\//.test( normalizedSeparators ) ||
			normalizedSeparators.split( '/' ).includes( '..' )
		) {
			throw new Error( 'The plugin archive contains an unsafe path.' );
		}
	}
};

/**
 * Finds the unique entry-file path in an archive.
 *
 * @param {string[]} paths     Archive entry paths.
 * @param {string}   entryFile Expected entry filename.
 * @return {string} Archive path to the entry file.
 */
export const findArchiveEntry = ( paths, entryFile ) => {
	const matches = paths.filter( ( path ) => {
		const normalizedSeparators = path.replaceAll( '\\', '/' );

		return (
			normalizedSeparators === entryFile ||
			normalizedSeparators.endsWith( `/${ entryFile }` )
		);
	} );

	if ( matches.length !== 1 ) {
		throw new Error(
			`The plugin archive must contain exactly one ${ entryFile }; found ${ matches.length }.`
		);
	}

	return matches[ 0 ].replaceAll( '\\', '/' );
};

/**
 * Reads the Version header from a WordPress plugin entry file.
 *
 * @param {string} source Plugin entry-file source.
 * @return {string|null} Header version when present.
 */
export const readPluginVersion = ( source ) =>
	source.match( /^[ \t/*#@]*Version:\s*([^\r\n]+)/im )?.[ 1 ]?.trim() ?? null;
