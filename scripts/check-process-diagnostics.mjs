import assert from 'node:assert/strict';

import { runProcess, sanitizeProcessOutput } from './lib/process.mjs';

const output = sanitizeProcessOutput(
	'Authorization: Basic c2VjcmV0\nCOMPOSER_AUTH={"secret":true}\n{"license_key":"abc","password":"example.org"}'
);

for ( const secret of [
	'c2VjcmV0',
	'"secret":true',
	'"abc"',
	'"example.org"',
] ) {
	assert.equal( output.includes( secret ), false );
}

assert.match( output, /\[REDACTED\]/ );

let failure;

try {
	await runProcess(
		process.execPath,
		[
			'-e',
			'console.log(\'Authorization: Basic visible-secret\'); console.error(\'{"password":"also-secret"}\'); process.exit(7);',
		],
		{ capture: true }
	);
} catch ( error ) {
	failure = error;
}

assert.ok( failure instanceof Error );
assert.match( failure.message, /exited with code 7[\s\S]*\[REDACTED\]/ );
assert.equal( failure.message.includes( 'visible-secret' ), false );
assert.equal( failure.message.includes( 'also-secret' ), false );
process.stdout.write(
	'Subprocess diagnostics are useful and credential-safe.\n'
);
