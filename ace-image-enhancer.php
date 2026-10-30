<?php
/**
 * Plugin Name: Ace Image Enhancer
 * Description: Adds modern image handling (WebP/AVIF) with configurable settings and secure SVG upload support with XSS sanitization.
 * Version: 1.4.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Shane Rounce, AceMedia
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

class Ace_Image_Enhancer {

    private $options;

    public function __construct() {
        // Load settings
        $this->options = get_option('ace_image_enhancer_options', $this->get_default_options());
        
        // Admin settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        
        add_filter('upload_mimes', [$this, 'allow_svg_upload']);
        add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_svg_prefilter']);
        add_filter('wp_handle_sideload_prefilter', [$this, 'sanitize_svg_prefilter']);
        if ($this->get_option('enable_svg') && class_exists('DOMDocument')) {
            add_filter('wp_check_filetype_and_ext', [$this, 'fix_svg_mime_check'], 10, 5);
        }

        // Raster image handling - only if not set to 'original'
        if ($this->get_option('image_format') !== 'original') {
            add_filter('wp_handle_upload', [$this, 'handle_modern_image_upload'], 20); // Priority 20 to run after SVG
            add_filter('wp_generate_attachment_metadata', [$this, 'generate_webp_versions'], 10, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'serve_webp_images'], 10, 4);
            // The src swap alone isn't enough: browsers pick from srcset, and content images and
            // cover backgrounds carry hard-coded URLs. Point all of them at the modern sibling.
            add_filter('wp_calculate_image_srcset', [$this, 'serve_modern_srcset']);
            add_filter('wp_content_img_tag', [$this, 'serve_modern_img_tag']);
            add_filter('render_block', [$this, 'serve_modern_backgrounds']);

            // Override WordPress default image quality with our setting
            add_filter('wp_editor_set_quality', [$this, 'override_image_quality'], 10, 2);
        }
        
        // SVG Editor integration for media library (experimental feature)
        if ($this->get_option('enable_svg') && $this->get_option('enable_svg_editor')) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_svg_editor']);
            add_action('wp_ajax_ace_save_svg', [$this, 'ajax_save_svg']);
            add_action('wp_ajax_ace_load_svg', [$this, 'ajax_load_svg']);
        }

        // Attachment tools (Reprocess + Rename buttons) always available
        add_filter('attachment_fields_to_edit', [$this, 'add_attachment_tools'], 10, 2);

        // Reprocess & rename REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Admin enqueue for batch page and attachment tools
        add_action('admin_enqueue_scripts', [$this, 'enqueue_reprocess_tools']);
    }

    // ---------------------------------------------------------
    // Settings & Options
    // ---------------------------------------------------------
    private function get_default_options() {
        return [
            'image_format'           => 'webp',  // webp, avif, or original
            'image_quality'          => 85,
            'enable_svg'             => true,
            'keep_originals'         => true,
            'replace_originals'      => false,
            'enable_svg_editor'      => false,
            'batch_default_types'    => ['jpg', 'jpeg', 'png'],
            'batch_overwrite'        => false,
            'batch_default_date_after' => '',
        ];
    }

    private function get_option($key) {
        return isset($this->options[$key]) ? $this->options[$key] : $this->get_default_options()[$key];
    }

    public function add_settings_page() {
        add_media_page(
            'Image Enhancer Settings',
            'Image Enhancer',
            'manage_options',
            'ace-image-enhancer',
            [$this, 'render_settings_page']
        );
        add_media_page(
            'Reprocess Images',
            'Reprocess Images',
            'upload_files',
            'ace-image-reprocess',
            [$this, 'render_reprocess_page']
        );
    }

    public function register_settings() {
        register_setting('ace_image_enhancer', 'ace_image_enhancer_options', [$this, 'sanitize_options']);

        add_settings_section(
            'ace_image_enhancer_main',
            'Image Format Settings',
            [$this, 'section_callback'],
            'ace-image-enhancer'
        );

        add_settings_field(
            'image_format',
            'Image Format',
            [$this, 'format_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );

        add_settings_field(
            'image_quality',
            'Image Quality',
            [$this, 'quality_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );

        add_settings_field(
            'keep_originals',
            'Keep Original Files',
            [$this, 'keep_originals_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );

        add_settings_field(
            'replace_originals',
            'Replace Originals',
            [$this, 'replace_originals_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );

        add_settings_field(
            'enable_svg',
            'Enable SVG Upload',
            [$this, 'svg_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );
        
        add_settings_field(
            'enable_svg_editor',
            'Enable SVG Editor (Experimental)',
            [$this, 'svg_editor_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_main'
        );

        // --- Batch reprocessing defaults section ---
        add_settings_section(
            'ace_image_enhancer_batch',
            'Batch Reprocessing Defaults',
            [$this, 'batch_section_callback'],
            'ace-image-enhancer'
        );

        add_settings_field(
            'batch_default_types',
            'File Types to Reprocess',
            [$this, 'batch_types_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_batch'
        );

        add_settings_field(
            'batch_overwrite',
            'Overwrite Existing Conversions',
            [$this, 'batch_overwrite_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_batch'
        );

        add_settings_field(
            'batch_default_date_after',
            'Only Images Uploaded After',
            [$this, 'batch_date_field_callback'],
            'ace-image-enhancer',
            'ace_image_enhancer_batch'
        );
    }

    public function section_callback() {
        echo '<p>Configure how uploaded images are processed and served.</p>';
    }

    public function format_field_callback() {
        $value = $this->get_option('image_format');
        $avif_supported = extension_loaded('gd') && function_exists('imageavif');
        ?>
        <select name="ace_image_enhancer_options[image_format]">
            <option value="webp" <?php selected($value, 'webp'); ?>>WebP (Best compatibility)</option>
            <option value="avif" <?php selected($value, 'avif'); ?> <?php disabled(!$avif_supported); ?>>
                AVIF (Better compression<?php echo !$avif_supported ? ' - Not available' : ''; ?>)
            </option>
            <option value="original" <?php selected($value, 'original'); ?>>Original (No conversion)</option>
        </select>
        <p class="description">Choose the format for converted images. WebP has best browser support, AVIF offers better compression.</p>
        <?php
    }

    public function quality_field_callback() {
        $value = $this->get_option('image_quality');
        ?>
        <input type="number" name="ace_image_enhancer_options[image_quality]" 
               value="<?php echo esc_attr($value); ?>" min="1" max="100" step="1" />
        <p class="description">Image quality (1-100). Higher = better quality but larger files. Default: 85</p>
        <?php
    }

    public function keep_originals_field_callback() {
        $value = $this->get_option('keep_originals');
        ?>
        <label>
            <input type="checkbox" name="ace_image_enhancer_options[keep_originals]" 
                   value="1" <?php checked($value, 1); ?> />
            Keep original files alongside converted versions
        </label>
        <p class="description">If enabled, original JPG/PNG files will be preserved in addition to WebP/AVIF versions.</p>
        <?php
    }

    public function replace_originals_field_callback() {
        $value = $this->get_option('replace_originals');
        ?>
        <label>
            <input type="checkbox" name="ace_image_enhancer_options[replace_originals]"
                   value="1" <?php checked($value, 1); ?> />
            Allow this plugin to replace or delete original raster files
        </label>
        <p class="description">Leave disabled unless you explicitly want original files removed during reprocessing. Uploads always preserve the original file.</p>
        <?php
    }

    public function svg_field_callback() {
        $value = $this->get_option('enable_svg');
        echo '<label><input type="checkbox" name="ace_image_enhancer_options[enable_svg]" value="1" ' . checked($value, true, false) . ' /> Enable sanitised SVG uploads</label>';
        echo '<p class="description">Requires PHP DOM. Compressed SVGZ files, scripts, embedded documents and external references are rejected.</p>';
    }

    public function svg_editor_field_callback() {
        $value = $this->get_option('enable_svg_editor');
        ?>
        <label>
            <input type="checkbox" name="ace_image_enhancer_options[enable_svg_editor]" 
                   value="1" <?php checked($value, 1); ?> />
            Enable experimental SVG editor integration
        </label>
        <p class="description" style="color: #d63638;">
            ⚠️ <strong>Experimental Feature:</strong> Adds an "Edit SVG" button to the media library that opens 
            SVG files in an embedded editor. This feature is still in development and may have issues.
        </p>
        <?php
    }

    public function sanitize_options($input) {
        $sanitized = [];

        $sanitized['image_format'] = in_array($input['image_format'] ?? '', ['webp', 'avif', 'original'])
            ? $input['image_format']
            : 'webp';

        $sanitized['image_quality']     = max(1, min(100, intval($input['image_quality'] ?? 85)));
        $sanitized['enable_svg']        = !empty($input['enable_svg']);
        $sanitized['keep_originals']    = !empty($input['keep_originals']);
        $sanitized['replace_originals'] = !empty($input['replace_originals']);
        $sanitized['enable_svg_editor'] = !empty($input['enable_svg_editor']);

        // Batch defaults
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $input_types   = isset($input['batch_default_types']) && is_array($input['batch_default_types'])
            ? $input['batch_default_types']
            : [];
        $sanitized['batch_default_types']    = array_values(array_intersect($input_types, $allowed_types));
        $sanitized['batch_overwrite']        = !empty($input['batch_overwrite']);
        $sanitized['batch_default_date_after'] = sanitize_text_field($input['batch_default_date_after'] ?? '');

        return $sanitized;
    }

    // ---------------------------------------------------------
    // Batch settings field callbacks
    // ---------------------------------------------------------
    public function batch_section_callback() {
        echo '<p>These defaults pre-populate the <a href="' . esc_url(admin_url('upload.php?page=ace-image-reprocess')) . '">Reprocess Images</a> tool.</p>';
    }

    public function batch_types_field_callback() {
        $selected = $this->get_option('batch_default_types');
        $types    = ['jpg' => 'JPEG (.jpg)', 'jpeg' => 'JPEG (.jpeg)', 'png' => 'PNG', 'gif' => 'GIF', 'bmp' => 'BMP'];
        foreach ($types as $val => $label) {
            $checked = in_array($val, (array) $selected, true) ? 'checked' : '';
            echo '<label style="margin-right:12px"><input type="checkbox" name="ace_image_enhancer_options[batch_default_types][]" value="' . esc_attr($val) . '" ' . $checked . '> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">File types to include when batch reprocessing.</p>';
    }

    public function batch_overwrite_field_callback() {
        $value = $this->get_option('batch_overwrite');
        echo '<label><input type="checkbox" name="ace_image_enhancer_options[batch_overwrite]" value="1" ' . checked($value, true, false) . '> Re-convert images that already have a WebP/AVIF version</label>';
        echo '<p class="description">Leave unchecked to skip already-converted images (faster).</p>';
    }

    public function batch_date_field_callback() {
        $value = $this->get_option('batch_default_date_after');
        echo '<input type="date" name="ace_image_enhancer_options[batch_default_date_after]" value="' . esc_attr($value) . '">';
        echo '<p class="description">Only reprocess images uploaded on or after this date. Leave blank for all images.</p>';
    }

    // ---------------------------------------------------------
    // Batch reprocess admin page
    // ---------------------------------------------------------
    public function render_reprocess_page() {
        if (!current_user_can('upload_files')) {
            return;
        }
        $opts   = $this->options;
        $types  = (array) $this->get_option('batch_default_types');
        $over   = (bool)  $this->get_option('batch_overwrite');
        $after  = (string)$this->get_option('batch_default_date_after');
        $format = $this->get_option('image_format');
        $all_types = ['jpg' => 'JPEG (.jpg)', 'jpeg' => 'JPEG (.jpeg)', 'png' => 'PNG', 'gif' => 'GIF', 'bmp' => 'BMP'];
        ?>
        <div class="wrap ace-reprocess-wrap">
            <h1>Reprocess Images</h1>
            <p>Convert existing media library images to <strong><?php echo esc_html(strtoupper($format)); ?></strong>.
            Images uploaded before this tool existed won't be converted automatically — use this to back-fill them.</p>
            <?php if ($format === 'original'): ?>
            <div class="notice notice-warning"><p><strong>Note:</strong> Image format is currently set to "Original" in
            <a href="<?php echo esc_url(admin_url('upload.php?page=ace-image-enhancer')); ?>">Image Enhancer Settings</a>.
            No conversion will occur until you choose WebP or AVIF.</p></div>
            <?php endif; ?>

            <div class="ace-reprocess-layout">
                <!-- Filter form -->
                <div class="ace-reprocess-filters card">
                    <h2>Filters</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th>File Types</th>
                            <td><?php foreach ($all_types as $val => $label):
                                $chk = in_array($val, $types, true) ? 'checked' : ''; ?>
                                <label style="display:block;margin-bottom:4px">
                                    <input type="checkbox" class="ace-filter-type" value="<?php echo esc_attr($val); ?>" <?php echo $chk ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?></td>
                        </tr>
                        <tr>
                            <th>Uploaded after</th>
                            <td><input type="date" id="ace-filter-date" value="<?php echo esc_attr($after); ?>">
                            <p class="description">Leave blank for all dates.</p></td>
                        </tr>
                        <tr>
                            <th>Overwrite</th>
                            <td><label><input type="checkbox" id="ace-overwrite" <?php checked($over); ?>>
                            Re-convert images that already have a <?php echo esc_html(strtoupper($format)); ?> version</label></td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="ace-count-btn" class="button">Preview count</button>
                        <span id="ace-count-result" style="margin-left:10px;font-weight:600"></span>
                    </p>
                    <p>
                        <button type="button" id="ace-start-btn" class="button button-primary">Start reprocessing</button>
                        <button type="button" id="ace-pause-btn" class="button" style="display:none">Pause</button>
                        <button type="button" id="ace-resume-btn" class="button" style="display:none">Resume</button>
                    </p>
                </div>

                <!-- Progress + log -->
                <div class="ace-reprocess-progress card">
                    <h2>Progress</h2>
                    <div class="ace-progress-bar-wrap">
                        <div id="ace-progress-bar" class="ace-progress-bar" style="width:0%"></div>
                    </div>
                    <p id="ace-progress-text" style="margin-top:6px">—</p>
                    <div id="ace-log" class="ace-log"></div>
                </div>
            </div>

            <div class="ace-reprocess-content-section" style="margin-top:20px;">
                <h2>Quick Reprocess from Recent Content</h2>
                <p>Reprocess images used in the most recent posts and pages. This automatically finds and deduplicates all images currently in use.</p>
                <p>
                    <label>
                        <input type="checkbox" id="ace-include-pages" checked>
                        Include pages (not just posts)
                    </label>
                    <label style="margin-left:20px">
                        Number of recent posts/pages: <input type="number" id="ace-content-count" value="100" min="1" max="500" style="width:80px">
                    </label>
                </p>
                <p>
                    <button type="button" id="ace-content-preview-btn" class="button">Preview Images to Process</button>
                    <span id="ace-content-preview-result" style="margin-left:10px;font-weight:600"></span>
                </p>
                <p>
                    <button type="button" id="ace-content-start-btn" class="button button-primary" style="display:none">Start Reprocessing from Content</button>
                </p>
            </div>
            <div class="ace-reprocess-content-section" style="margin-top:20px;">
                <h2>Reprocess Author Avatars</h2>
                <p>Find and reprocess all media-library avatars set by users on this site. Useful when authors have uploaded portrait images before the WebP/AVIF setting was active.</p>
                <p>
                    <button type="button" id="ace-avatar-preview-btn" class="button">Preview Avatar Images</button>
                    <span id="ace-avatar-preview-result" style="margin-left:10px;font-weight:600"></span>
                </p>
                <p>
                    <button type="button" id="ace-avatar-start-btn" class="button button-primary" style="display:none">Reprocess Avatar Images</button>
                </p>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('ace_image_enhancer');
                do_settings_sections('ace-image-enhancer');
                submit_button('Save Settings');
                ?>
            </form>
            
            <hr>
            
            <h2>System Information</h2>
            <table class="widefat">
                <tr>
                    <td><strong>GD Library</strong></td>
                    <td><?php echo extension_loaded('gd') ? '✓ Enabled' : '✗ Disabled'; ?></td>
                </tr>
                <tr>
                    <td><strong>WebP Support</strong></td>
                    <td><?php echo (extension_loaded('gd') && function_exists('imagewebp')) ? '✓ Available' : '✗ Not Available'; ?></td>
                </tr>
                <tr>
                    <td><strong>AVIF Support</strong></td>
                    <td><?php echo (extension_loaded('gd') && function_exists('imageavif')) ? '✓ Available' : '✗ Not Available'; ?></td>
                </tr>
                <tr>
                    <td><strong>SVG Currently Allowed</strong></td>
                    <td>
                        <?php
                        // Check server capability
                        $has_simplexml = extension_loaded('simplexml');
                        $has_dom = extension_loaded('dom');
                        $has_libxml = extension_loaded('libxml');
                        $server_capable = $has_simplexml || ($has_dom && $has_libxml);
                        
                        // Check WordPress version
                        global $wp_version;
                        $wp_core_supports_svg = version_compare($wp_version, '6.3', '>=');
                        
                        // Check actual MIME types
                        $current_mimes = get_allowed_mime_types();
                        $svg_allowed = false;
                        foreach ($current_mimes as $exts => $mime) {
                            if ($mime === 'image/svg+xml') {
                                $svg_allowed = true;
                                break;
                            }
                        }
                        
                        // If WP 6.3+ and server capable, consider SVG natively supported
                        if ($wp_core_supports_svg && $server_capable) {
                            echo '✓ Allowed (native WordPress ' . $wp_version . ' support)';
                            if ($this->get_option('enable_svg')) {
                                echo '<br><em style="font-size: 11px;">+ Plugin sanitization layer active</em>';
                            }
                        } elseif ($svg_allowed) {
                            echo $this->get_option('enable_svg') 
                                ? '✓ Allowed (with this plugin\'s sanitization)' 
                                : '✓ Allowed (by another plugin/code)';
                        } else {
                            echo '✗ Not Allowed';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>WordPress Version</strong></td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <td><strong>SVG Capability Status</strong></td>
                    <td>
                        <?php
                        // Check server capability for SVG processing
                        $has_simplexml = extension_loaded('simplexml');
                        $has_libxml = extension_loaded('libxml');
                        $has_dom = extension_loaded('dom');
                        
                        // We can process SVG if we have at least one XML parsing method
                        $server_can_handle_svg = $has_simplexml || ($has_dom && $has_libxml);
                        
                        // Check WordPress version (6.3+ includes SVG support)
                        global $wp_version;
                        $wp_core_supports_svg = version_compare($wp_version, '6.3', '>=');
                        
                        // Check if SVG is currently in allowed MIME types
                        $current_mimes = get_allowed_mime_types();
                        $svg_currently_allowed = false;
                        foreach ($current_mimes as $exts => $mime) {
                            if ($mime === 'image/svg+xml') {
                                $svg_currently_allowed = true;
                                break;
                            }
                        }
                        
                        // If WP 6.3+ and server capable, SVG is natively supported
                        $native_svg_support = $wp_core_supports_svg && $server_can_handle_svg;
                        
                        if (!$server_can_handle_svg) {
                            echo '⚠️ <strong>Server limitation:</strong> Missing required XML processing extensions (needs SimpleXML or DOM+LibXML)';
                        } elseif ($native_svg_support) {
                            echo '✓ Server capable • ✓ WordPress ' . $wp_version . ' natively supports SVG • ✓ SVG uploads enabled';
                            if ($this->get_option('enable_svg')) {
                                echo '<br><em style="font-size: 12px;">Plugin sanitization layer active (additional security)</em>';
                            }
                        } elseif ($svg_currently_allowed) {
                            echo '✓ Server capable • ✓ SVG uploads enabled' . 
                                 ($this->get_option('enable_svg') ? ' (with this plugin\'s sanitization)' : ' (by another plugin/code)');
                        } else {
                            echo '✓ Server capable • ⚠️ WordPress ' . $wp_version . ' (older version) • SVG not supported in core (enable plugin option)';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="background: #f0f0f1; padding: 10px; font-size: 12px;">
                        <strong>About SVG Support:</strong> This plugin enables secure SVG uploads with XSS protection by sanitizing 
                        potentially dangerous elements like scripts and event handlers.
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    // ---------------------------------------------------------
    // REST API — Batch reprocess & rename
    // ---------------------------------------------------------
    public function register_rest_routes() {
        $ns         = 'ace-image-enhancer/v1';
        $type_items = ['type' => 'string', 'enum' => ['jpg', 'jpeg', 'png', 'gif', 'bmp']];

        register_rest_route($ns, '/batch-count', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_batch_count'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args'                => [
                'file_types' => ['type' => 'array', 'items' => $type_items, 'default' => ['jpg', 'jpeg', 'png']],
                'date_after' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'overwrite'  => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($ns, '/batch-run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_batch_run'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args'                => [
                'file_types'  => ['type' => 'array', 'items' => $type_items, 'default' => ['jpg', 'jpeg', 'png']],
                'date_after'  => ['type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'batch_size'  => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 200],
                'offset'      => ['type' => 'integer', 'default' => 0,  'minimum' => 0],
                'overwrite'   => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($ns, '/batch-from-content', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_batch_from_content'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args'                => [
                'post_count' => ['type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500],
                'include_pages' => ['type' => 'boolean', 'default' => true],
            ],
        ]);

        register_rest_route($ns, '/reprocess-single', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_reprocess_single'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args'                => [
                'attachment_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ],
        ]);

        register_rest_route($ns, '/rename', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_rename'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args'                => [
                'attachment_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'new_name'      => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_file_name'],
            ],
        ]);

        register_rest_route($ns, '/batch-from-avatars', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_batch_from_avatars'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
    }

    // ---------------------------------------------------------
    // REST helpers
    // ---------------------------------------------------------

    /** Map simple extension names to WP MIME type strings. */
    private function types_to_mimes(array $types): array {
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
        ];
        $mimes = [];
        foreach ($types as $t) {
            if (isset($map[$t])) {
                $mimes[] = $map[$t];
            }
        }
        return array_values(array_unique($mimes));
    }

    /** Build a WP_Query args array for reprocessable attachments. */
    private function get_reprocessable_attachments(array $args): array {
        $defaults = [
            'file_types' => ['jpg', 'jpeg', 'png'],
            'date_after' => '',
            'batch_size' => 25,
            'offset'     => 0,
            'overwrite'  => false,
            'count_only' => false,
        ];
        $args  = wp_parse_args($args, $defaults);
        $mimes = $this->types_to_mimes($args['file_types']);

        if ($args['overwrite']) {
            $mimes = array_values(array_unique(array_merge($mimes, ['image/webp', 'image/avif'])));
        }

        if (empty($mimes)) {
            return $args['count_only'] ? ['count' => 0] : [];
        }

        $format = $this->get_option('image_format');
        $target = ($format === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';

        $query_args = [
            'post_type'               => 'attachment',
            'post_status'             => 'inherit',
            'post_mime_type'          => $mimes,
            'posts_per_page'          => $args['count_only'] ? -1 : (int) $args['batch_size'],
            'offset'                  => $args['count_only'] ? 0 : (int) $args['offset'],
            'fields'                  => 'ids',
            'no_found_rows'           => true,
            'update_post_meta_cache'  => false,
            'update_post_term_cache'  => false,
        ];

        // Exclude already-converted files at DB level — single JOIN, not N PHP get_attached_file() calls
        if (!$args['overwrite']) {
            $query_args['meta_query'] = [
                [
                    'key'     => '_wp_attached_file',
                    'value'   => '.' . $target,
                    'compare' => 'NOT LIKE',
                ],
            ];
        }

        if (!empty($args['date_after'])) {
            $query_args['date_query'] = [['after' => sanitize_text_field($args['date_after']), 'inclusive' => false]];
        }

        $query = new WP_Query($query_args);
        $ids   = $query->posts;

        if ($args['count_only']) {
            return ['count' => count($ids)];
        }

        return array_values($ids);
    }

    private function resolve_reprocess_source_file(string $file): ?string {
        if ($file === '' || !$this->safe_upload_path($file) || !file_exists($file)) {
            return null;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp'], true)) {
            return $file;
        }

        if (!in_array($ext, ['webp', 'avif'], true)) {
            return null;
        }

        foreach (['jpg', 'jpeg', 'png', 'gif', 'bmp'] as $candidate_ext) {
            $candidate = preg_replace('/\.[a-z0-9]+$/i', '.' . $candidate_ext, $file);
            if ($candidate && $this->safe_upload_path($candidate) && file_exists($candidate)) {
                return $candidate;
            }
        }

        return $file;
    }

    private function prepare_reprocess_destination(string $source_file, string $target_file): array {
        $save_file = $target_file;
        $replace_after_save = false;

        $source_real = realpath($source_file);
        $target_real = file_exists($target_file) ? realpath($target_file) : false;
        if ($source_real && $target_real && $source_real === $target_real) {
            $save_file = preg_replace('/\.[a-z0-9]+$/i', '.ace-reprocess-tmp.' . strtolower(pathinfo($target_file, PATHINFO_EXTENSION)), $target_file);
            $replace_after_save = true;
        }

        return [
            'save_file' => $save_file,
            'target_file' => $target_file,
            'replace_after_save' => $replace_after_save,
        ];
    }

    /** Convert a single attachment to the configured format. */
    private function convert_attachment_to_format(int $attachment_id): array {
        $file = get_attached_file($attachment_id);
        if (!current_user_can('edit_post', $attachment_id) || get_post_type($attachment_id) !== 'attachment') return ['status' => 'skipped', 'reason' => 'permission_denied', 'id' => $attachment_id];
        if (!$file || !$this->safe_upload_path($file) || !file_exists($file)) {
            return ['status' => 'skipped', 'reason' => 'no_source', 'id' => $attachment_id];
        }

        $ext    = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $format = $this->get_option('image_format');

        if ($format === 'original') {
            return ['status' => 'skipped', 'reason' => 'format_original', 'id' => $attachment_id];
        }

        $target = ($format === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';

        $source_file = $this->resolve_reprocess_source_file($file);
        if (!$source_file) {
            return ['status' => 'skipped', 'reason' => 'unsupported_type', 'id' => $attachment_id];
        }

        $upload_dir = wp_upload_dir();
        $mime_type  = 'image/' . $target;
        $dest       = preg_replace('/\.[a-z]+$/i', '.' . $target, $file);
        $destination = $this->prepare_reprocess_destination($source_file, $dest);

        if (!$this->safe_upload_path($destination['save_file'], false) || !$this->safe_upload_path($destination['target_file'], false)) return ['status' => 'failed', 'reason' => 'unsafe_destination', 'id' => $attachment_id];

        // --- Convert main file ---
        $image = wp_get_image_editor($source_file);
        if (is_wp_error($image)) {
            return ['status' => 'failed', 'reason' => $image->get_error_message(), 'id' => $attachment_id];
        }
        $image->set_quality($this->get_option('image_quality'));
        $saved = $image->save($destination['save_file'], $mime_type);
        unset($image); // free GD resource immediately — do not wait for GC

        if (is_wp_error($saved)) {
            return ['status' => 'failed', 'reason' => $saved->get_error_message(), 'id' => $attachment_id];
        }

        if ($destination['replace_after_save']) {
            @rename($destination['save_file'], $destination['target_file']);
        }
        $this->forget_modern_check($destination['target_file']);

        // --- Convert existing thumbnail sizes in-place ---
        // We do NOT call wp_generate_attachment_metadata() here because it reloads the
        // full-size source image from disk for every thumbnail size it regenerates.
        // On a batch of 25 images with 10+ registered sizes this exhausts 128 MB.
        // Instead we convert the already-generated thumbnail files one at a time,
        // freeing the GD resource after each one.
        $meta = wp_get_attachment_metadata($attachment_id) ?: [];
        $dir  = trailingslashit(dirname($file));

        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_name => $size_data) {
                $old_thumb = $dir . $size_data['file'];
                $thumb_source = $this->resolve_reprocess_source_file($old_thumb);
                if (!$thumb_source) {
                    continue;
                }
                $new_thumb = preg_replace('/\.[a-z]+$/i', '.' . $target, $old_thumb);
                $thumb_destination = $this->prepare_reprocess_destination($thumb_source, $new_thumb);
                if (!$this->safe_upload_path($thumb_destination['save_file'], false) || !$this->safe_upload_path($thumb_destination['target_file'], false)) continue;
                $thumb_img = wp_get_image_editor($thumb_source);
                if (is_wp_error($thumb_img)) {
                    continue;
                }
                $thumb_img->set_quality($this->get_option('image_quality'));
                $thumb_saved = $thumb_img->save($thumb_destination['save_file'], $mime_type);
                unset($thumb_img); // free immediately

                if (!is_wp_error($thumb_saved)) {
                    if ($thumb_destination['replace_after_save']) {
                        @rename($thumb_destination['save_file'], $thumb_destination['target_file']);
                    }
                    $this->forget_modern_check($thumb_destination['target_file']);
                    if (!$this->get_option('keep_originals') && $this->should_replace_originals() && $thumb_source !== $thumb_destination['target_file'] && file_exists($thumb_source)) {
                        @unlink($thumb_source);
                    }
                    $meta['sizes'][$size_name]['file']      = basename($thumb_destination['target_file']);
                    $meta['sizes'][$size_name]['mime-type'] = $mime_type;
                }
            }
        }

        // --- Update DB records ---
        $relative = ltrim(str_replace($upload_dir['basedir'], '', $dest), '/');
        if (isset($meta['file'])) {
            $meta['file'] = $relative;
        }
        wp_update_attachment_metadata($attachment_id, $meta);
        update_post_meta($attachment_id, '_wp_attached_file', $relative);
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $mime_type]);

        // --- Optionally delete original main file ---
        if (!$this->get_option('keep_originals') && $this->should_replace_originals() && $source_file !== $dest && file_exists($source_file)) {
            @unlink($source_file);
        }

        // Nudge GC between images so fragmented GD heap is released before next iteration
        gc_collect_cycles();

        return [
            'status'  => 'processed',
            'id'      => $attachment_id,
            'new_url' => $upload_dir['baseurl'] . '/' . $relative,
            'title'   => get_the_title($attachment_id),
        ];
    }

    public function rest_batch_count(\WP_REST_Request $request): \WP_REST_Response {
        $result = $this->get_reprocessable_attachments([
            'file_types' => (array) $request->get_param('file_types'),
            'date_after' => $request->get_param('date_after'),
            'overwrite'  => (bool) $request->get_param('overwrite'),
            'count_only' => true,
        ]);
        return rest_ensure_response(['count' => $result['count']]);
    }

    public function rest_batch_run(\WP_REST_Request $request): \WP_REST_Response {
        $file_types = (array) $request->get_param('file_types');
        $date_after = $request->get_param('date_after');
        $batch_size = (int) $request->get_param('batch_size');
        $offset     = (int) $request->get_param('offset');
        $overwrite  = (bool) $request->get_param('overwrite');

        // Get total count for progress calculation
        $count_result = $this->get_reprocessable_attachments([
            'file_types' => $file_types,
            'date_after' => $date_after,
            'overwrite'  => $overwrite,
            'count_only' => true,
        ]);
        $total = $count_result['count'];

        // Get this batch's IDs
        $ids = $this->get_reprocessable_attachments([
            'file_types' => $file_types,
            'date_after' => $date_after,
            'overwrite'  => $overwrite,
            'batch_size' => $batch_size,
            'offset'     => $offset,
        ]);

        $counters = ['processed' => 0, 'skipped' => 0, 'failed' => 0];
        $log      = [];

        foreach ((array) $ids as $id) {
            $result = $this->convert_attachment_to_format((int) $id);
            $status = $result['status'];
            if (isset($counters[$status])) {
                $counters[$status]++;
            } else {
                $counters['skipped']++;
            }
            $log[] = [
                'id'     => $id,
                'title'  => $result['title'] ?? get_the_title($id),
                'status' => $status,
                'reason' => $result['reason'] ?? '',
                'url'    => $result['new_url'] ?? '',
            ];
        }

        $done     = $offset + count($ids);
        $complete = ($done >= $total) || empty($ids);

        return rest_ensure_response(array_merge($counters, [
            'total'    => $total,
            'offset'   => $done,
            'complete' => $complete,
            'log'      => $log,
        ]));
    }

    public function rest_reprocess_single(\WP_REST_Request $request): \WP_REST_Response {
        $id = (int) $request->get_param('attachment_id');
        if (!current_user_can('edit_post', $id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $result = $this->convert_attachment_to_format($id);
        return rest_ensure_response(array_merge(['success' => $result['status'] === 'processed'], $result));
    }

    private function collect_image_ids_from_blocks(array $blocks, array &$image_ids): void {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = isset($block['blockName']) ? (string) $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            switch ($block_name) {
                case 'core/image':
                case 'core/cover':
                case 'core/file':
                    if (!empty($attrs['id'])) {
                        $image_ids[] = (int) $attrs['id'];
                    }
                    break;

                case 'core/media-text':
                    if (!empty($attrs['mediaId'])) {
                        $image_ids[] = (int) $attrs['mediaId'];
                    }
                    break;

                case 'core/gallery':
                    if (!empty($attrs['ids']) && is_array($attrs['ids'])) {
                        foreach ($attrs['ids'] as $gallery_id) {
                            $image_ids[] = (int) $gallery_id;
                        }
                    }
                    if (!empty($attrs['images']) && is_array($attrs['images'])) {
                        foreach ($attrs['images'] as $image) {
                            if (is_array($image) && !empty($image['id'])) {
                                $image_ids[] = (int) $image['id'];
                            }
                        }
                    }
                    break;
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collect_image_ids_from_blocks($block['innerBlocks'], $image_ids);
            }
        }
    }

    public function rest_batch_from_content(\WP_REST_Request $request): \WP_REST_Response {
        $post_count = (int) $request->get_param('post_count');
        $include_pages = (bool) $request->get_param('include_pages');

        $post_types = ['post'];
        if ($include_pages) {
            $post_types[] = 'page';
        }

        // Get recent posts/pages
        $posts = get_posts([
            'post_type'      => $post_types,
            'posts_per_page' => $post_count,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);

        $image_ids = [];

        foreach ($posts as $post_id) {
            // Get featured image
            $featured_id = get_post_thumbnail_id($post_id);
            if ($featured_id) {
                $image_ids[] = $featured_id;
            }

            // Get images from content
            $content = get_post_field('post_content', $post_id);
            if ($content) {
                $blocks = parse_blocks($content);
                if (!empty($blocks)) {
                    $this->collect_image_ids_from_blocks($blocks, $image_ids);
                }

                // Find all image IDs in content (from img tags and gallery shortcodes)
                preg_match_all('/wp-image-(\d+)/', $content, $matches);
                if (!empty($matches[1])) {
                    $image_ids = array_merge($image_ids, $matches[1]);
                }

                // Also check for gallery shortcodes
                preg_match_all('/\[gallery.*ids="([^"]+)"/', $content, $gallery_matches);
                foreach ($gallery_matches[1] as $ids_str) {
                    $gallery_ids = explode(',', $ids_str);
                    $image_ids = array_merge($image_ids, $gallery_ids);
                }
            }
        }

        // Deduplicate and validate
        $image_ids = array_unique(array_map('intval', array_filter($image_ids)));
        $valid_images = [];

        foreach ($image_ids as $id) {
            $file = get_attached_file($id);
            if ($file && file_exists($file) && $this->resolve_reprocess_source_file($file)) {
                $valid_images[] = $id;
            }
        }

        return rest_ensure_response([
            'count' => count($valid_images),
            'image_ids' => array_values($valid_images),
            'post_count' => count($posts),
        ]);
    }

    public function rest_batch_from_avatars(\WP_REST_Request $request): \WP_REST_Response {
        $users = get_users([
            'fields'  => 'ID',
            'number'  => -1,
        ]);

        $image_ids   = [];
        $user_count  = 0;

        foreach ($users as $user_id) {
            $user_id = (int) $user_id;

            // Primary: custom_avatar_id set by set-avatar plugin
            $attachment_id = (int) get_user_meta($user_id, 'custom_avatar_id', true);

            // Fallback: resolve from the URL stored in custom_avatar
            if ($attachment_id <= 0) {
                $avatar_url = get_user_meta($user_id, 'custom_avatar', true);
                if ($avatar_url) {
                    $resolved = attachment_url_to_postid($avatar_url);
                    if ($resolved > 0) {
                        $attachment_id = $resolved;
                        // Cache the resolved ID for future requests
                        update_user_meta($user_id, 'custom_avatar_id', $attachment_id);
                    }
                }
            }

            // Also check common avatar plugin meta keys for broader compatibility
            if ($attachment_id <= 0) {
                foreach (['wp_user_avatar', 'simple_local_avatar'] as $meta_key) {
                    $val = get_user_meta($user_id, $meta_key, true);
                    if (is_numeric($val) && (int) $val > 0) {
                        $attachment_id = (int) $val;
                        break;
                    }
                    if ($val && filter_var($val, FILTER_VALIDATE_URL)) {
                        $resolved = attachment_url_to_postid($val);
                        if ($resolved > 0) {
                            $attachment_id = $resolved;
                            break;
                        }
                    }
                }
            }

            if ($attachment_id > 0) {
                $image_ids[] = $attachment_id;
                $user_count++;
            }
        }

        // Deduplicate and validate that the file exists and is reprocessable
        $image_ids   = array_unique(array_map('intval', array_filter($image_ids)));
        $valid_images = [];

        foreach ($image_ids as $id) {
            $file = get_attached_file($id);
            if ($file && file_exists($file) && $this->resolve_reprocess_source_file($file)) {
                $valid_images[] = $id;
            }
        }

        return rest_ensure_response([
            'count'      => count($valid_images),
            'image_ids'  => array_values($valid_images),
            'user_count' => $user_count,
        ]);
    }

    // ---------------------------------------------------------
    // Rename: file system + reference updates
    // ---------------------------------------------------------
    private function rename_attachment_files(int $attachment_id, string $new_basename) {
        $file = get_attached_file($attachment_id);
        if (!$file || !$this->safe_upload_path($file)) {
            return new \WP_Error('no_file', 'Attachment file not found.');
        }

        $dir         = trailingslashit(dirname($file));
        $old_ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $old_basename = pathinfo($file, PATHINFO_FILENAME);

        // Sanitize new_basename: strip path separators, ensure non-empty
        $new_basename = sanitize_file_name($new_basename);
        $new_basename = str_replace(['/', '\\', '..'], '', $new_basename);
        $new_basename = trim($new_basename);
        if (empty($new_basename)) {
            return new \WP_Error('invalid_name', 'New filename is empty.');
        }

        $meta          = wp_get_attachment_metadata($attachment_id);
        $upload_dir    = wp_upload_dir();
        $old_url_base  = $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dir), '/');

        // Build map: old path => new path for all file variants
        $rename_map = [];

        // Modern format variants of main file (webp/avif)
        foreach (['webp', 'avif'] as $fmt) {
            $old_modern = $dir . $old_basename . '.' . $fmt;
            if (file_exists($old_modern)) {
                $rename_map[$old_modern] = $dir . $new_basename . '.' . $fmt;
            }
        }

        // Main file itself
        $old_main = $dir . $old_basename . '.' . $old_ext;
        if (file_exists($old_main)) {
            $rename_map[$old_main] = $dir . $new_basename . '.' . $old_ext;
        }

        // Thumbnail sizes
        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_data) {
                $thumb_file = $dir . $size_data['file'];
                if (file_exists($thumb_file)) {
                    $thumb_ext      = strtolower(pathinfo($thumb_file, PATHINFO_EXTENSION));
                    $thumb_basename = pathinfo($thumb_file, PATHINFO_FILENAME);
                    // Derive suffix (e.g. "-300x200") from old thumb basename vs old_basename
                    $suffix         = substr($thumb_basename, strlen($old_basename));
                    $new_thumb      = $dir . $new_basename . $suffix . '.' . $thumb_ext;
                    $rename_map[$thumb_file] = $new_thumb;

                    // WebP/AVIF variants of thumbnails
                    foreach (['webp', 'avif'] as $fmt) {
                        $old_t_modern = $dir . $thumb_basename . '.' . $fmt;
                        if (file_exists($old_t_modern)) {
                            $rename_map[$old_t_modern] = $dir . $new_basename . $suffix . '.' . $fmt;
                        }
                    }
                }
            }
        }

        foreach ($rename_map as $old => $new) {
            if (!$this->safe_upload_path($old) || !$this->safe_upload_path($new, false) || ($old !== $new && file_exists($new))) return new \WP_Error('unsafe_destination', 'A destination already exists or a file is outside uploads.');
        }
        $completed = [];
        $renamed = [];
        foreach ($rename_map as $old_path => $new_path) {
            if ($old_path === $new_path) {
                continue;
            }
            if (@rename($old_path, $new_path)) {
                $renamed[] = basename($new_path);
                $completed[$old_path] = $new_path;
            } else {
                foreach (array_reverse($completed, true) as $old => $new) rename($new, $old);
                return new \WP_Error('rename_failed', 'Could not rename all files; earlier moves were rolled back.');
            }
        }

        // Determine primary new file (prefer webp/avif if it was the stored file, else use original ext)
        $primary_new_ext = $old_ext;
        foreach (['webp', 'avif'] as $fmt) {
            $modern_check = $dir . $new_basename . '.' . $fmt;
            if (file_exists($modern_check)) {
                $primary_new_ext = $fmt;
                break;
            }
        }

        $new_file    = $dir . $new_basename . '.' . $primary_new_ext;
        $old_rel     = ltrim(str_replace($upload_dir['basedir'], '', $file), '/');
        $new_rel     = ltrim(str_replace($upload_dir['basedir'], '', $new_file), '/');
        $old_url     = $upload_dir['baseurl'] . '/' . $old_rel;
        $new_url     = $upload_dir['baseurl'] . '/' . $new_rel;

        return [
            'old_url'       => $old_url,
            'new_url'       => $new_url,
            'old_basename'  => $old_basename,
            'new_basename'  => $new_basename,
            'new_file'      => $new_file,
            'new_rel'       => $new_rel,
            'renamed_files' => $renamed,
        ];
    }

    /** Recursively replace a string in potentially serialized data. */
    private function recursive_str_replace(string $search, string $replace, $data) {
        if (is_string($data)) {
            return str_replace($search, $replace, $data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->recursive_str_replace($search, $replace, $v);
            }
        }
        return $data;
    }

    private function update_attachment_references(int $id, string $old_url, string $new_url, string $old_basename, string $new_basename): array {
        global $wpdb;
        $counts = ['posts' => 0, 'postmeta' => 0, 'options' => 0];

        // 1. Post content
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $counts['posts'] += (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $old_url, $new_url, '%' . $wpdb->esc_like($old_basename) . '%'
            )
        );

        // 2. Attachment GUID
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($wpdb->posts, ['guid' => $new_url], ['ID' => $id]);

        // 3. _wp_attachment_metadata — update file paths inside the array
        $meta = wp_get_attachment_metadata($id);
        if ($meta) {
            $meta = $this->recursive_str_replace($old_basename, $new_basename, $meta);
            // Re-point the root file key
            if (!empty($meta['file'])) {
                $meta['file'] = str_replace($old_basename, $new_basename, $meta['file']);
            }
            wp_update_attachment_metadata($id, $meta);
        }

        // 4. Update _wp_attached_file — keep as a relative path, not a full URL
        $old_attached = get_post_meta($id, '_wp_attached_file', true);
        $new_attached  = str_replace($old_basename, $new_basename, (string) $old_attached);
        update_post_meta($id, '_wp_attached_file', $new_attached);

        // 6. Postmeta — any meta values containing old basename
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND post_id != %d",
                '%' . $wpdb->esc_like($old_basename) . '%',
                $id
            )
        );
        foreach ($rows as $row) {
            $unserialized = maybe_unserialize($row->meta_value);
            $updated      = $this->recursive_str_replace($old_url, $new_url, $unserialized);
            $updated      = $this->recursive_str_replace($old_basename, $new_basename, $updated);
            $serialized   = maybe_serialize($updated);
            if ($serialized !== $row->meta_value) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($wpdb->postmeta, ['meta_value' => $serialized], ['meta_id' => $row->meta_id]);
                $counts['postmeta']++;
            }
        }

        // 7. Options table — skip protected keys
        $protected = ['siteurl', 'home', 'blogname', 'blogdescription', 'admin_email', 'blogurl', 'template', 'stylesheet', 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt', 'user_roles', 'rewrite_rules', 'active_plugins', 'active_sitewide_plugins'];
        $protected_list = implode("','", array_map('esc_sql', $protected));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $opt_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT IN ('{$protected_list}')",
                '%' . $wpdb->esc_like($old_basename) . '%'
            )
        );
        foreach ($opt_rows as $row) {
            $unserialized = maybe_unserialize($row->option_value);
            $updated      = $this->recursive_str_replace($old_url, $new_url, $unserialized);
            $updated      = $this->recursive_str_replace($old_basename, $new_basename, $updated);
            $serialized   = maybe_serialize($updated);
            if ($serialized !== $row->option_value) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($wpdb->options, ['option_value' => $serialized], ['option_id' => $row->option_id]);
                $counts['options']++;
            }
        }

        return $counts;
    }

    public function rest_rename(\WP_REST_Request $request): \WP_REST_Response {
        $id       = (int) $request->get_param('attachment_id');
        $new_name = sanitize_file_name($request->get_param('new_name'));

        if (!get_post($id) || get_post_type($id) !== 'attachment') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid attachment.'], 400);
        }

        if (!current_user_can('edit_post', $id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Permission denied.'], 403);
        }

        $rename = $this->rename_attachment_files($id, $new_name);

        if (is_wp_error($rename)) {
            return new \WP_REST_Response(['success' => false, 'message' => $rename->get_error_message()], 400);
        }

        if (empty($rename['renamed_files'])) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No files were renamed. Check that the file exists and the uploads directory is writable.',
            ], 500);
        }

        $refs = $this->update_attachment_references(
            $id,
            $rename['old_url'],
            $rename['new_url'],
            $rename['old_basename'],
            $rename['new_basename']
        );

        clean_attachment_cache($id);

        return rest_ensure_response([
            'success'             => true,
            'old_url'             => $rename['old_url'],
            'new_url'             => $rename['new_url'],
            'renamed_files'       => $rename['renamed_files'],
            'references_updated'  => $refs,
        ]);
    }

    // ---------------------------------------------------------
    // Enqueue reprocess tools (batch page + attachment tools)
    // ---------------------------------------------------------
    public function enqueue_reprocess_tools(string $hook): void {
        $is_batch_page  = $hook === 'media_page_ace-image-reprocess';
        $is_attach_page = in_array($hook, ['post.php', 'upload.php'], true);

        if (!$is_batch_page && !$is_attach_page) {
            return;
        }

        $ver = '1.4.1';
        $base = plugins_url('', __FILE__);

        wp_enqueue_style('ace-reprocess', $base . '/css/reprocess.css', [], $ver);

        if ($is_batch_page) {
            wp_enqueue_script('ace-reprocess', $base . '/js/reprocess.js', [], $ver, true);
            wp_localize_script('ace-reprocess', 'aceReprocess', [
                'restUrl'    => rest_url('ace-image-enhancer/v1/'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'format'     => strtoupper($this->get_option('image_format')),
                'defaults'   => [
                    'types'      => $this->get_option('batch_default_types'),
                    'overwrite'  => $this->get_option('batch_overwrite'),
                    'date_after' => $this->get_option('batch_default_date_after'),
                ],
            ]);
        }

        if ($is_attach_page) {
            wp_enqueue_script('ace-attachment-tools', $base . '/js/attachment-tools.js', [], $ver, true);
            wp_localize_script('ace-attachment-tools', 'aceAttachTools', [
                'restUrl' => rest_url('ace-image-enhancer/v1/'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'format'  => strtoupper($this->get_option('image_format')),
            ]);
        }
    }

    // ---------------------------------------------------------
    // SVG Support with Security Sanitization
    // ---------------------------------------------------------
    // WordPress core intentionally doesn't allow SVG uploads by default due to
    // security concerns (SVGs can contain XSS attacks). This plugin adds SVG
    // support with automatic sanitization to strip dangerous elements.

    public function allow_svg_upload($mimes) {
        if ($this->get_option('enable_svg') && class_exists('DOMDocument')) {
            $mimes['svg'] = 'image/svg+xml';
        }
        unset($mimes['svgz']);
        return $mimes;
    }

    public function sanitize_svg_prefilter($file) {
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['svg', 'svgz'], true) && ($file['type'] ?? '') !== 'image/svg+xml') return $file;
        if ($ext !== 'svg' || !$this->get_option('enable_svg') || !class_exists('DOMDocument')) {
            $file['error'] = 'SVG uploads are disabled or unsupported.';
            return $file;
        }
        $path = $file['tmp_name'] ?? '';
        $content = is_file($path) && filesize($path) <= 5 * 1024 * 1024 ? file_get_contents($path) : false;
        $clean = $content === false ? false : $this->sanitize_svg_content($content);
        if ($clean === false || file_put_contents($path, $clean) !== strlen($clean)) {
            $file['error'] = 'Invalid or unsafe SVG file.';
        }
        return $file;
    }

    public function fix_svg_mime_check($data, $file, $filename, $mimes, $real_mime) {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            $data['ext'] = 'svg';
            $data['type'] = 'image/svg+xml';
        }
        return $data;
    }

    private function sanitize_svg_content($svg) {
        if (!is_string($svg) || strlen($svg) > 5 * 1024 * 1024 || !class_exists('DOMDocument') || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) return false;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadXML($svg, LIBXML_NONET) || $doc->doctype || !$doc->documentElement || $doc->documentElement->localName !== 'svg') return false;
            $elements = ['svg','g','defs','symbol','use','path','rect','circle','ellipse','line','polyline','polygon','text','tspan','title','desc','linearGradient','radialGradient','stop','clipPath','mask','pattern'];
            $attributes = explode(' ', 'id class viewBox width height x y x1 y1 x2 y2 cx cy r rx ry d points transform fill fill-opacity fill-rule stroke stroke-width stroke-opacity stroke-linecap stroke-linejoin stroke-dasharray stroke-dashoffset opacity offset stop-color stop-opacity gradientUnits gradientTransform spreadMethod fx fy fr clip-path clip-rule mask patternUnits patternContentUnits patternTransform preserveAspectRatio font-size font-family font-weight text-anchor dx dy href');
            foreach ($doc->getElementsByTagName('*') as $node) {
                if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($node->localName, $elements, true)) return false;
                foreach ($node->attributes as $attr) {
                    if ($attr->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
                    if ($attr->namespaceURI && !($attr->namespaceURI === 'http://www.w3.org/1999/xlink' && $attr->localName === 'href')) return false;
                    if (!in_array($attr->localName, $attributes, true)) return false;
                    $value = trim($attr->value);
                    if ($attr->localName === 'href' && !preg_match('/^#[a-zA-Z_][a-zA-Z0-9_.:-]*$/D', $value)) return false;
                    if (preg_match('/url\s*\(/i', $value) && !preg_match('/^url\(#[a-zA-Z_][a-zA-Z0-9_.:-]*\)$/D', $value)) return false;
                    if (strpos($value, chr(92)) !== false || preg_match('/[\x00-\x08]|(?:javascript|data|https?):/i', $value)) return false;
                }
            }
            foreach ($doc->childNodes as $node) if ($node->nodeType === XML_PI_NODE) return false;
            return $doc->saveXML($doc->documentElement);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function safe_upload_path($path, $existing = true) {
        $uploads = wp_upload_dir();
        $root = realpath($uploads['basedir']);
        $resolved = $existing ? realpath($path) : realpath(dirname($path));
        if (!$root || !$resolved || is_link($path)) return false;
        if ($resolved !== $root && strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0) return false;
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) return false;
            $cursor = dirname($cursor);
        }
        return true;
    }

    // ---------------------------------------------------------
    // Raster Image: Convert and Serve as WebP / AVIF
    // ---------------------------------------------------------
    private function debug_log($message, array $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . (string) $value;
            }
        }

        error_log('[Ace Image Enhancer] ' . $message . ($parts ? ' ' . implode(' ', $parts) : ''));
    }

    private function get_image_mime_type($file) {
        if (!is_string($file) || $file === '' || !file_exists($file) || !is_readable($file)) {
            return '';
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($file) : '';
        if (!$mime) {
            $type = wp_check_filetype($file);
            $mime = $type['type'] ?? '';
        }

        return (string) $mime;
    }

    private function is_palette_png($file) {
        if ($this->get_image_mime_type($file) !== 'image/png' || !function_exists('imagecreatefrompng') || !function_exists('imageistruecolor')) {
            return false;
        }

        $image = @imagecreatefrompng($file);
        if (!$image) {
            return false;
        }

        $is_palette = !imageistruecolor($image);
        unset($image); // imagedestroy() is a no-op since PHP 8.0 and deprecated in 8.5.

        return $is_palette;
    }

    private function get_webp_skip_reason($file) {
        if (!is_string($file) || $file === '') {
            return 'missing_source';
        }

        if (!file_exists($file)) {
            return 'source_not_found';
        }

        if (!is_readable($file)) {
            return 'source_not_readable';
        }

        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return 'webp_not_supported';
        }

        $mime = $this->get_image_mime_type($file);
        if ($mime === 'image/svg+xml') {
            return 'svg_not_rasterized';
        }

        if ($mime === 'image/avif') {
            return 'avif_not_supported';
        }

        if ($mime === 'image/webp') {
            return 'already_webp';
        }

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return 'unsupported_mime';
        }

        if ($mime === 'image/png' && $this->is_palette_png($file)) {
            return 'palette_png_skipped';
        }

        return '';
    }

    private function can_generate_webp($file) {
        return $this->get_webp_skip_reason($file) === '';
    }

    private function safe_generate_webp($source, $destination) {
        if (!$this->safe_upload_path($source) || !$this->safe_upload_path($destination, false)) return false;
        $mime = $this->get_image_mime_type($source);
        $skip_reason = $this->get_webp_skip_reason($source);
        if ($skip_reason !== '') {
            $this->debug_log('WebP conversion skipped', [
                'source' => $source,
                'mime'   => $mime,
                'reason' => $skip_reason,
            ]);
            return false;
        }

        $destination_dir = dirname($destination);
        if (!is_dir($destination_dir) || !is_writable($destination_dir)) {
            $this->debug_log('WebP conversion skipped', [
                'source'      => $source,
                'destination' => $destination,
                'mime'        => $mime,
                'reason'      => 'destination_not_writable',
            ]);
            return false;
        }

        try {
            $image = wp_get_image_editor($source);
            if (is_wp_error($image)) {
                $this->debug_log('WebP conversion failed', [
                    'source'      => $source,
                    'destination' => $destination,
                    'mime'        => $mime,
                    'reason'      => $image->get_error_message(),
                ]);
                return false;
            }

            $this->debug_log('WebP conversion started', [
                'source'      => $source,
                'destination' => $destination,
                'mime'        => $mime,
                'editor'      => get_class($image),
            ]);

            $image->set_quality($this->get_option('image_quality'));
            $saved = $image->save($destination, 'image/webp');
            unset($image);

            if (is_wp_error($saved)) {
                $this->debug_log('WebP conversion failed', [
                    'source'      => $source,
                    'destination' => $destination,
                    'mime'        => $mime,
                    'reason'      => $saved->get_error_message(),
                ]);
                return false;
            }

            $this->forget_modern_check($destination);
            return true;
        } catch (\Throwable $e) {
            $this->debug_log('WebP conversion failed', [
                'source'      => $source,
                'destination' => $destination,
                'mime'        => $mime,
                'reason'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function should_replace_originals() {
        return (bool) $this->get_option('replace_originals');
    }

    public function override_image_quality($quality, $mime_type) {
        // Override WordPress default quality with our setting for WebP/AVIF
        if (in_array($mime_type, ['image/webp', 'image/avif'])) {
            return $this->get_option('image_quality');
        }
        return $quality;
    }
    
    public function handle_modern_image_upload($file) {
        if (!is_array($file) || empty($file['file'])) {
            return $file;
        }

        $src = $file['file'];
        $mime = $this->get_image_mime_type($src);

        if (strpos((string) ($file['type'] ?? $mime), 'svg') !== false) {
            return $file;
        }

        if (!$this->can_generate_webp($src)) {
            $this->debug_log('Upload WebP sidecar skipped', [
                'source' => $src,
                'mime'   => $mime,
                'reason' => $this->get_webp_skip_reason($src),
            ]);
            return $file;
        }

        $dest = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
        if (!$dest || $dest === $src) {
            $this->debug_log('Upload WebP sidecar skipped', [
                'source' => $src,
                'mime'   => $mime,
                'reason' => 'destination_not_derivable',
            ]);
            return $file;
        }

        $this->safe_generate_webp($src, $dest);

        return $file;
    }

    public function generate_webp_versions($metadata, $attachment_id) {
        try {
            if (!is_array($metadata) || empty($metadata['file'])) {
                return $metadata;
            }

            $upload_dir = wp_upload_dir();
            $file_path = trailingslashit($upload_dir['basedir']) . ltrim($metadata['file'], '/');
            $mime = $this->get_image_mime_type($file_path);

            if ($mime === 'image/svg+xml' || strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'svg') {
                return $metadata;
            }

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    if (empty($data['file'])) {
                        continue;
                    }

                    $src = trailingslashit(dirname($file_path)) . $data['file'];
                    if (!preg_match('/\.(jpe?g|png)$/i', $src)) {
                        continue;
                    }

                    $dest = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
                    if (!$dest || $dest === $src) {
                        $this->debug_log('Metadata WebP sidecar skipped', [
                            'source' => $src,
                            'size'   => $size,
                            'reason' => 'destination_not_derivable',
                        ]);
                        continue;
                    }

                    $this->safe_generate_webp($src, $dest);
                }
            }
        } catch (\Throwable $e) {
            $this->debug_log('Metadata WebP generation failed defensively', [
                'attachment_id' => $attachment_id,
                'reason'        => $e->getMessage(),
            ]);
        }

        return $metadata;
    }

    public function serve_webp_images($image, $attachment_id, $size, $icon) {
        if (!$image || !is_array($image)) return $image;

        // Swap the SIZE FILE actually being served for its modern-format sibling.
        // (Previously this swapped in the modern version of the FULL-SIZE original for
        // every size request, so a thumbnail request could serve a multi-MB file - and
        // for already-webp uploads the "swap" resolved to the original itself.
        // Thumbnails must stay thumbnails.)
        $modern = $this->modern_url((string) $image[0]);
        if ($modern !== null) {
            $image[0] = $modern;
        }

        return $image;
    }

    /** Every srcset candidate WordPress builds from attachment metadata. */
    public function serve_modern_srcset($sources) {
        if (!is_array($sources)) return $sources;
        foreach ($sources as &$source) {
            if (!empty($source['url']) && ($modern = $this->modern_url((string) $source['url'])) !== null) {
                $source['url'] = $modern;
            }
        }
        return $sources;
    }

    /** src and srcset of each <img> in post content and block templates (wp_filter_content_tags). */
    public function serve_modern_img_tag($img) {
        if (!is_string($img) || !preg_match('/\.(?:jpe?g|png)/i', $img)) return $img;
        return preg_replace_callback('/\b(src|srcset)=(["\'])(.*?)\2/i', function ($m) {
            if (strtolower($m[1]) === 'srcset') {
                $value = preg_replace_callback('/[^\s,]+\.(?:jpe?g|png)(?:\?[^\s,]*)?(?=[\s,]|$)/i', function ($u) {
                    return $this->modern_url($u[0]) ?? $u[0];
                }, $m[3]);
            } else {
                $value = $this->modern_url($m[3]) ?? $m[3];
            }
            return $m[1] . '=' . $m[2] . $value . $m[2];
        }, $img);
    }

    /** CSS backgrounds in block markup, e.g. parallax covers' background-image:url(...). */
    public function serve_modern_backgrounds($html) {
        if (!is_string($html) || strpos($html, 'url(') === false || !preg_match('/\.(?:jpe?g|png)/i', $html)) return $html;
        return preg_replace_callback('/url\(\s*(["\']|&quot;|&#039;|)([^"\')\s&]+\.(?:jpe?g|png)(?:\?[^"\')\s&]*)?)\1\s*\)/i', function ($m) {
            return 'url(' . $m[1] . ($this->modern_url($m[2]) ?? $m[2]) . $m[1] . ')';
        }, $html);
    }

    /**
     * The modern-format sibling of an uploaded JPG/PNG (name.webp beside name.png, as this plugin
     * writes them), or null when there isn't one. Any host and any network blog under the uploads
     * root counts, since content can reference another blog's uploads. The existence check is cached
     * in the object cache for an hour and per request, so a page full of srcsets costs no disk stats.
     */
    private function modern_url($url) {
        static $memo = [];
        if (isset($memo[$url])) return $memo[$url] ?: null;
        $memo[$url] = false;

        if (!preg_match('/\.(jpe?g|png)(?=$|\?)/i', $url)) return null;
        [$root, $prefix] = $this->uploads_root();
        $path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
        if ($root === '' || strpos($path, $prefix) !== 0 || strpos($path, '..') !== false) return null;

        $format = $this->modern_format();
        $modern_path = wp_normalize_path(preg_replace('/\.(jpe?g|png)$/i', '.' . $format, $root . substr($path, strlen($prefix) - 1)));

        $cache_key  = 'modern_exists_' . $format . '_' . md5($modern_path);
        $has_modern = wp_cache_get($cache_key, 'ace_image_enhancer');
        if (false === $has_modern) {
            $has_modern = file_exists($modern_path) ? '1' : '0';
            wp_cache_set($cache_key, $has_modern, 'ace_image_enhancer', HOUR_IN_SECONDS);
        }
        if ('1' !== $has_modern) return null;

        return $memo[$url] = preg_replace('/\.(jpe?g|png)(?=$|\?)/i', '.' . $format, $url);
    }

    /** [uploads root dir, its URL path with trailing slash], network-wide (sites/N stripped). */
    private function uploads_root() {
        static $root = null;
        if ($root === null) {
            $up  = wp_upload_dir(null, false);
            $dir = rtrim(wp_normalize_path((string) $up['basedir']), '/');
            $url = rtrim((string) wp_parse_url((string) $up['baseurl'], PHP_URL_PATH), '/');
            if (is_multisite()) {
                $dir = preg_replace('#/sites/\d+$#', '', $dir);
                $url = preg_replace('#/sites/\d+$#', '', $url);
            }
            $root = ($dir !== '' && $url !== '') ? [$dir, $url . '/'] : ['', ''];
        }
        return $root;
    }

    /**
     * Drop the cached "no modern sibling" answer for a file that has just been written, so a
     * reprocessed image is served as WebP/AVIF straight away rather than after the hour's cache.
     */
    private function forget_modern_check($modern_path) {
        $modern_path = wp_normalize_path((string) $modern_path);
        $ext = strtolower((string) pathinfo($modern_path, PATHINFO_EXTENSION));
        if (in_array($ext, ['webp', 'avif'], true)) {
            wp_cache_delete('modern_exists_' . $ext . '_' . md5($modern_path), 'ace_image_enhancer');
        }
    }

    private function modern_format() {
        return ($this->get_option('image_format') === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';
    }

    // ---------------------------------------------------------
    // SVG Editor Integration
    // ---------------------------------------------------------
    public function enqueue_svg_editor($hook) {
        // Only load on media pages
        if (!in_array($hook, ['post.php', 'upload.php', 'media-upload-popup'])) {
            return;
        }

        wp_enqueue_style(
            'ace-svg-editor',
            plugins_url('css/svg-editor.css', __FILE__),
            [],
            '1.4.1'
        );

        wp_enqueue_script(
            'ace-svg-editor',
            plugins_url('js/svg-editor.js', __FILE__),
            ['jquery'],
            '1.4.1',
            true
        );

        wp_localize_script('ace-svg-editor', 'aceSvgEditor', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ace_svg_editor'),
            'svgEditUrl' => plugins_url('svgedit/dist/editor/index.html', __FILE__)
        ]);
    }
    
    public function add_attachment_tools($form_fields, $post) {
        $mime      = $post->post_mime_type;
        $is_raster = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'], true);
        $is_svg    = ($mime === 'image/svg+xml');

        if (!$is_raster && !$is_svg) {
            return $form_fields;
        }

        $id  = $post->ID;
        $ext = strtolower(pathinfo(get_attached_file($id), PATHINFO_EXTENSION));

        // ---- Raster: Reprocess + Rename ----
        if ($is_raster) {
            $current_name = pathinfo(get_attached_file($id), PATHINFO_FILENAME);
            $format       = $this->get_option('image_format');
            $can_reprocess = ($format !== 'original');

            $reprocess_btn = $can_reprocess
                ? sprintf('<button type="button" class="button ace-reprocess-btn" data-id="%d">Reprocess to %s</button>',
                    $id, esc_attr(strtoupper($format)))
                : '<span class="description">Set a format in Image Enhancer settings to enable reprocessing.</span>';

            $rename_modal = sprintf(
                '<div id="ace-rename-modal-%1$d" class="ace-tool-modal" style="display:none;">
                    <div class="ace-tool-modal-overlay"></div>
                    <div class="ace-tool-modal-container" style="max-width:520px">
                        <div class="ace-tool-modal-header">
                            <h2>Rename Image</h2>
                            <button type="button" class="ace-tool-modal-close">&times;</button>
                        </div>
                        <div class="ace-tool-modal-body">
                            <p>Enter a new filename (without extension). All thumbnails and file references across posts, postmeta, and options will be updated.</p>
                            <p><strong>Note:</strong> The old URL will no longer be accessible — add a redirect if needed.</p>
                            <input type="text" id="ace-rename-input-%1$d" class="large-text" value="%2$s">
                            <p id="ace-rename-status-%1$d" style="margin-top:8px"></p>
                        </div>
                        <div class="ace-tool-modal-footer">
                            <button type="button" class="button button-primary ace-rename-confirm" data-id="%1$d">Rename &amp; Update References</button>
                            <button type="button" class="button ace-tool-modal-close">Cancel</button>
                        </div>
                    </div>
                </div>',
                $id,
                esc_attr($current_name)
            );

            $form_fields['ace_image_tools'] = [
                'label' => 'Image Tools',
                'input' => 'html',
                'html'  => sprintf(
                    '<div class="ace-attachment-tools">%s &nbsp; <button type="button" class="button ace-rename-btn" data-id="%d">Rename…</button>%s</div>',
                    $reprocess_btn, $id, $rename_modal
                ),
            ];
        }

        // ---- SVG: Edit SVG ----
        if ($is_svg && $this->get_option('enable_svg_editor')) {
            $file_url = wp_get_attachment_url($id);
            $form_fields['svg_editor'] = [
                'label' => 'SVG Editor',
                'input' => 'html',
                'html'  => sprintf(
                    '<button type="button" class="button ace-edit-svg" data-attachment-id="%1$d" data-svg-url="%2$s">Edit SVG</button>
                    <div id="ace-svg-editor-modal-%1$d" class="ace-tool-modal" style="display:none;">
                        <div class="ace-tool-modal-overlay"></div>
                        <div class="ace-tool-modal-container">
                            <div class="ace-tool-modal-header">
                                <h2>Edit SVG</h2>
                                <button type="button" class="ace-tool-modal-close">&times;</button>
                            </div>
                            <iframe id="ace-svg-editor-frame-%1$d" src="" style="width:100%%;height:calc(90vh - 120px);border:none;flex:1"></iframe>
                            <div class="ace-tool-modal-footer">
                                <button type="button" class="button button-primary ace-svg-save" data-attachment-id="%1$d">Save Changes</button>
                                <button type="button" class="button ace-tool-modal-close">Cancel</button>
                            </div>
                        </div>
                    </div>',
                    $id,
                    esc_url($file_url)
                ),
            ];
        }

        return $form_fields;
    }

    public function ajax_load_svg() {
        check_ajax_referer('ace_svg_editor', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$this->get_option('enable_svg') || !$this->get_option('enable_svg_editor') || get_post_type($attachment_id) !== 'attachment' || !current_user_can('edit_post', $attachment_id)) wp_send_json_error('Permission denied', 403);

        if (!$attachment_id) {
            wp_send_json_error('Missing attachment ID');
        }

        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !$this->safe_upload_path($file_path) || strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) !== 'svg') {
            wp_send_json_error('Invalid SVG file');
        }

        $svg_content = filesize($file_path) <= 5 * 1024 * 1024 ? $this->sanitize_svg_content(file_get_contents($file_path)) : false;

        if ($svg_content === false) {
            wp_send_json_error('Failed to read SVG file');
        }
        
        wp_send_json_success([
            'content' => $svg_content
        ]);
    }
    
    public function ajax_save_svg() {
        check_ajax_referer('ace_svg_editor', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$this->get_option('enable_svg') || !$this->get_option('enable_svg_editor') || get_post_type($attachment_id) !== 'attachment' || !current_user_can('edit_post', $attachment_id)) wp_send_json_error('Permission denied', 403);
        $svg_content = isset($_POST['svg_content']) && is_string($_POST['svg_content']) ? wp_unslash($_POST['svg_content']) : '';
        
        if (!$attachment_id || !$svg_content) {
            wp_send_json_error('Missing required data');
        }
        
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !$this->safe_upload_path($file_path) || strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) !== 'svg') {
            wp_send_json_error('Invalid SVG file');
        }
        
        // Sanitize the SVG content before saving
        $sanitized_content = $this->sanitize_svg_content($svg_content);
        
        if ($sanitized_content === false) {
            wp_send_json_error('Invalid or malicious SVG content');
        }
        
        // Save the file
        $result = file_put_contents($file_path, $sanitized_content);
        
        if ($result === false) {
            wp_send_json_error('Failed to save file');
        }
        
        // Clear any caches
        clean_attachment_cache($attachment_id);
        
        wp_send_json_success([
            'message' => 'SVG saved successfully',
            'url' => wp_get_attachment_url($attachment_id) . '?v=' . time()
        ]);
    }
}

new Ace_Image_Enhancer();
