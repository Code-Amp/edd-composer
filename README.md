# EDD Composer Extension

EDD Composer Extension turns selected, licence-protected Easy Digital Downloads products into installable Composer packages. It publishes package metadata from WordPress and authorizes each protected download with an EDD Software Licensing key and the site URL activated against that key.

The installable plugin is the [`edd-composer/`](edd-composer/) directory. Development sources, tests, package-manager files, and local environments deliberately remain outside that directory.

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- Easy Digital Downloads 3.7.0 or newer
- EDD Software Licensing 3.9.7 or newer
- Node.js matching [`.nvmrc`](.nvmrc), pnpm 12.4.2, Composer 2, and Docker for development

The automated compatibility matrix verifies the minimum WordPress 6.9/PHP 8.0 environment and WordPress 7.0 on PHP 8.4.

## Install for development

```sh
pnpm install --frozen-lockfile
composer install
pnpm run env:start
pnpm run start
```

The default development environment installs the free Easy Digital Downloads plugin and displays the requirements-only screen until Software Licensing is supplied. Copy [`.wp-env.override.example.json`](.wp-env.override.example.json) to the ignored `.wp-env.override.json` and replace its example path to mount a local Software Licensing checkout.

`pnpm run start` watches the React application and writes generated assets to `edd-composer/assets/`. `pnpm run env:start` manages the ordinary development WordPress environment; the dedicated test environment uses the separate commands below.

## Test environment

The integration suite requires locally supplied, licensed EDD Pro 3.7.0 and Software Licensing 3.9.7 directories. Extract them at:

```text
wp-env/plugins/easy-digital-downloads-pro/
wp-env/plugins/edd-software-licensing/
```

Yes, the irony is noted: until EDD itself speaks Composer, these test dependencies take the scenic route via a manual install.

These directories are ignored and must never be committed. See [`wp-env/plugins/README.md`](wp-env/plugins/README.md) for the expected entry files.

Code Amp CI resolves the versions declared in [`scripts/test-matrix.json`](scripts/test-matrix.json) from a private dependency catalogue pinned to an exact commit. It authenticates with the read-only `WP_DEPENDENCIES_TOKEN` secret, then verifies each catalogue path, SHA-256 checksum, archive structure, entry file, and WordPress version header before extraction.

Forks do not need access to that catalogue. Their workflows can provide private HTTPS archive URLs through `EDD_PRO_ZIP_URL` and `EDD_SOFTWARE_LICENSING_ZIP_URL`, while local development can continue using the ignored extracted directories above. Fork pull requests never receive Code Amp's secret.

```sh
pnpm run test:start
pnpm run test
pnpm run test:stop
pnpm run test:destroy
```

`pnpm run test` starts and provisions the dedicated environment when necessary, runs the JavaScript and WordPress test suites, checks the live public and protected endpoints, downloads a real EDD-signed ZIP, and installs a package through Composer 2.

## Verification and packaging

```sh
pnpm run lint
pnpm run phpstan
pnpm run test
pnpm run build
pnpm run plugin-zip
pnpm run check
```

`pnpm run phpstan` analyses the distributable plugin at level 5 against the real, locally installed EDD Pro and Software Licensing sources. It therefore requires the same ignored licensed-plugin directories as the integration tests.

`pnpm run check` is the complete CI/release verification entry point. It validates manifests and versions, runs PHP syntax, PHPCS, PHPCompatibility, PHPStan, JavaScript and stylesheet checks, exercises the full test suite, rebuilds committed assets, and validates a clean install of the generated plugin ZIP.

The release archive is `edd-composer.zip` and contains only the top-level `edd-composer/` plugin directory. Generated production assets are committed so the archive does not require Node.js at runtime.

## Repository layout

```text
edd-composer/       Installable WordPress plugin and committed assets
src/                React administration source
tests/              JavaScript, WordPress, fixture, and integration tests
scripts/            Environment and release-check orchestration
wp-env/             Container-only PHPUnit dependencies and ignored plugins
```

## Security

Protected package credentials must be sent over HTTPS in production. Never include licence keys, Authorization headers, customer data, private download URLs, or licensed plugin source in issues, fixtures, logs, or commits. See [`SECURITY.md`](SECURITY.md) for reporting guidance.

## Licence

EDD Composer Extension is licensed under the GNU General Public License version 2 or later. See [`LICENSE`](LICENSE).
