# EDD Composer Extension

EDD Composer Extension lets an Easy Digital Downloads store offer licensed WordPress plugins as Composer packages. Customers use their EDD Software Licensing key and the website URL activated against that key to download packages.

## Requirements

- WordPress 6.9 or newer and PHP 8.0 or newer
- Easy Digital Downloads 3.7.0 or newer
- EDD Software Licensing 3.9.7 or newer

Easy Digital Downloads and Software Licensing are separate plugins and are not included in this download. If a requirement is missing or outdated, EDD Composer Extension shows a requirements notice and keeps repository features disabled.

## Install

1. Install and activate Easy Digital Downloads and EDD Software Licensing on your store.
2. Download `edd-composer.zip` from the [latest release](https://github.com/Code-Amp/edd-composer/releases/latest). Use the attached plugin ZIP, not GitHub's source-code archive.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**, select the ZIP, and activate EDD Composer Extension.
4. Open **Downloads → Composer** to configure your repository.

## Add a versioned EDD Download

1. Create or edit an EDD Download, publish it, and enable Software Licensing for that Download.
2. On the Download edit page, open **Download details → Files**. Add a file row for the plugin ZIP and set its **File URL**.
3. In that same file row, enter a **Composer Version** such as `1.2.3`, `1.2.3-beta.1`, or `v1.2.3`. EDD Composer Extension adds this field to the normal EDD file row; the version is not entered on the Composer settings screen. Save the Download.

Each version you want to offer needs its own download file row and a unique [SemVer](https://semver.org/) version. A leading lowercase `v` is accepted and omitted from the published Composer version. For variable-price Downloads, make each Composer file available to all price variations.

## Publish the Composer package

1. Under **Downloads → Composer**, set the **Repository title** and **Composer vendor**. The vendor is the first part of every package name, for example `acme` in `acme/my-plugin`.
2. Review the Download's **Package slug**, enable it in the product catalogue, and save your changes. The screen reports missing file versions and other issues that prevent publication.
3. Copy the repository URL shown at the top of the screen. Once a package is published, choose **Share installation guide** and **Copy Markdown** to give customers instructions tailored to your repository and package names.

The customer guide covers Composer 2, authentication, package installation, and CI/CD. Package metadata is public, but downloading a package requires a valid licence key and its activated website URL. Use HTTPS for production repository and download URLs, and never commit licence credentials or Composer authentication files.

## For contributors

See [DEVELOPERS.md](DEVELOPERS.md) for local setup, testing, CI, and releases, and the [architecture notes](docs/architecture.md) for design decisions.

## Security and licence

Report security issues as described in [SECURITY.md](SECURITY.md). EDD Composer Extension is licensed under the [GNU General Public License version 2 or later](LICENSE).
