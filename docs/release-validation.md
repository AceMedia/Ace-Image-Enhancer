# Ace Image Enhancer 1.4.2 review

Prepared locally on 30 October 2026 after rebuilding the lost temporary checkout. The canonical PHP 8.5 resource change in 7c18cd2 is preserved. Source and package verification are recorded here; live deployment verification is tracked separately in the release issues. The package has not been submitted to WordPress.org.

## Changes

- Issue #3: replace regex SVG filtering with a fail-closed DOM allowlist, reject documents above 5 MB, DTD/entities, scripts, event handlers, styles, foreign namespaces and external references. Protect upload and sideload prefilters, reject SVGZ and honour disabled SVG settings. Local icon references and gradients remain supported.
- Issue #4: keep avatar discovery GET requests read-only while retaining legacy URL lookup. A dedicated disposable test confirms no avatar metadata is saved. require administrator capability for bulk/site-wide operations and attachment editing capability for individual conversions and SVG requests. Retain nonce checks. Fix the existing editor modal, canvas save API and clipped iframe.
- Issue #5: restrict image operations to real paths beneath uploads, accept trusted deployment-managed upload-root symlinks while rejecting nested symlinks and rename collisions, roll back partial rename sequences, and prepare complete SVG edits before replacement. Use same-directory replacement without pre-deleting originals.
- Issue #6: document DOM/image-editor requirements, SVG limitations, editor storage and upstream source/licence. Package only runtime files. Remove incorrect claims that WordPress 6.3 natively permits SVG uploads. Escape remaining settings output.

## Repeated verification

- 16 SVG adversarial/valid-document cases pass on PHP 8.4.25. PHP 8.5.10 on this Pi lacks DOM and rejects every SVG safely.
- 17 native path, collision, upload, disabled-setting and anonymous/other-author permission checks pass in a dedicated local database.
- Native reactivation on PHP 8.4.25 and 8.5.10 produces no plugin warnings or output.
- Final ZIP installs and reactivates on Playground WordPress 6.0/PHP 7.4, with explicit version assertions, no activation output and exit 0.
- Chromium on the isolated localhost:9486 installation loads and saves a disposable SVG through the embedded editor. The SVG survives the save sanitisation. Editor height corrected from the clipped frame to 506 px at the tested desktop viewport.
- Plugin Check against the extracted final ZIP reports 0 errors and 5 warnings; stderr is empty. All 197 packaged files match the native installed copy.
- PHP and JavaScript syntax checks pass.

ZIP SHA256: `ff37201ad0270d1daf6e7584f877b54cff0b6abe840fa5b4b8d6248a37c94a12`.

## Warning triage

Two slow-query warnings concern explicit administrator batch and rename tools. The SQL interpolation warning is a fixed internal protected-option allowlist, escaped with esc_sql; user search values use placeholders. Debug logging is conditional on WP_DEBUG. Raw SVG input is deliberately unslashed before the DOM allowlist rather than passed through a text sanitizer which would destroy its markup; attachment permissions and the nonce are checked before saving.

Read-only inspection confirmed William uses a shared uploads-root symlink. The native tests exercise that deployment shape, actual PNG-to-WebP conversion through the trusted root, and rejection of an untrusted symlink beneath it.

## Limits and next step

Native WordPress used the recovered dedicated image-test database; the independent Playground test supplies a clean installation. No live media were changed. The parent task owns consumer rollout and live checks after ARC. Do not close issues as live-verified or deploy to PPN live on this evidence. Complex SVGs containing unsupported features are rejected rather than partially rewritten. Existing files are not automatically rewritten.

Persistent evidence: `/var/www/html/plugin-review-work/evidence/image-*`; browser captures: `/var/www/html/plugin-review-work/image-browser/`; final package: `dist/ace-image-enhancer-1.4.2.zip` (rebuild with `python bin/package.py`).
