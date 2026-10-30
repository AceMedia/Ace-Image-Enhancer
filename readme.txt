=== Ace Image Enhancer ===
Contributors: shanerounce
Tags: images, webp, avif, svg, performance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds modern image handling (WebP/AVIF) with configurable settings and secure SVG upload support with XSS sanitization.

== Description ==

Adds modern image handling (WebP/AVIF) with configurable settings and secure SVG upload support with XSS sanitization.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it through the Plugins screen.
3. Configure image conversion and optional SVG tools under Media > Image Enhancer.
4. SVG uploads require PHP DOM. Raster conversions require a working WordPress image editor with support for the selected format.

== Frequently Asked Questions ==

= Where do I report a bug or ask for a feature? =

Open an issue on the plugin's GitHub repository.

== Changelog ==

= 1.4.1 =
* Harden SVG uploads, sideloads and editing with a DOM allowlist.
* Check attachment permissions and keep file operations inside uploads.
* Reject filename collisions and roll back incomplete file renames.
* Fix editor save controls and PHP 8.5 resource compatibility.

== Privacy and external services ==

Image conversion and SVG editing run locally. The plugin does not send images, account details or site content to an external service. The bundled SVG-Edit editor is distributed under the MIT licence in svgedit/LICENSE-MIT.txt. Its upstream source and build instructions are available at https://github.com/SVG-Edit/svgedit. The embedded editor can store preferences and artwork in the browser when you choose its local storage option.

== SVG limitations ==

SVG uploads are limited to 5 MB. Compressed SVGZ, scripts, styles, embedded documents, animation and external references are rejected. Basic shapes, text, gradients and local icon references are supported. Disable SVG uploads in settings to reject new SVG files. Existing files are not rewritten automatically.

Bulk processing and site-wide rename operations require an administrator. Individual attachments must also be editable by the current user. Renaming changes public URLs, so arrange redirects where needed. No images are changed merely by activating the plugin.
