<?php
// Run only in a disposable local WordPress installation using wp eval-file.
if (strpos(home_url(), '127.0.0.1') === false) throw new RuntimeException('Disposable local site required');
$plugin = new Ace_Image_Enhancer();
$root = wp_upload_dir()['basedir']; wp_mkdir_p($root);
$fixture = $root . '/ace-image-review.svg';
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
file_put_contents($fixture, $svg);
$assert = function($condition, $name) { if (!$condition) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name."\n"; };
$path = new ReflectionMethod($plugin, 'safe_upload_path');
$assert($path->invoke($plugin, $fixture), 'inside uploads');
$assert(!$path->invoke($plugin, '/etc/passwd'), 'outside uploads');
$link = $root . '/ace-image-review-link.svg'; symlink($fixture, $link);
$assert(!$path->invoke($plugin, $link), 'symlink denied'); unlink($link);
$result = $plugin->sanitize_svg_prefilter(['name'=>'safe.svg','tmp_name'=>$fixture,'type'=>'image/svg+xml']);
$assert(empty($result['error']), 'SVG upload passes');
$result = $plugin->sanitize_svg_prefilter(['name'=>'unsafe.svgz','tmp_name'=>$fixture,'type'=>'image/svg+xml']);
$assert(!empty($result['error']), 'SVGZ denied');
file_put_contents($fixture, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
$result = $plugin->sanitize_svg_prefilter(['name'=>'unsafe.svg','tmp_name'=>$fixture,'type'=>'image/svg+xml']);
$assert(!empty($result['error']), 'active SVG denied');
file_put_contents($fixture, $svg);
$id = wp_insert_attachment(['post_title'=>'Disposable review fixture','post_mime_type'=>'image/svg+xml','post_status'=>'inherit'], $fixture);
$rename = new ReflectionMethod($plugin, 'rename_attachment_files');
$collision = $root . '/ace-image-review-existing.svg'; file_put_contents($collision, 'preserve');
$result = $rename->invoke($plugin, $id, 'ace-image-review-existing');
$assert(is_wp_error($result) && file_get_contents($collision)==='preserve' && file_exists($fixture), 'rename collision preserves both files');
wp_set_current_user(0);
$convert = new ReflectionMethod($plugin, 'convert_attachment_to_format');
$assert($convert->invoke($plugin, $id)['reason'] === 'permission_denied', 'anonymous reprocess denied');
$saved_options = get_option('ace_image_enhancer_options');
update_option('ace_image_enhancer_options', array_merge((array)$saved_options, ['enable_svg'=>false]));
$disabled = new Ace_Image_Enhancer();
$result = $disabled->sanitize_svg_prefilter(['name'=>'safe.svg','tmp_name'=>$fixture,'type'=>'image/svg+xml']);
$assert(!empty($result['error']), 'disabled SVG setting enforced');
update_option('ace_image_enhancer_options', $saved_options);
$request = new WP_REST_Request('POST', '/ace-image-enhancer/v1/reprocess-single');
$request->set_param('attachment_id', $id);
$assert(rest_get_server()->dispatch($request)->get_status() === 401, 'anonymous REST denied');
$author_id = wp_insert_user(['user_login'=>'aie-review-other-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(),'role'=>'author']);
if (is_wp_error($author_id)) throw new RuntimeException('Could not create local test author');
wp_set_current_user($author_id);
$assert(!current_user_can('edit_post', $id), 'author cannot edit unowned fixture');
$assert(rest_get_server()->dispatch($request)->get_status() === 403, 'other author REST denied');
wp_set_current_user(0);
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user($author_id);
wp_delete_attachment($id, true); if (file_exists($fixture)) unlink($fixture); unlink($collision);

// Deployments commonly keep uploads in a shared directory behind a trusted symlink.
$alias = sys_get_temp_dir() . '/aie-trusted-uploads-' . wp_generate_password(8, false);
symlink($root, $alias);
$override = function($uploads) use ($alias) { $uploads['basedir'] = $alias; return $uploads; };
add_filter('upload_dir', $override);
$probe = $root . '/aie-symlink-root-probe.txt'; file_put_contents($probe, 'preserve');
$assert($path->invoke($plugin, $alias . '/aie-symlink-root-probe.txt'), 'trusted upload-root symlink accepted');
$assert($path->invoke($plugin, $probe), 'resolved trusted upload-root path accepted');
$nested = $root . '/aie-untrusted-link'; symlink($root, $nested);
$assert(!$path->invoke($plugin, $alias . '/aie-untrusted-link/aie-symlink-root-probe.txt'), 'symlink beneath trusted root denied');
$assert(!$path->invoke($plugin, $alias . '/../outside'), 'traversal beneath trusted root denied');
if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
    $raster = $alias . '/aie-root-conversion.png';
    $modern = $alias . '/aie-root-conversion.webp';
    $pixels = imagecreatetruecolor(8, 8); imagepng($pixels, $raster); unset($pixels);
    $generate = new ReflectionMethod($plugin, 'safe_generate_webp');
    $assert($generate->invoke($plugin, $raster, $modern) && is_file($modern) && wp_get_image_mime($modern) === 'image/webp', 'conversion works through trusted upload-root symlink');
    unlink($raster); unlink($modern);
}
remove_filter('upload_dir', $override); unlink($nested); unlink($probe); unlink($alias);
