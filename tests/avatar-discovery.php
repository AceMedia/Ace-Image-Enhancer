<?php
// Disposable local WordPress only: prove GET discovery does not save avatar metadata.
if (strpos(home_url(), '127.0.0.1') === false) throw new RuntimeException('Local fixture required');
$uploads=wp_upload_dir();
$file=$uploads['basedir'].'/aie-avatar-discovery.png';
$image=imagecreatetruecolor(8,8);imagepng($image,$file);unset($image);
$id=wp_insert_attachment(['post_title'=>'Disposable avatar discovery','post_mime_type'=>'image/png','post_status'=>'inherit'],$file);
$user=wp_insert_user(['user_login'=>'aie-avatar-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(),'role'=>'author']);
if(is_wp_error($user))throw new RuntimeException('Fixture user creation failed');
try {
    update_user_meta($user,'custom_avatar',wp_get_attachment_url($id));
    $plugin=new Ace_Image_Enhancer();
    $response=$plugin->rest_batch_from_avatars(new WP_REST_Request('GET','/ace-image-enhancer/v1/batch-from-avatars'));
    if(!in_array($id,$response->get_data()['image_ids'],true))throw new RuntimeException('Discovery did not find fixture');
    if(metadata_exists('user',$user,'custom_avatar_id'))throw new RuntimeException('GET discovery wrote metadata');
    echo "PASS avatar discovery finds local image without saving user metadata\n";
} finally {
    require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);wp_delete_attachment($id,true);
    if(file_exists($file))unlink($file);
}
