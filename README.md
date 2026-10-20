# Ace Image Enhancer

Ace Image Enhancer adds modern image handling to WordPress. It converts uploaded images to WebP or AVIF, serves the modern versions on the front end, and allows SVG uploads with sanitisation to guard against XSS. A batch tool lets you reprocess images already in your media library.

## Features

- Converts uploaded images to WebP or AVIF (AVIF is offered only when your PHP GD build supports it).
- Configurable image quality (default 85).
- Option to keep original files, or to replace them with the converted versions.
- Serves modern formats in image sources, `srcset`, content image tags and block backgrounds.
- SVG upload support, with sanitisation of uploaded SVG files.
- Optional in-admin SVG editor (disabled by default), built on SVG-Edit.
- Reprocess Images page for converting existing media in bulk, with defaults for file types, overwrite behaviour and an "uploaded after" date.
- Per-attachment tools in the media modal, plus REST routes under `ace-image-enhancer/v1` that power the batch tools.

## Requirements

- WordPress 6.0 or later (tested up to 7.0)
- PHP 7.4 or later
- GD with WebP support (and AVIF support if you want AVIF output)

## Installation

### From a release

1. Download the zip from the latest [GitHub Release](https://github.com/AceMedia/Ace-Image-Enhancer/releases).
2. In wp-admin go to Plugins > Add New > Upload Plugin, choose the zip and install it.
3. Activate **Ace Image Enhancer**.

### From source

The plugin has no build step. Clone the repository into `wp-content/plugins/Ace-Image-Enhancer` and activate it.

## Usage

- Go to Media > Image Enhancer to choose the image format, quality and SVG options.
- New uploads are converted automatically according to those settings.
- To convert existing images, go to Media > Reprocess Images, pick the file types and options, and run the batch.

## Configuration

Settings are stored in the `ace_image_enhancer_options` option. Defaults: format WebP, quality 85, SVG uploads enabled, originals kept, originals not replaced, SVG editor off, batch types jpg, jpeg and png.

## Development

There is no `package.json`. The admin scripts and styles live in `js/` and `css/`, and the bundled SVG editor is in `svgedit/`.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled SVG-Edit code carries its own MIT licence in `svgedit/LICENSE-MIT.txt`.

## Author and repository

Shane Rounce, AceMedia. Source: https://github.com/AceMedia/Ace-Image-Enhancer
