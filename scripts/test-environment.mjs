/* eslint-disable no-console */

import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const configPath = resolve( projectDirectory, '.wp-env.tests.json' );
const setupMarker = '/var/www/html/.edd-composer-test-environment';
const dependencies = [
	{
		name: 'Easy Digital Downloads Pro',
		path: 'wp-env/plugins/easy-digital-downloads-pro/easy-digital-downloads.php',
		version: '3.7.0',
	},
	{
		name: 'EDD Software Licensing',
		path: 'wp-env/plugins/edd-software-licensing/edd-software-licenses.php',
		version: '3.9.7',
	},
];

const runProcess = (
	executable,
	args,
	{ allowFailure = false, capture = false } = {}
) =>
	new Promise( ( resolveProcess, reject ) => {
		const child = spawn( executable, args, {
			cwd: projectDirectory,
			env: process.env,
			stdio: capture ? [ 'ignore', 'pipe', 'pipe' ] : 'inherit',
		} );
		let stdout = '';
		let stderr = '';

		if ( capture ) {
			child.stdout.setEncoding( 'utf8' );
			child.stderr.setEncoding( 'utf8' );
			child.stdout.on( 'data', ( chunk ) => {
				stdout += chunk;
			} );
			child.stderr.on( 'data', ( chunk ) => {
				stderr += chunk;
			} );
		}

		child.on( 'error', reject );
		child.on( 'exit', ( code, signal ) => {
			const result = { code: code ?? 1, signal, stderr, stdout };

			if ( code === 0 || allowFailure ) {
				resolveProcess( result );
				return;
			}

			reject(
				new Error(
					signal
						? `${ executable } exited after signal ${ signal }.`
						: `${ executable } exited with code ${ code }.`
				)
			);
		} );
	} );

const runPnpm = ( args, options = {} ) => {
	const pnpmCliPath = process.env.npm_execpath;
	let executable = process.platform === 'win32' ? 'pnpm.cmd' : 'pnpm';
	let executableArgs = args;

	if ( pnpmCliPath ) {
		if ( /\.(?:c?js|mjs)$/.test( pnpmCliPath ) ) {
			executable = process.execPath;
			executableArgs = [ pnpmCliPath, ...args ];
		} else {
			executable = pnpmCliPath;
		}
	}

	return runProcess( executable, executableArgs, options );
};

const runWpEnv = ( args, options = {} ) =>
	runPnpm(
		[
			'--dir',
			projectDirectory,
			'exec',
			'wp-env',
			`--config=${ configPath }`,
			...args,
		],
		options
	);

const validateDependencies = () => {
	const problems = [];

	for ( const dependency of dependencies ) {
		const pluginFile = resolve( projectDirectory, dependency.path );
		let source;

		try {
			source = readFileSync( pluginFile, 'utf8' );
		} catch {
			problems.push(
				`${ dependency.name } is missing at ${ dependency.path }.`
			);
			continue;
		}

		const version = source.match( /^ \* Version:\s*([^\s]+)/m )?.[ 1 ];

		if ( version !== dependency.version ) {
			problems.push(
				`${ dependency.name } must be ${ dependency.version }; found ${
					version ?? 'no plugin version'
				}.`
			);
		}
	}

	if ( problems.length === 0 ) {
		return;
	}

	throw new Error(
		[
			'The wp-env test profile is not ready:',
			...problems.map( ( problem ) => `- ${ problem }` ),
			'Extract the pinned plugin ZIPs into wp-env/plugins and try again.',
		].join( '\n' )
	);
};

const getEnvironmentStatus = async () => {
	const result = await runWpEnv( [ 'status', '--json' ], {
		allowFailure: true,
		capture: true,
	} );
	const jsonLine = result.stdout
		.split( /\r?\n/ )
		.findLast( ( line ) => line.trim().startsWith( '{' ) );

	if ( result.code !== 0 || ! jsonLine ) {
		return 'unknown';
	}

	try {
		return JSON.parse( jsonLine ).status ?? 'unknown';
	} catch {
		return 'unknown';
	}
};

const getSetupVersion = () =>
	createHash( 'sha256' )
		.update( 'edd-composer-test-setup-v2\0' )
		.update( readFileSync( configPath ) )
		.update(
			readFileSync(
				resolve( projectDirectory, 'wp-env/phpunit/composer.lock' )
			)
		)
		.digest( 'hex' )
		.slice( 0, 16 );

const getProvisionedVersion = async () => {
	const marker = await runWpEnv(
		[
			'run',
			'cli',
			'php',
			'-r',
			`if ( is_readable( '${ setupMarker }' ) ) { echo trim( file_get_contents( '${ setupMarker }' ) ); }`,
		],
		{ allowFailure: true, capture: true }
	);

	if ( marker.code !== 0 ) {
		return null;
	}

	return marker.stdout.trim() || null;
};

