# Licensed EDD test plugins

The wp-env test profile mounts plugins from this directory as ordinary WordPress
plugins. Code Amp developers can install both pinned versions from the private
catalogue using their existing GitHub CLI authentication:

```sh
gh auth login
gh repo view Code-Amp/wp-dependencies
pnpm run test:install-plugins minimum
```

External contributors can instead extract both official plugin ZIPs directly
into `wp-env/plugins/` from the repository root:

```sh
unzip /path/to/easy-digital-downloads-pro-3.7.0.zip -d wp-env/plugins
unzip /path/to/edd-software-licensing-3.9.7.zip -d wp-env/plugins
```

Only this README is committed; the extracted licensed-plugin directories are
ignored.

Extract the pinned archives so these files exist:

```text
wp-env/plugins/easy-digital-downloads-pro/easy-digital-downloads.php
wp-env/plugins/edd-software-licensing/edd-software-licenses.php
```

The expected versions are Easy Digital Downloads Pro 3.7.0 and Software
Licensing 3.9.7. Composer 2 must also be available on the host. The package
scripts check both plugin files and versions before use.

Code Amp CI obtains these versions from its pinned private dependency catalogue
using `WP_DEPENDENCIES_TOKEN`. Other CI environments can set `EDD_PRO_ZIP_URL` and
`EDD_SOFTWARE_LICENSING_ZIP_URL` to direct HTTPS archive URLs that require no
separate authorization header, then run `pnpm run test:install-plugins minimum`
before starting the suite. The committed GitHub Actions workflow performs this
command automatically.

Starting is idempotent, and container dependencies and plugin activation are
only provisioned when the environment's setup marker is missing or outdated:

```sh
pnpm run test:start
pnpm run test
```

`pnpm run test` creates a licensed EDD fixture and an OS-temporary Composer
consumer project. It runs a real Composer install against the live wp-env
repository, verifies the installed WordPress plugin and lock metadata, and
removes both fixtures afterward. No consumer project is kept in this repository.
