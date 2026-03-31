<?php
/**
 * Plugin Name: Ace Image Enhancer
 * Description: Adds modern image handling (WebP/AVIF) with configurable settings and secure SVG upload support with XSS sanitization.
 * Version: 1.2.0
 * Author: Shane Rounce, AceMedia
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// UNCONDITIONAL SVG MIME SUPPORT (for all contexts including WP-CLI)
// ============================================================
// Register SVG mime types early and unconditionally so WP-CLI imports work
add_filter('upload_mimes', function($mimes) {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
}, 9999);

// Explicit CLI support
if (defined('WP_CLI') && WP_CLI) {
    add_filter('upload_mimes', function($mimes) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
        return $mimes;
    }, 10000);
}

class Ace_Image_Enhancer {

    private $options;

    public function __construct() {
        // Load settings
        $this->options = get_option('ace_image_enhancer_options', $this->get_default_options());
        
        // Admin settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // SVG support with sanitization (WordPress core doesn't include SVG by default)
        // Note: MIME registration is now unconditional (see top of file)
        if ($this->get_option('enable_svg')) {
            add_filter('wp_check_filetype_and_ext', [$this, 'fix_svg_mime_check'], 10, 5);
            add_filter('wp_handle_upload', [$this, 'sanitize_svg_on_upload']);
        }

        // Raster image handling - only if not set to 'original'
        if ($this->get_option('image_format') !== 'original') {
            add_filter('wp_handle_upload', [$this, 'handle_modern_image_upload'], 20); // Priority 20 to run after SVG
            add_filter('wp_generate_attachment_metadata', [$this, 'generate_webp_versions'], 10, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'serve_webp_images'], 10, 4);
            
            // Override WordPress default image quality with our setting
            add_filter('wp_editor_set_quality', [$this, 'override_image_quality'], 10, 2);
        }
        
        // SVG Editor integration for media library (experimental feature)
        if ($this->get_option('enable_svg_editor')) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_svg_editor']);
            add_action('wp_ajax_ace_save_svg', [$this, 'ajax_save_svg']);
            add_action('wp_ajax_ace_load_svg', [$this, 'ajax_load_svg']);
            add_filter('attachment_fields_to_edit', [$this, 'add_svg_edit_button'], 10, 2);
        }
    }

    // ---------------------------------------------------------
    // Settings & Options
    // ---------------------------------------------------------
    private function get_default_options() {
        return [
            'image_format' => 'webp',  // webp, avif, or original
            'image_quality' => 85,
            'enable_svg' => true,
            'keep_originals' => true,  // Keep original files by default
            'enable_svg_editor' => false,  // Experimental SVG editor
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

    public function svg_field_callback() {
        $value = $this->get_option('enable_svg');
        
        // Check if SVG is already allowed (by another plugin or custom code)
        $current_mimes = get_allowed_mime_types();
        $svg_already_allowed = false;
        foreach ($current_mimes as $exts => $mime) {
            if ($mime === 'image/svg+xml') {
                $svg_already_allowed = true;
                break;
            }
        }
        
        // Check server capability
        $has_simplexml = extension_loaded('simplexml');
        $has_dom = extension_loaded('dom');
        $has_libxml = extension_loaded('libxml');
        $server_capable = $has_simplexml || ($has_dom && $has_libxml);
        
        // Check WordPress version (6.3+ includes native SVG support)
        global $wp_version;
        $wp_core_supports_svg = version_compare($wp_version, '6.3', '>=');
        
        // If both WordPress and server support SVG natively, this becomes optional
        $native_support = $wp_core_supports_svg && $server_capable;
        
        ?>
        <label style="<?php echo $native_support ? 'opacity: 0.6;' : ''; ?>">
            <input type="checkbox" name="ace_image_enhancer_options[enable_svg]" 
                   value="1" <?php checked($value, 1); ?> <?php disabled(!$server_capable || $native_support); ?> />
            Enable SVG upload support with security sanitization
        </label>
        
        <?php if (!$server_capable): ?>
            <p class="description" style="color: #d63638;">
                ⚠️ <strong>Server limitation:</strong> Your server is missing required XML processing extensions. 
                Install php-simplexml or php-dom to enable SVG support.
            </p>
        <?php elseif ($native_support): ?>
            <p class="description" style="color: #2271b1;">
                ✓ WordPress <?php echo $wp_version; ?> natively supports SVG uploads. This option is disabled as no plugin configuration is needed.
            </p>
        <?php elseif ($svg_already_allowed && !$wp_core_supports_svg): ?>
            <p class="description" style="color: #d63638;">
                ⚠️ SVG uploads are enabled by another plugin/code. 
                Enable this option to add XSS security protection.
            </p>
        <?php else: ?>
            <p class="description">
                Enable secure SVG file uploads with automatic XSS protection. 
                Dangerous elements (scripts, event handlers) are automatically removed.
            </p>
        <?php endif; ?>
        <?php
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
        
        $sanitized['image_format'] = in_array($input['image_format'], ['webp', 'avif', 'original']) 
            ? $input['image_format'] 
            : 'webp';
        
        $sanitized['image_quality'] = max(1, min(100, intval($input['image_quality'])));
        $sanitized['enable_svg'] = !empty($input['enable_svg']);
        $sanitized['keep_originals'] = !empty($input['keep_originals']);
        $sanitized['enable_svg_editor'] = !empty($input['enable_svg_editor']);
        
        return $sanitized;
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
    // SVG Support with Security Sanitization
    // ---------------------------------------------------------
    // WordPress core intentionally doesn't allow SVG uploads by default due to
    // security concerns (SVGs can contain XSS attacks). This plugin adds SVG
    // support with automatic sanitization to strip dangerous elements.
    
    public function allow_svg_upload($mimes) {
        // Only add SVG if it's not already present (avoids conflicts with other plugins)
        if (!isset($mimes['svg'])) {
            $mimes['svg'] = 'image/svg+xml';
        }
        return $mimes;
    }

    public function fix_svg_mime_check($data, $file, $filename, $mimes, $real_mime) {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            $data['ext'] = 'svg';
            $data['type'] = 'image/svg+xml';
        }
        return $data;
    }

    public function sanitize_svg_on_upload($file) {
        if (!isset($file['file']) || !str_ends_with(strtolower($file['file']), '.svg')) {
            return $file;
        }

        $svg_content = file_get_contents($file['file']);
        
        if ($svg_content === false) {
            $file['error'] = 'Could not read SVG file';
            return $file;
        }

        // Sanitize SVG to remove potential XSS vectors
        $sanitized = $this->sanitize_svg_content($svg_content);
        
        if ($sanitized === false) {
            $file['error'] = 'Invalid or potentially malicious SVG file';
            return $file;
        }

        file_put_contents($file['file'], $sanitized);
        
        return $file;
    }

    private function sanitize_svg_content($svg) {
        // Remove XML declarations and doctype
        $svg = preg_replace('/<\?xml.*?\?>/i', '', $svg);
        $svg = preg_replace('/<!DOCTYPE.*?>/i', '', $svg);
        
        // Strip potentially dangerous elements
        $dangerous_tags = [
            'script', 'embed', 'object', 'iframe', 'link', 
            'style', 'foreignObject', 'use'
        ];
        
        foreach ($dangerous_tags as $tag) {
            $svg = preg_replace('#<' . $tag . '[^>]*?>.*?</' . $tag . '>#is', '', $svg);
            $svg = preg_replace('#<' . $tag . '[^>]*?/>#is', '', $svg);
        }
        
        // Remove event handlers (onclick, onload, etc.)
        $svg = preg_replace('/\s*on[a-z]+\s*=\s*["\'].*?["\']/is', '', $svg);
        
        // Remove javascript: and data: protocols from attributes
        $svg = preg_replace('/\s*(href|src|xlink:href)\s*=\s*["\']?\s*(javascript|data):/is', '', $svg);
        
        // Basic XML validation - try SimpleXML first, fallback to DOMDocument
        libxml_use_internal_errors(true);
        
        if (function_exists('simplexml_load_string')) {
            $dom = simplexml_load_string($svg);
        } elseif (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            $dom->loadXML($svg);
        } else {
            // No XML parser available - reject for safety
            libxml_clear_errors();
            return false;
        }
        
        libxml_clear_errors();
        
        if ($dom === false) {
            return false;
        }
        
        return $svg;
    }

    // ---------------------------------------------------------
    // Raster Image: Convert and Serve as WebP / AVIF
    // ---------------------------------------------------------
    public function override_image_quality($quality, $mime_type) {
        // Override WordPress default quality with our setting for WebP/AVIF
        if (in_array($mime_type, ['image/webp', 'image/avif'])) {
            return $this->get_option('image_quality');
        }
        return $quality;
    }
    
    public function handle_modern_image_upload($file) {
        if (str_contains($file['type'], 'svg')) return $file;

        $format = $this->get_option('image_format');
        $target_format = ($format === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';
        $mime_type = "image/{$target_format}";

        $src = $file['file'];
        $ext = pathinfo($src, PATHINFO_EXTENSION);
        $dest = str_replace(".$ext", ".{$target_format}", $src);

        $image = wp_get_image_editor($src);
        if (!is_wp_error($image)) {
            $image->set_quality($this->get_option('image_quality'));
            $saved = $image->save($dest, $mime_type);
            if (!is_wp_error($saved)) {
                // Keep original for now - thumbnails need it
                // We'll delete it later in generate_webp_versions if needed
                $file['file'] = $dest;
                $file['type'] = $mime_type;
                $file['url']  = str_replace(basename($file['url']), basename($dest), $file['url']);
            }
        }

        return $file;
    }

    public function generate_webp_versions($metadata, $attachment_id) {
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $metadata['file'];
        $ext = pathinfo($file_path, PATHINFO_EXTENSION);

        if ($ext === 'svg') return $metadata;

        $format = $this->get_option('image_format');
        $target_format = ($format === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';
        $mime_type = "image/{$target_format}";
        
        // Track original files to potentially delete later
        $originals_to_delete = [];

        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $src = dirname($file_path) . '/' . $data['file'];
                
                // Check if this is an original format file
                if (preg_match('/\.(jpg|jpeg|png)$/i', $src)) {
                    $dest = preg_replace('/\.(jpg|jpeg|png)$/i', ".{$target_format}", $src);

                    $image = wp_get_image_editor($src);
                    if (!is_wp_error($image)) {
                        $image->set_quality($this->get_option('image_quality'));
                        $saved = $image->save($dest, $mime_type);
                        if (!is_wp_error($saved)) {
                            $originals_to_delete[] = $src;
                            $metadata['sizes'][$size]['file'] = basename($dest);
                            $metadata['sizes'][$size]['mime-type'] = $mime_type;
                        }
                    }
                }
            }
        }
        
        // Now delete originals if keep_originals is disabled
        if (!$this->get_option('keep_originals')) {
            foreach ($originals_to_delete as $original) {
                @unlink($original);
            }
            
            // Also delete the main original file if it exists
            $main_original = preg_replace('/\.(webp|avif)$/i', '.jpg', $file_path);
            if (file_exists($main_original)) {
                @unlink($main_original);
            }
            $main_original = preg_replace('/\.(webp|avif)$/i', '.jpeg', $file_path);
            if (file_exists($main_original)) {
                @unlink($main_original);
            }
            $main_original = preg_replace('/\.(webp|avif)$/i', '.png', $file_path);
            if (file_exists($main_original)) {
                @unlink($main_original);
            }
        }

        return $metadata;
    }

    public function serve_webp_images($image, $attachment_id, $size, $icon) {
        if (!$image || !is_array($image)) return $image;

        $file = get_attached_file($attachment_id);
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext === 'svg') return $image;

        $format = $this->get_option('image_format');
        $target_format = ($format === 'avif' && extension_loaded('gd') && function_exists('imageavif')) ? 'avif' : 'webp';
        $modern_path = preg_replace('/\.(jpg|jpeg|png)$/i', ".{$target_format}", $file);

        if (file_exists($modern_path)) {
            $upload_dir = wp_upload_dir();
            $image[0] = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $modern_path);
        }

        return $image;
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
            '1.2.0'
        );
        
        wp_enqueue_script(
            'ace-svg-editor',
            plugins_url('js/svg-editor.js', __FILE__),
            ['jquery'],
            '1.2.0',
            true
        );
        
        wp_localize_script('ace-svg-editor', 'aceSvgEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ace_svg_editor'),
            'svgEditUrl' => plugins_url('svgedit/dist/editor/index.html', __FILE__)
        ]);
    }
    
    public function add_svg_edit_button($form_fields, $post) {
        if ($post->post_mime_type !== 'image/svg+xml') {
            return $form_fields;
        }
        
        $file_url = wp_get_attachment_url($post->ID);
        
        $form_fields['svg_editor'] = [
            'label' => 'SVG Editor',
            'input' => 'html',
            'html' => sprintf(
                '<button type="button" class="button ace-edit-svg" data-attachment-id="%d" data-svg-url="%s">
                    Edit SVG
                </button>
                <div id="ace-svg-editor-modal-%d" class="ace-svg-editor-modal" style="display:none;">
                    <div class="ace-svg-editor-overlay"></div>
                    <div class="ace-svg-editor-container">
                        <div class="ace-svg-editor-header">
                            <h2>Edit SVG</h2>
                            <button type="button" class="ace-svg-editor-close">&times;</button>
                        </div>
                        <iframe id="ace-svg-editor-frame-%d" src="" style="width:100%%;height:calc(100vh - 120px);border:none;"></iframe>
                        <div class="ace-svg-editor-footer">
                            <button type="button" class="button button-primary ace-svg-save">Save Changes</button>
                            <button type="button" class="button ace-svg-cancel">Cancel</button>
                        </div>
                    </div>
                </div>',
                $post->ID,
                esc_url($file_url),
                $post->ID,
                $post->ID
            )
        ];
        
        return $form_fields;
    }
    
    public function ajax_load_svg() {
        check_ajax_referer('ace_svg_editor', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        
        if (!$attachment_id) {
            wp_send_json_error('Missing attachment ID');
        }
        
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || pathinfo($file_path, PATHINFO_EXTENSION) !== 'svg') {
            wp_send_json_error('Invalid SVG file');
        }
        
        $svg_content = file_get_contents($file_path);
        
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
        
        $attachment_id = intval($_POST['attachment_id']);
        $svg_content = stripslashes($_POST['svg_content']);
        
        if (!$attachment_id || !$svg_content) {
            wp_send_json_error('Missing required data');
        }
        
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || pathinfo($file_path, PATHINFO_EXTENSION) !== 'svg') {
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