const provisionEnvironment = async ( expectedVersion ) => {
	console.log( 'Provisioning the wp-env test environment...' );
	await runWpEnv( [
		'run',
		'cli',
		'composer',
		'install',
		'--no-interaction',
		'--no-progress',
		'--prefer-dist',
	] );
	await runWpEnv( [
		'run',
		'cli',
		'wp',
		'rewrite',
		'structure',
		'/%postname%/',
	] );
	await runWpEnv( [
		'run',
		'cli',
		'php',
		'-r',
		`file_put_contents( '${ setupMarker }', '${ expectedVersion }' );`,
	] );
	console.log( 'The wp-env test environment has been provisioned.' );
};

const ensurePluginsActive = async () => {
	const activePlugins = await runWpEnv(
		[
			'run',
			'cli',
			'wp',
			'plugin',
			'list',
			'--status=active',
			'--field=name',
			'--skip-update-check',
		],
		{ capture: true }
	);
	const activePluginNames = new Set(
		activePlugins.stdout.split( /\r?\n/ ).filter( Boolean )
	);
	const requiredPluginNames = [
		'easy-digital-downloads-pro',
		'edd-software-licensing',
		'edd-composer',
	];

	for ( const pluginName of requiredPluginNames ) {
		if ( activePluginNames.has( pluginName ) ) {
			continue;
		}

		console.log( `Activating the required test plugin ${ pluginName }...` );
		await runWpEnv( [
			'run',
			'cli',
			'wp',
			'plugin',
			'activate',
			pluginName,
		] );
		activePluginNames.add( pluginName );
	}
};

const ensurePrettyPermalinks = async () => {
	const permalinkStructure = await runWpEnv(
		[ 'run', 'cli', 'wp', 'option', 'get', 'permalink_structure' ],
		{ allowFailure: true, capture: true }
	);

	if ( permalinkStructure.stdout.trim() === '/%postname%/' ) {
		return;
	}

	console.log( 'Configuring pretty permalinks for repository routes...' );
	await runWpEnv( [
		'run',
		'cli',
		'wp',
		'rewrite',
		'structure',
		'/%postname%/',
	] );
};

const verifyRepositoryRoute = async () => {
	const config = JSON.parse( readFileSync( configPath, 'utf8' ) );
	const port = config.port ?? 8888;
	const response = await fetch(
		`http://localhost:${ port }/composer/packages.json`
	);
	const responseBody = await response.text();
	let payload;

	try {
		payload = JSON.parse( responseBody );
	} catch {
		throw new Error(
			`The live Composer package-index route returned invalid JSON (HTTP ${ response.status }): ${ responseBody.slice(
				0,
				200
			) }`
		);
	}

	const cacheControl = response.headers.get( 'cache-control' ) ?? '';

	if (
		! response.ok ||
		! payload ||
		typeof payload.packages !== 'object' ||
		Array.isArray( payload.packages ) ||
		! cacheControl.includes( 'public' ) ||
		! cacheControl.includes( 'max-age=' )
	) {
		throw new Error(
			`The live Composer package-index route failed validation (HTTP ${ response.status }, Cache-Control: ${ cacheControl }).`
		);
	}

	console.log( 'The live Composer package-index route is available.' );
};

const verifyProtectedRoute = async () => {
	const config = JSON.parse( readFileSync( configPath, 'utf8' ) );
	const port = config.port ?? 8888;
	const response = await fetch(
		`http://localhost:${ port }/composer/download/integration-check/1.0.0`
	);
	const payload = await response.json();
	const cacheControl = response.headers.get( 'cache-control' ) ?? '';
	const challenge = response.headers.get( 'www-authenticate' ) ?? '';

	if (
		response.status !== 401 ||
		payload.code !== 'edd_composer_missing_credentials' ||
		! cacheControl.includes( 'private' ) ||
		! cacheControl.includes( 'no-store' ) ||
		! challenge.startsWith( 'Basic ' )
	) {
		throw new Error(
			`The protected Composer route failed validation (HTTP ${ response.status }, Cache-Control: ${ cacheControl }, WWW-Authenticate: ${ challenge }).`
		);
	}

	console.log(
		'The protected Composer route requires uncached HTTP Basic authentication.'
	);
};

const runSuccessfulDownloadFixture = async ( action ) => {
	const result = await runWpEnv(
		[
			'run',
			'cli',
			'wp',
			'eval-file',
			'tests/Integration/class-edd-composer-successful-download-fixture.php',
			action,
		],
		{ capture: true }
	);

	if ( action !== 'setup' ) {
		return null;
	}

	const jsonLine = result.stdout
		.split( /\r?\n/ )
		.find( ( line ) => line.trim().startsWith( '{' ) );

	if ( ! jsonLine ) {
		throw new Error(
			'The successful-download fixture did not return its setup data.'
		);
	}

	return JSON.parse( jsonLine );
};

