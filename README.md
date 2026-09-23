# EDD Composer Extension

EDD Composer Extension turns selected, licence-protected Easy Digital Downloads products into installable Composer packages. It publishes package metadata from WordPress and authorizes each protected download with an EDD Software Licensing key and the site URL activated against that key.

The installable plugin is the [`edd-composer/`](edd-composer/) directory. Development sources, tests, package-manager files, and local environments deliberately remain outside that directory.

See the concise [architecture notes](docs/architecture.md) for the design boundaries and reasons behind them.

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- Easy Digital Downloads 3.7.0 or newer
- EDD Software Licensing 3.9.7 or newer
- Node.js matching [`.nvmrc`](.nvmrc), pnpm 12.4.2, Composer 2, and Docker for development

The automated compatibility matrix verifies the minimum WordPress 6.9/PHP 8.0 environment and WordPress 7.0 on PHP 8.4.

## Install the plugin

Download `edd-composer.zip` from the [latest release](https://github.com/Code-Amp/edd-composer/releases/latest). In WordPress, go to **Plugins → Add New → Upload Plugin**, select the ZIP, and activate EDD Composer Extension. Easy Digital Downloads and EDD Software Licensing must be installed separately. Then open **Downloads → Composer** to configure the repository.

## Install for development

```sh
pnpm install --frozen-lockfile
composer install
pnpm run test:install-plugins minimum
pnpm run env:start
pnpm run start
```

The development environment mounts EDD Pro and Software Licensing from the same ignored `wp-env/plugins/` directories used by the integration suite. Code Amp developers can populate them with `pnpm run test:install-plugins minimum`; external contributors can install their own copies as described below. EDD Pro is the complete EDD plugin and must not be mounted alongside the free edition.

`pnpm run start` watches the React application and writes generated assets to `edd-composer/assets/`. `pnpm run env:start` manages the ordinary development WordPress environment; the dedicated test environment uses the separate commands below.

## Test environment

### Licensed test plugins

The integration suite requires EDD Pro 3.7.0 and Software Licensing 3.9.7 in the ignored `wp-env/plugins/` directory.

#### Code Amp developers

Team members with access to the private dependency catalogue can install both pinned plugins using their existing GitHub CLI authentication:

```sh
gh auth login
gh repo view Code-Amp/wp-dependencies
pnpm run test:install-plugins minimum
```

The login is a one-time setup. Repository access is enforced by GitHub, and no shared token needs to be copied into the local environment.

#### External contributors

Extract both official ZIPs directly into `wp-env/plugins/` so the resulting plugin trees are:

```text
wp-env/plugins/easy-digital-downloads-pro/
wp-env/plugins/edd-software-licensing/
```

Yes, the irony is noted: until EDD itself speaks Composer, these test dependencies take the scenic route via a manual install.

These directories are ignored and must never be committed. See the [licensed test-plugin setup](wp-env/plugins/README.md) for example extraction commands, required versions, and the exact entry files checked by the test runner. Once the files are present, `pnpm run test` and `pnpm run check` use them without requiring catalogue credentials or archive URLs.

```sh
pnpm run test:start
pnpm run test
pnpm run test:stop
pnpm run test:destroy
```

`pnpm run test` starts and provisions the dedicated environment when necessary, runs the JavaScript and WordPress test suites, checks the live public and protected endpoints, downloads a real EDD-signed ZIP, and installs a package through Composer 2.

### CI licensed-plugin setup

Code Amp CI runs `pnpm run test:install-plugins <profile>` to resolve the versions declared in [`scripts/test-matrix.json`](scripts/test-matrix.json) from a private dependency catalogue pinned to an exact commit. It authenticates with the read-only `WP_DEPENDENCIES_TOKEN` secret, then verifies each catalogue path, SHA-256 checksum, archive structure, entry file, and WordPress version header before extraction.

Forks do not need access to that catalogue or the `WP_DEPENDENCIES_TOKEN` secret. To run the licensed GitHub Actions jobs in a fork, add these repository Actions secrets under **Settings → Secrets and variables → Actions**:

- `EDD_PRO_ZIP_URL`: a direct HTTPS download URL for the required EDD Pro ZIP.
- `EDD_SOFTWARE_LICENSING_ZIP_URL`: a direct HTTPS download URL for the required Software Licensing ZIP.

Each URL must return its ZIP to a normal HTTPS GET without a separate authorization header; a private signed URL is suitable. The archive versions must match the selected profile in [`scripts/test-matrix.json`](scripts/test-matrix.json). Pull requests originating from external forks do not receive secrets and skip the licensed compatibility jobs.

The one installation command supports all three automated routes, in this order for each plugin:

1. A private archive URL for fork or custom CI infrastructure.
2. `WP_DEPENDENCIES_TOKEN` for Code Amp CI.
3. The authenticated local `gh` session for Code Amp developers; this fallback is disabled in GitHub Actions.

Every route uses the same checksum, archive, entry-file, and version validation before extraction into `wp-env/plugins/`.

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

To publish a release, merge the version and generated assets into `main`, then push a matching `vX.Y.Z` tag from that commit. Tag CI reruns the full matrix and publishes its verified plugin ZIP as a GitHub Release only when every job passes. The tag must point to a commit on `main`.

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
