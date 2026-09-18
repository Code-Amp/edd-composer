# Licensed EDD test plugins

The wp-env test profile mounts locally supplied plugins from this directory as
ordinary WordPress plugins. Only this file is committed; the licensed plugin
directories are ignored.

Extract the pinned archives so these files exist:

```text
wp-env/plugins/easy-digital-downloads-pro/easy-digital-downloads.php
wp-env/plugins/edd-software-licensing/edd-software-licenses.php
```

The expected versions are Easy Digital Downloads Pro 3.7.0 and Software
Licensing 3.9.7. Composer 2 must also be available on the host. The package
scripts check both plugin files and versions before use.
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
