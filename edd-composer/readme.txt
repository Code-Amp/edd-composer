=== EDD Composer Extension ===
Contributors: codeamp
Tags: composer, easy digital downloads, software licensing
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose eligible Easy Digital Downloads products as authenticated Composer packages.

== Description ==

EDD Composer Extension adds a Composer package repository to an Easy Digital Downloads store. Store administrators choose which licensed downloads are available and customers authenticate downloads with an EDD Software Licensing key and its activated site URL.

Version 1 requires:

* WordPress 6.9 or newer.
* PHP 8.0 or newer.
* Easy Digital Downloads 3.7.0 or newer.
* EDD Software Licensing 3.9.7 or newer.

When a dependency is unavailable or outdated, repository features remain disabled and administrators see a requirements-only screen. The plugin does not expose partially configured routes.

Production repository and download URLs must use HTTPS so Composer credentials are never sent over cleartext transport.

== Installation ==

1. Install and activate Easy Digital Downloads and EDD Software Licensing.
2. Upload the `edd-composer` directory to `/wp-content/plugins/`.
3. Activate EDD Composer Extension through the Plugins screen.
4. Open Downloads > Composer and review the requirements.

== Composer Setup ==

Add the public package repository to your project, replacing the example store URL:

`composer config repositories.edd-composer composer https://store.example/composer`

Configure HTTP Basic authentication outside the project's `composer.json`. The username is the EDD Software Licensing key and the password is the site URL activated against that license:

`composer config --global --auth http-basic.store.example LICENSE-KEY https://activated-site.example`

For automated environments, provide the same per-domain credentials through Composer's `COMPOSER_AUTH` environment variable or another secure secret store. Do not commit license credentials to the project repository.

Composer can then install any enabled package covered by that activated license:

`composer require vendor/package-slug`

== Advanced Proxy Deployment ==

The default repository and signed downloads use the WordPress site origin. A reverse proxy can be used when its public origins are explicitly allowlisted. The proxy remains responsible for forwarding both Composer routes and the unchanged EDD signed-download path and query string.

The following example advertises a repository path on one HTTPS origin and sends final EDD-signed redirects through another:

`add_filter( 'edd_composer_allowed_repository_origins', function ( $origins ) { $origins[] = 'https://composer.example.com'; $origins[] = 'https://downloads.example.com'; return $origins; } );`

`add_filter( 'edd_composer_repository_base_url', function () { return 'https://composer.example.com/private-repository'; } );`

`add_filter( 'edd_composer_download_proxy_origin', function () { return 'https://downloads.example.com'; } );`

The download proxy setting accepts an origin only, without a path. EDD Composer Extension first validates the signed URL against the WordPress origin and then replaces only its scheme, host, and port. It does not provide proxy server, firewall, or shared-secret configuration.

Local HTTP environments whose WordPress environment type is `local` or `development` can define `EDD_COMPOSER_ALLOW_INSECURE_HTTP` as `true`. The override is ignored in production.

== Frequently Asked Questions ==

= Does this plugin include Easy Digital Downloads or Software Licensing? =

No. Both are separate dependencies and must be installed on the store.

= Are protected download URLs published in the Composer package index? =

No. The public index contains package metadata. Protected downloads require valid Software Licensing credentials before EDD creates a signed URL.

== Changelog ==

= 1.0.0 =

* Initial release.
