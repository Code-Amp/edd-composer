import { spawn } from 'node:child_process';

/**
 * Removes credential-shaped values from subprocess diagnostics.
 *
 * @param {string} output Process output.
 * @return {string} Sanitized output.
 */
export const sanitizeProcessOutput = ( output ) =>
	String( output )
		.replace( /(authorization\s*:\s*basic\s+)[^\s"']+/gi, '$1[REDACTED]' )
		.replace( /(COMPOSER_AUTH\s*=\s*)[^\r\n]+/gi, '$1[REDACTED]' )
		.replace(
			/("(?:license_key|password|authorization)"\s*:\s*")[^"]*(")/gi,
			'$1[REDACTED]$2'
		);

/**
 * Runs a child process and preserves useful sanitized diagnostics on failure.
 *
 * @param {string}   executable           Executable name or path.
 * @param {string[]} args                 Process arguments.
 * @param {Object}   options              Process options.
 * @param {boolean}  options.allowFailure Whether a nonzero exit is returned.
 * @param {boolean}  options.capture      Whether to capture standard streams.
 * @param {string}   options.cwd          Working directory.
 * @param {Object}   options.env          Process environment.
 * @return {Promise<Object>} Process result.
 */
export const runProcess = (
	executable,
	args,
	{
		allowFailure = false,
		capture = false,
		cwd = process.cwd(),
		env = process.env,
	} = {}
) =>
	new Promise( ( resolveProcess, reject ) => {
		const child = spawn( executable, args, {
			cwd,
			env,
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

			const details = [ stdout, stderr ]
				.map( ( value ) => sanitizeProcessOutput( value ).trim() )
				.filter( Boolean )
				.join( '\n' );
			const summary = signal
				? `${ executable } exited after signal ${ signal }.`
				: `${ executable } exited with code ${ code }.`;

			reject(
				new Error( details ? `${ summary }\n${ details }` : summary )
			);
		} );
	} );

/**
 * Runs the pnpm instance that invoked the current package script.
 *
 * @param {string[]} args    pnpm arguments.
 * @param {Object}   options Process options.
 * @return {Promise<Object>} Process result.
 */
export const runPnpm = ( args, options = {} ) => {
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