const verifySuccessfulDownload = async () => {
	const config = JSON.parse( readFileSync( configPath, 'utf8' ) );
	const port = config.port ?? 8888;
	const baseUrl = `http://localhost:${ port }`;
	let failure = null;

	try {
		const fixture = await runSuccessfulDownloadFixture( 'setup' );
		const credentials = Buffer.from(
			`${ fixture.license_key }:${ fixture.site_url }`
		).toString( 'base64' );
		const response = await fetch(
			`${ baseUrl }/composer/download/${ encodeURIComponent(
				fixture.package_slug
			) }/${ encodeURIComponent( fixture.version ) }`,
			{
				headers: { authorization: `Basic ${ credentials }` },
				redirect: 'manual',
			}
		);
		const location = response.headers.get( 'location' ) ?? '';
		const cacheControl = response.headers.get( 'cache-control' ) ?? '';
		const signedUrl = location ? new URL( location, baseUrl ) : null;

		if (
			response.status !== 302 ||
			! signedUrl ||
			signedUrl.origin !== baseUrl ||
			signedUrl.pathname !== '/index.php' ||
			! signedUrl.searchParams.has( 'eddfile' ) ||
			! signedUrl.searchParams.has( 'ttl' ) ||
			! signedUrl.searchParams.has( 'token' ) ||
			! cacheControl.includes( 'private' ) ||
			! cacheControl.includes( 'no-store' )
		) {
			throw new Error(
				`The authenticated Composer download did not return a valid private EDD redirect (HTTP ${ response.status }, Location: ${ location }, Cache-Control: ${ cacheControl }).`
			);
		}

		const downloadResponse = await fetch( signedUrl );
		const file = Buffer.from( await downloadResponse.arrayBuffer() );
		const fileHash = createHash( 'sha256' ).update( file ).digest( 'hex' );
		const disposition =
			downloadResponse.headers.get( 'content-disposition' ) ?? '';

		if (
			! downloadResponse.ok ||
			file.length !== fixture.file_size ||
			fileHash !== fixture.file_sha256 ||
			! disposition.includes( 'attachment' ) ||
			! disposition.includes( '.zip' )
		) {
			throw new Error(
				`The EDD signed URL did not deliver the expected ZIP fixture (HTTP ${ downloadResponse.status }, bytes: ${ file.length }, Content-Disposition: ${ disposition }).`
			);
		}

		console.log(
			'The authenticated Composer route redirects to a signed EDD URL that delivers the expected ZIP.'
		);
	} catch ( error ) {
		failure = error;
	}

	try {
		await runSuccessfulDownloadFixture( 'cleanup' );
	} catch ( cleanupError ) {
		if ( failure ) {
			throw new AggregateError(
				[ failure, cleanupError ],
				'The successful-download check and fixture cleanup both failed.'
			);
		}

		throw cleanupError;
	}

	if ( failure ) {
		throw failure;
	}
};

const ensureEnvironment = async () => {
	validateDependencies();

	const expectedVersion = getSetupVersion();
	const status = await getEnvironmentStatus();
	if ( status === 'running' ) {
		const provisionedVersion = await getProvisionedVersion();

		if ( provisionedVersion === expectedVersion ) {
			console.log( 'The wp-env test server is already running.' );
		} else {
			console.log(
				'Refreshing the running wp-env test server configuration...'
			);
			await runWpEnv( [ 'start' ] );
		}
	} else {
		console.log( 'Starting the wp-env test server...' );
		await runWpEnv( [ 'start' ] );
	}

	if ( ( await getProvisionedVersion() ) === expectedVersion ) {
		console.log( 'The wp-env test environment is already provisioned.' );
	} else {
		await provisionEnvironment( expectedVersion );
	}

	await ensurePrettyPermalinks();
	await ensurePluginsActive();
};

const runTestSuite = async () => {
	const failures = [];
	const checks = [
		{
			name: 'PHPUnit',
			run: () =>
				runWpEnv( [
					'run',
					'cli',
					'../vendor/bin/phpunit',
					'--env-cwd=tests',
				] ),
		},
		{ name: 'permalink verification', run: ensurePrettyPermalinks },
		{ name: 'plugin activation verification', run: ensurePluginsActive },
		{ name: 'repository route verification', run: verifyRepositoryRoute },
		{ name: 'protected route verification', run: verifyProtectedRoute },
		{
			name: 'successful download verification',
			run: verifySuccessfulDownload,
		},
	];

	for ( const check of checks ) {
		try {
			await check.run();
		} catch ( error ) {
			failures.push(
				new Error(
					`${ check.name } failed: ${
						error instanceof Error ? error.message : error
					}`,
					{ cause: error }
				)
			);
		}
	}

	if ( failures.length > 0 ) {
		throw new AggregateError(
			failures,
			failures.map( ( failure ) => failure.message ).join( '\n' )
		);
	}
};

const command = process.argv[ 2 ] ?? 'test';

try {
	if ( command !== 'start' && command !== 'test' ) {
		throw new Error( `Unknown command "${ command }". Use start or test.` );
	}

	await ensureEnvironment();

	if ( command === 'test' ) {
		await runTestSuite();
	}
} catch ( error ) {
	console.error( error instanceof Error ? error.message : error );
	process.exitCode = 1;
}
