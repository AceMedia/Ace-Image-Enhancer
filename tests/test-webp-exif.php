<?php
/** Isolated plugin registration plus optional stock Core metadata reader test. */
define('ABSPATH', __DIR__ . '/unused-wordpress/');
$ace_test_filters = array();
function get_option($name, $default = false) { return $default; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { return true; }
function add_filter($name, $callback, $priority = 10, $accepted = 1) {
    $GLOBALS['ace_test_filters'][$name][$priority][] = $callback;
    return true;
}
function apply_filters($name, $value, ...$args) {
    $callbacks = isset($GLOBALS['ace_test_filters'][$name]) ? $GLOBALS['ace_test_filters'][$name] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $callback) { $value = $callback($value, ...$args); }
    }
    return $value;
}
require dirname(__DIR__) . '/ace-image-enhancer.php';
$checks = array();
function ace_test($passed, $label) { $GLOBALS['checks'][] = array('passed' => (bool) $passed, 'case' => $label); }
$defaults = array(IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM);
$enabled = PHP_VERSION_ID >= 80600 && function_exists('exif_read_data');
$types = apply_filters('wp_read_image_metadata_types', $defaults);
ace_test(count($ace_test_filters['wp_read_image_metadata_types'][10]) === 1, 'actual plugin constructor registers one metadata filter');
ace_test($types === ($enabled ? array_merge($defaults, array(IMAGETYPE_WEBP)) : $defaults), 'preserve existing types and conditionally add WebP');
ace_test(apply_filters('wp_read_image_metadata_types', $types) === $types, 'repeated filtering does not add duplicate WebP');
ace_test(apply_filters('wp_read_image_metadata_types', null) === null, 'unexpected upstream value stays unchanged');
$custom = array(IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_JPEG);
ace_test(apply_filters('wp_read_image_metadata_types', $custom) === $custom, 'custom type order and existing WebP stay unchanged');
$core = getenv('WP_CORE_IMAGE_FILE');
if ($core) {
    function wp_getimagesize($file, &$info = null) { return @getimagesize($file, $info); }
    function wp_is_valid_utf8($value) { return 1 === preg_match('//u', (string) $value); }
    function wp_kses_post_deep($value) { return $value; }
    require $core;
    $fixtures = __DIR__ . '/fixtures';
    $webp = wp_read_image_metadata($fixtures . '/synthetic-exif.webp');
    $jpeg = wp_read_image_metadata($fixtures . '/synthetic-exif.jpg');
    $plain = wp_read_image_metadata($fixtures . '/synthetic-plain.webp');
    ace_test($webp['orientation'] === ($enabled ? 6 : 0), 'actual stock Core reader gets WebP orientation only on supported runtime');
    ace_test($webp['credit'] === ($enabled ? 'Synthetic test author' : ''), 'WebP credit follows existing Core metadata path');
    ace_test($webp['copyright'] === ($enabled ? 'Synthetic test copyright' : ''), 'WebP copyright follows existing Core metadata path');
    ace_test($jpeg['orientation'] === 6 && $jpeg['credit'] === 'Synthetic test author', 'JPEG metadata unchanged');
    ace_test($plain['orientation'] === 0 && $plain['credit'] === '', 'WebP without EXIF retains empty metadata');
    ace_test(wp_read_image_metadata($fixtures . '/not-present.webp') === false, 'missing file remains false');
}
add_filter('wp_read_image_metadata_types', static function($types) {
    return array_values(array_diff($types, array(IMAGETYPE_WEBP)));
}, 20);
ace_test(!in_array(IMAGETYPE_WEBP, apply_filters('wp_read_image_metadata_types', $defaults), true), 'later site filter can still opt out');
$failed = array_values(array_filter($checks, static function($check) { return !$check['passed']; }));
echo json_encode(array('php' => PHP_VERSION, 'checks' => count($checks), 'passed' => count($checks) - count($failed), 'failed' => $failed, 'stock_core_function_tested' => (bool) $core, 'scope' => 'Actual plugin registration and optional stock Core function. Hook, option, UTF-8 and sanitisation boundaries stubbed; synthetic fixtures, no database or real upload.')), "\n";
exit($failed ? 1 : 0);
