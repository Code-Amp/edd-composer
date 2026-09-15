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

== Installation ==

1. Install and activate Easy Digital Downloads and EDD Software Licensing.
2. Upload the `edd-composer` directory to `/wp-content/plugins/`.
3. Activate EDD Composer Extension through the Plugins screen.
4. Open Downloads > Composer and review the requirements.

== Frequently Asked Questions ==

= Does this plugin include Easy Digital Downloads or Software Licensing? =

No. Both are separate dependencies and must be installed on the store.

= Are protected download URLs published in the Composer package index? =

No. The public index contains package metadata. Protected downloads require valid Software Licensing credentials before EDD creates a signed URL.

== Changelog ==

= 1.0.0 =

* Initial release.
