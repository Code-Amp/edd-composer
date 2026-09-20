# Architecture

EDD Composer Extension exposes selected Easy Digital Downloads products as Composer packages while leaving entitlement and file delivery with EDD and Software Licensing. These are the design boundaries contributors should preserve.

## Distribution boundary

- `edd-composer/` is the complete installable WordPress plugin.
- React source lives in `src/` and builds into committed files under `edd-composer/assets/`.
- Release archives contain one top-level `edd-composer/` directory and no development tooling or licensed dependencies.

This keeps releases directly installable without Node.js while allowing the repository to hold tests and build tooling separately.

## Runtime and dependencies

- WordPress, PHP, Easy Digital Downloads, and Software Licensing minimum versions are checked before plugin services start.
- When a requirement is missing or too old, WordPress still loads the plugin and its administration screen, but repository features remain disabled.
- PHP services are small, domain-oriented classes composed explicitly during bootstrap. Avoid service containers and abstraction layers unless the problem genuinely requires them.

Graceful gating prevents a missing commercial dependency from causing a fatal error. Shallow composition keeps the request and security paths easy to audit.

## Package catalogue

- EDD products are discovered dynamically and must be explicitly enabled by an administrator.
- A global vendor slug combines with a unique per-product package slug to form each Composer package name.
- File versions remain in `edd_download_files[<file key>][version]` so they travel with EDD's existing file records.
- Versions use SemVer 2.0.0. One leading `v` is accepted as input and removed from published metadata.
- Only enabled products with valid, versioned files and unique package names are published.
- Variable-price products are published only when their Composer files apply to every price variation.

These rules make the public index deterministic and prevent ambiguous package or file resolution.

## HTTP interface

- `/composer` is the default public repository endpoint and uses Composer's simple `packages` repository format.
- Package metadata is public; package files are protected.
- HTTP Basic Auth uses the Software Licensing key as the username and the activated site URL as the password.
- A download request validates the credential licence, product or bundle entitlement, the licence that grants the target product, site activation, exact version, and qualifying price or order before asking EDD for a signed URL.
- Successful requests redirect to the EDD-signed URL rather than streaming files through this plugin.

Keeping discovery public makes standard Composer clients simple to configure. Delegating delivery to EDD preserves its download controls and accounting.

## URLs and transport

- Production credentials and protected downloads require HTTPS.
- EDD-signed URLs must initially match the WordPress site origin.
- An external advertised repository URL or signed-download proxy origin is opt-in through filters and must pass the configured origin allowlist.
- Proxy rewriting may replace only the URL scheme, host, and port; the signed path and query remain unchanged.

These restrictions support reverse-proxy deployments without weakening signed URLs or trusting request headers implicitly.

## Caching and security

- The public package index may be cached and is invalidated when package settings, files, versions, validity, or cache schema change.
- Protected redirects and errors are private and non-cacheable.
- Administration uses WordPress capabilities, REST authentication, boundary sanitization, and contextual escaping.
- Credentials, authorization headers, customer data, private file URLs, and licensed dependency source must never enter logs, fixtures, releases, or commits.

Public metadata benefits from caching; authorization decisions must always reflect current licence and activation state.

## Administration

- The administration application uses WordPress React packages, DataViews, DataForm, and the WordPress REST API.
- Admin assets load only on the plugin's screen and use generated dependency metadata from `index.asset.php`.
- Production assets are committed and must be rebuilt whenever their source changes.

Using WordPress-native components and transport keeps permissions, accessibility, dependency loading, and visual behavior aligned with WordPress.

## Verification

- Minimum and latest compatibility profiles exercise the supported WordPress and PHP range against real EDD and Software Licensing code.
- The release path covers static analysis, coding standards, unit and integration tests, live public and protected requests, an EDD-signed download, a real Composer installation, asset reproducibility, and clean ZIP installation.
- Licensed test plugins remain ignored; local contributors or CI provision their own copies through the documented installation routes.

The test boundary mirrors production closely without distributing third-party plugin archives from this repository.
