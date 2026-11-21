# WebP EXIF regression checks

Run the isolated plugin registration and older-runtime fallback checks:

```sh
php tests/test-webp-exif.php
```

To include the real stock WordPress metadata function, point at a disposable checkout's image include:

```sh
WP_CORE_IMAGE_FILE=/path/to/wordpress/wp-admin/includes/image.php php tests/test-webp-exif.php
```

This fixture stubs hooks, options, UTF-8 validation and sanitisation boundaries. It tests the actual plugin constructor and stock metadata function, but does not test uploads, image conversion, sanitisation security or a complete WordPress request. A separate read-only check against an actual WordPress bootstrap covers Core's sanitised metadata representation.

The tiny images in `fixtures/` are synthetic, created with Pillow. They contain no photograph or client data. The EXIF samples contain orientation 6, the artist `Synthetic test author` and copyright `Synthetic test copyright`. The plain WebP contains no EXIF.

Native checks cover PHP 7.4 plugin registration/fallback and PHP 8.1, 8.5 and 8.6 registration plus the stock metadata reader. Extension-free runs check that missing EXIF does not change existing types. PHP 7.4 needs its JSON extension for the test's result output.
