<?php
/**
 * @package wux-blog-editor
 */
/*
Plugin Name: Wux blog editor
Description: Edit posts and pages from all your different WordPress websites in one place.
Version: 3.0.0
Author: Jurre de Klijn
License: GPLv2 or later
Text Domain: wux-blog-editor
*/
$wuxbt_version = "3.0.0";
$blogtoolUrl = "https://blog.tool.wux.nl/";

if (!defined('ABSPATH')) {
    die;
}

//Create Token page//
add_action('admin_menu', 'wuxbt_create_token_page');

function wuxbt_create_token_page(){
    $page_title = 'Wux Blog Editor';
    $menu_title = 'Wux Blog Editor';
    $capability = 'manage_options';
    $menu_slug = 'wux-blog-editor';
    $function = 'wuxbt_test_init';
    $position = 75;
    add_options_page( $page_title, $menu_title, $capability, $menu_slug, $function, $position);
}

function wuxbt_createRandomToken($token) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $token; $i++) {
        $index = rand(0, strlen($characters) - 1);
        $randomString .= $characters[$index];
    }
    return $randomString;
}

function wuxbt_test_init(){
    $token = get_option('wux_blogtool_token');

    if(isset($_POST['action'])){
        $action = $_POST['action'];
    }else{
        $action = "";
    }
    if(empty($token) || $action == 'resetToken'){
        $token=30;
        $token = wuxbt_createRandomToken($token);
        update_option('wux_blogtool_token',$token);
    }
    if($action == 'set_post_types'){
        update_option('wuxbt_post_types',$_POST['post_types']);
        update_option('wuxbt_addpost_post_type',$_POST['addpost_post_type']);
    }

    
?>
    <h1>Wux Blog Editor</h1>
    <p>You need this token for adding the website in your Wux blog editor tool.</p>
    <input type="text" size="50%" value="<?php echo $token ?>" readonly>
    <form action="" method="POST">
        <br>
        <button name="action" value="resetToken" type="submit">Reset token</button>
    </form>
    <br><br><br><br>
    <form action="" method="POST">
        <h2>Welke post type moet hij weergeven op de index van de posts?</h2>
        <p>Voorbeeld: post,blog</p>
        <input type="text" name="post_types" value="<?= get_option('wuxbt_post_types') ?>">
        <br><br>

        <h2>Voor welke post type moet het een posts zijn bij het aanmaken van een post?</h2>
        <p>Voorbeeld: post of blog</p>
        <p>Standaard: post</p>
        <input type="text" name="addpost_post_type" value="<?= get_option('wuxbt_addpost_post_type') ?>">
        <br><br>

        <button name="action" value="set_post_types" type="submit">Opslaan</button>
    </form>
<?php
}

//Set Token//
function wuxbt_externalSetToken( $request) {
    $token = get_option('wux_blogtool_token');
    $filledToken = $request->get_param( 'token' );
    if($token == $filledToken){
        return 1;
    }
    return 0;
}

function wuxbt_forceToken(){
    $token = get_option('wux_blogtool_token');
    if($token == $_REQUEST['token']){
        return;
    }
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/token', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_externalSetToken',
        'permission_callback' => '__return_true',
        
    ));
});

//Get post//
function wuxbt_getPostData($post) {
    wuxbt_forceToken();
    $categorie = get_the_category($post['ID']);
    $categorie = json_decode(json_encode($categorie), true);

    $post['category'] = '';
    $post['category_id'] = '';
    foreach ($categorie as $key => $noUse) {
        if(count($categorie) > 1){
            $post['category'] .= $categorie[$key]['name'].', ';
            $post['category_id'] .= $categorie[$key]['term_id'].', ';
        }else{
            $post['category'] .= $categorie[$key]['name'];
            $post['category_id'] .= $categorie[$key]['term_id'];
        }
    }

    $tags = get_the_tags($post['ID']);
    $tags = json_decode(json_encode($tags), true);

    $post['tags'] = '';
    if(!empty($tags[0]['name'])){
        foreach ($tags as $key => $tag) {
            $post['tags'] .= $tags[$key]['name'].', ';
        }  
    }

    // $date = get_the_date();
    // $post['date']

    $image = wp_get_attachment_url(get_post_thumbnail_id($post['ID']));
    $post['image'] = $image;

    return $post;
}

function wuxbt_externalGetPost()
{
    wuxbt_forceToken();
    if(isset($_GET['id'])){
        $id = intval($_GET['id']);
        if($id < 1){
            exit;
        }
        $post = get_post($id);
        $post = json_decode(json_encode($post), true);
        return wuxbt_getPostData($post);
    }


    $allowedPostTypes = explode(",",get_option('wuxbt_post_types'));
    $allowedPostTypes = array_map('trim',$allowedPostTypes);

    if(isset($_GET['allblogs'])){
        $allblogs = $_GET['allblogs'];
    }else{
        $allblogs = 999999;
    }

    $posts = get_posts(array(
        'posts_per_page'=> $allblogs, 
        'numberposts'=> -1,
        'post_status' => array('any'),
        'post_type' => $allowedPostTypes,
        'orderby' => 'ID',
        'order' => 'DESC',
    ));

    $posts = json_decode(json_encode($posts), true);
    foreach ($posts as $key => $post) {
        $posts[$key] = wuxbt_getPostData($post);
    }

    if (empty($posts)) {
        return null;
    }

    return $posts;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/posts', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetPost',
        'permission_callback' => '__return_true',
    ));
});

//Insert post//
function wuxbt_externalSetPost( $request ) {
    wuxbt_forceToken();

    $post_type = get_option('wuxbt_addpost_post_type');

    $postArray = array(
        'post_title'     => $request->get_param( 'title' ),
        'post_content'   => $request->get_param( 'content' ),
        'post_category'  => $request->get_param( 'category' ),
        'tags_input'     => $request->get_param( 'tags' ),
        'post_status'    => $request->get_param( 'status' ),
        'comment_status' => $request->get_param( 'comment' ),
        'post_name'      => $request->get_param( 'slug' ),
        'post_author'    => 1,
        'post_type'      => $post_type,
    );
    
    // removes wp_filter_post_kses filter so user can add script tags to html SEE FUNCTION apply_filters WP-INCLUDES
    kses_remove_filters(); 

    $postId = wp_insert_post($postArray);
    set_post_thumbnail( $postId, $request->get_param('image') );
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/posts', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_externalSetPost',
        'permission_callback' => '__return_true',
    ));
});

//Delete post//
function wuxbt_externalDeletePost($request) {
    wp_delete_post($request->get_param( 'ID' ));
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/posts', array(
        'methods' => 'DELETE',
        'callback' => 'wuxbt_externalDeletePost',
        'permission_callback' => '__return_true',
    ));
});

//Edit post//
function wuxbt_externalUpdatePost( $request ) {
    wuxbt_forceToken();
    $postArray = array(
        'ID'             => $request->get_param( 'ID' ), 
        'post_title'     => $request->get_param( 'title' ),
        'post_name'      => $request->get_param( 'slug' ),
        'post_content'   => $request->get_param( 'content' ),
        'post_category'  => $request->get_param( 'category' ),
        'tags_input'     => $request->get_param( 'tags' ),
        'post_date'      => $request->get_param( 'date' ),
        'post_status'    => $request->get_param( 'status' ),
        'comment_status' => $request->get_param( 'comment' ),

        'post_author'    => 1,
    );

    // removes wp_filter_post_kses filter so user can add script tags to html SEE FUNCTION apply_filters WP-INCLUDES
    kses_remove_filters(); 
    // kses_init_filters();   // Set up the filters.
    // remove_filter( 'pre_comment_content', 'wp_filter_post_kses' );

    
    wp_update_post($postArray);

    set_post_thumbnail( $request->get_param( 'ID' ), $request->get_param('image') );
    if($request->get_param('image') == null){
        delete_post_thumbnail( $request->get_param( 'ID' ) );
    }
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/posts', array(
        'methods' => 'PUT',
        'callback' => 'wuxbt_externalUpdatePost',
        'permission_callback' => '__return_true',
    ));
});

//posts fallback
function wuxbt_posts_fallback( $request ){
    $fallback_method = $request->get_param( 'fallback_method' );
    if($fallback_method == "put"){
        wuxbt_externalUpdatePost($request);
    }
    if($fallback_method == "delete"){
        wuxbt_externalDeletePost($request);
    }
}
add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/posts_fallback', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_posts_fallback',
        'permission_callback' => '__return_true',
    ));
});

//Get Page//
function wuxbt_externalGetPage()
{
    wuxbt_forceToken();
    if(isset($_GET['id'])){
        $id = intval($_GET['id']);
        if($id < 1){
            exit;
        }
        $page = get_post($id);
        $page = json_decode(json_encode($page), true);
        return $page;
    }

    $pages = get_posts(array(
        'posts_per_page'=>- 1, 
        'numberposts'=>- 1,
        'post_type' => array('page'),
        'post_status' => array('any'),
    ));
    
    if (empty($pages)){
        return null;
    }

    return $pages;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/pages', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetPage',
        'permission_callback' => '__return_true',
    ));
});

//Get Categories//
function wuxbt_externalGetCategories()
{
    wuxbt_forceToken();
    $args = array(
        'hide_empty' => false,
    );

    if(isset($_GET['id'])){
        $id = intval($_GET['id']);
        if($id < 1){
            exit;
        }
        return get_category($args, $id);
    }

    $categories = get_categories($args);

    return $categories;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/categories', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetCategories',
        'permission_callback' => '__return_true',
    ));
});

//Insert Categories//
function wuxbt_externalSetCategories( $request ){
    wuxbt_forceToken();

    //NEW
    require_once(ABSPATH . str_replace(get_site_url().'/',"",admin_url('includes/taxonomy.php', __FILE__)));
    //OLD
    //require_once(ABSPATH . 'wp-admin/includes/taxonomy.php'); 

    $catArray = array(
        'cat_name'             => $request->get_param( 'name' ),
        'category_description' => $request->get_param( 'description' ), 
        'category_nicename'    => $request->get_param( 'slug' ), 
        'category_parent'      => $request->get_param( 'parent' )
    );

    wp_insert_category($catArray);
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/categories', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_externalSetCategories',
        'permission_callback' => '__return_true',
    ));
});

//Update Categories//
function wuxbt_externalUpdateCategories( $request ) {
    wuxbt_forceToken();

    //NEW
    require_once(ABSPATH . str_replace(get_site_url().'/',"",admin_url('includes/taxonomy.php', __FILE__)));
    //OLD
    //require_once(ABSPATH . 'wp-admin/includes/taxonomy.php'); 

    $catArray = array(
        'cat_ID'               => $request->get_param( 'ID' ), 
        'cat_name'             => $request->get_param( 'name' ),
        'category_description' => $request->get_param( 'description' ),
        'category_nicename'    => $request->get_param( 'slug' ), 
        'category_parent'      => $request->get_param( 'parent' ),
    );
    wp_update_category($catArray);
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/categories', array(
        'methods' => 'PUT',
        'callback' => 'wuxbt_externalUpdateCategories',
        'permission_callback' => '__return_true',
    ));
});

//Get Tags//
function wuxbt_externalGetTags() {
    wuxbt_forceToken();
    $tags = get_tags(array(
        'taxonomy' => 'post_tag',
        'orderby' => 'name',
    ));

    return $tags;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/tags', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetTags',
        'permission_callback' => '__return_true',
    ));
});

//Get Media//
function wuxbt_externalGetMedia(){
    wuxbt_forceToken();
    $query_images_args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => - 1,
    );
    $query_images = new WP_Query( $query_images_args );

    $images = array();
    foreach ( $query_images->posts as $image ) {
        $images[$image->ID] = wp_get_attachment_url( $image->ID );
    }

    return $images;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/media', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetMedia',
        'permission_callback' => '__return_true',
    ));
});

//Get image element by id
function wuxbt_externalGetImageElement(){
    wuxbt_forceToken();

    return wp_get_attachment_image($_GET['id'],'large','',array( "class" => "wp-image-".$_GET['id'] ));
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/image-element', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetImageElement',
        'permission_callback' => '__return_true',
    ));
});

//Insert Media//
function wuxbt_insertImage( $request ){
    wuxbt_forceToken();
    $images = array(
        'image' => $request->get_param( 'image' ),
        'base64' => $request->get_param( 'base64' )
    );
    $decode = base64_decode($images['base64']);

    //NEW
    $uploadDIR = wp_upload_dir();
    $uploadDIR = $uploadDIR['url'];
    $siteURL = get_site_url();
    $siteURL = str_replace("http:","https:",$siteURL);
    $uploadDIR = str_replace("http:","https:",$uploadDIR);
    $path = str_replace($siteURL."/", "", $uploadDIR);
    $img = $path.'/'.$images['image'];
    //OLD
    // $year = date('Y');
    // $month = date('m');
    // $img = 'wp-content/uploads/'. $year .'/'. $month .'/'. $images['image'];
    file_put_contents($img, $decode);

    $wp_upload_dir = wp_upload_dir();
    $filename = $wp_upload_dir['path'] . '/'. $images['image'];
    $filetype = wp_check_filetype( basename( $filename ), null );

    $image_alt = $request->get_param( 'image_alt' );
    $image_title = $request->get_param( 'image_title' );
    if($image_title == ""){
        $image_title = preg_replace( '/\.[^.]+$/', '', basename( $filename ) );
    }


    $attachment = array(
        'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ), 
        'post_mime_type' => $filetype['type'],
        'post_title'     => $image_title,
        'post_content'   => '',//description
        'post_excerpt' => '',//caption
        'post_status'    => 'inherit'
    );

    $attach_id = wp_insert_attachment( $attachment, $filename);
    update_post_meta($attach_id, '_wp_attachment_image_alt', $image_alt);//set alt text

    //NEW
    require_once(ABSPATH . str_replace(get_site_url().'/',"",admin_url('includes/image.php', __FILE__)));
    //OLD
    //require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
    wp_update_attachment_metadata( $attach_id, $attach_data );

}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/image', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_insertImage',
        'permission_callback' => '__return_true',
    ));
});


//NEW IMAGE UPLOAD
function wuxbt_insertImageNew( $request ) {
	$url = $request->get_param( 'url' );
    $imageName = $request->get_param( 'imageName' );
    $encodedIMG = file_get_contents($url);
	
	if($encodedIMG == false){//allow_url_fopen is disabled?
		http_response_code(500);
		exit;
	}
	
    $uploadDIR = wp_upload_dir();
    $uploadDIR = $uploadDIR['url'];
    $siteURL = get_site_url();
    $siteURL = str_replace("http:","https:",$siteURL);
    $uploadDIR = str_replace("http:","https:",$uploadDIR);
    $path = str_replace($siteURL."/", "", $uploadDIR);
    $img = $path.'/'.$imageName;

    file_put_contents($img, $encodedIMG);

    $wp_upload_dir = wp_upload_dir();
    $filename = $wp_upload_dir['path'] . '/'. $imageName;
    $filetype = wp_check_filetype( basename( $filename ), null );

    $image_alt = $request->get_param( 'image_alt' );
    $image_title = $request->get_param( 'image_title' );
    if($image_title == ""){
        $image_title = preg_replace( '/\.[^.]+$/', '', basename( $filename ) );
    }


    $attachment = array(
        'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ), 
        'post_mime_type' => $filetype['type'],
        'post_title'     => $image_title,
        'post_content'   => '',//description
        'post_excerpt' => '',//caption
        'post_status'    => 'inherit'
    );

    $attach_id = wp_insert_attachment( $attachment, $filename);
    update_post_meta($attach_id, '_wp_attachment_image_alt', $image_alt);//set alt text

    //NEW
    require_once(ABSPATH . str_replace(get_site_url().'/',"",admin_url('includes/image.php', __FILE__)));
    //OLD
    //require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
    wp_update_attachment_metadata( $attach_id, $attach_data );
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/image-upload', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_insertImageNew',
        'permission_callback' => '__return_true',
    ));
});

//Insert page//
function wuxbt_externalSetPage( $request ){
    wuxbt_forceToken();
    $pageArray = array(
        'post_title'     => $request->get_param( 'title' ),
        'post_content'   => $request->get_param( 'content' ),
        'post_status'    => $request->get_param( 'status' ),
        'comment_status' => $request->get_param( 'comment' ),

        'post_type'      => 'page',
        'post_author'    => 1,
    );
    wp_insert_post($pageArray);
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/pages', array(
        'methods' => 'POST',
        'callback' => 'wuxbt_externalSetPage',
        'permission_callback' => '__return_true',
    ));
});

//Delete page//
function wuxbt_externalDeletePage($request) {
    wp_delete_post($request->get_param( 'ID' ));
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/pages', array(
        'methods' => 'DELETE',
        'callback' => 'wuxbt_externalDeletePage',
        'permission_callback' => '__return_true',
    ));
});

//Update page//
function wuxbt_externalUpdatePage( $request ) {
    wuxbt_forceToken();
    $pageArray = array(
        'ID'             => $request->get_param( 'ID' ), 
        'post_title'     => $request->get_param( 'title' ),
        'post_name'      => $request->get_param( 'slug' ),
        'post_content'   => $request->get_param( 'content' ),
        'post_status'    => $request->get_param( 'status' ),
        'comment_status' => $request->get_param( 'comment' ),

        'post_type'      => 'page',
        'post_author'    => 1,
    );
    wp_update_post($pageArray);
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/pages', array(
        'methods' => 'PUT',
        'callback' => 'wuxbt_externalUpdatePage',
        'permission_callback' => '__return_true',
    ));
});



//AUTOLOGIN TO WORDPRESS
function wuxbt_externalAutologin( $request ) {
    wuxbt_forceToken();


    if($_SERVER['HTTP_REFERER'] != 'https://blog.tool.wux.nl/'){
        
        if($_SERVER['HTTP_REFERER'] == "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"){
            
        }
        else{
            echo "Error code 444";
            exit;
        }
    }
    

    $blogusers = get_users( );
    // Array of WP_User objects.
    foreach ( $blogusers as $user ) {
        if ( in_array( 'administrator', (array) $user->roles ) ) {
            

            // Check if user is already logged in, redirect to account if true
            if (!is_user_logged_in()) {
                // Sanitize the received key to prevent SQL Injections
                $received_key = sanitize_text_field($_GET['token']);
                
                // Get the user id then set the login cookies to the browser
                wp_set_auth_cookie($user->ID);
                
                
                foreach($_COOKIE as $name => $value) {
                    // Find the cookie with prefix starting with "wordpress_logged_in_"
                    if(substr($name, 0, strlen('wordpress_logged_in_')) == 'wordpress_logged_in_') {
                        // Redirect to account page if the login cookie is already set.
                        echo 'Heeft gewerkt, ga naar /wp-admin';
                        wp_redirect( admin_url() );
                        exit;
                        
                    }
                }
                header("Refresh:1");
                echo 'Redirecting...';
                echo 'Laad deze oneindig? Ga dan naar /wp-admin';
            
            } else {
                wp_redirect( admin_url() );
                exit;
            }
        }else{
            // echo "Error code: 5U70";
        }
    }
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/autologin', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalAutologin',
        'permission_callback' => '__return_true',
    ));
});


function wuxbt_externalGetPluginVersion( $request ){
    wuxbt_forceToken();
    
    global $wuxbt_version;
    echo $wuxbt_version;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/plugin-version', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetPluginVersion',
        'permission_callback' => '__return_true',
    ));
});

//shortcode
add_action('init', function () {
    add_shortcode('wuxbt_shortcode', 'wuxbt_shortcodeFunction');
});

function wuxbt_shortcodeFunction($attributes = []){
    global $blogtoolUrl;

    $websitename = parse_url(get_site_url(), PHP_URL_HOST);
    $websitename = ucfirst(str_replace('www.','',$websitename));

    //if no id in shortcode
    if(!isset($attributes['token'])){
        return "Er staat geen token als parameter in de shortcode!";
    }
    $token = strip_tags($attributes['token']);

    $content = file_get_contents($blogtoolUrl . 'getshortcode?token='.$token);
    $content = str_replace('[[WEBSITE_NAAM]]',$websitename,$content);
    $content = str_replace('[[WEBSITE_URL]]',get_site_url(),$content);
    ob_start();
    echo $content;
    return ob_get_clean();
}


//custom preview for concept posts/pages
add_action('init', function () {
    if(isset($_GET['wuxbt_preview'])){
        global $wp_query;

        $post = get_post($_GET['p']);

        if($post == NULL || $post->post_status == "trash"){
            $wp_query->set_404();
            status_header(404);
            exit;
        }
        
        //redirect to right page if published
        if($post->post_status == "publish"){
            wp_redirect(get_permalink($post->ID));
            exit;
        }

        //check if post is 10 days or older
        $currentDate = date('Y-m-d H:i:s');
        $diff = strtotime($currentDate) - strtotime($post->post_date);
        $differentDays = (int) $diff/(60*60*24);
        if($differentDays > 3){
            $wp_query->set_404();
            status_header(404);

            get_header();
            echo '<h1>Deze pagina is verlopen...</h1>';
            get_footer();

            exit;
        }

        //show preview after all checks
        get_header();
        ?>
            <div style="width: 900px; margin: auto; margin-top: 30px;">
                <h1><?= $post->post_title ?></h1>
            </div>
            <div style="width: 800px; margin: auto;">
                <?= get_the_post_thumbnail($post->ID,'full') ?>
            </div>
            <div style="width: 700px; margin: auto;  margin-bottom: 30px;">
                <?= $post->post_content ?>
            </div>
        <?php
        get_footer();
        exit;
    }
});


//external plugin updater
function wuxbt_externalUpgradePlugin( $request ){
    wuxbt_forceToken();


    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    wp_clean_plugins_cache();

    wp_update_plugins();
    ob_start();
    $upgrader = new Plugin_Upgrader();
    $result = $upgrader->upgrade('wux-blog-editor/External_Post_Editor.php');

    activate_plugin('wux-blog-editor/External_Post_Editor.php');

    echo $result;
}
add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/upgrade-plugin', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalUpgradePlugin',
        'permission_callback' => '__return_true',
    ));
});

//update plugin from specific homepage url
add_action('init', function () {
    if(isset($_GET['token'])){
        $currentUrl = home_url($_SERVER['REQUEST_URI']);
        $validUrl = home_url('?token='.get_option('wux_blogtool_token'));
        if($currentUrl == $validUrl){
            wuxbt_externalUpgradePlugin($_GET);
            exit;
        }
    }
});



//get all titles
function wuxbt_externalGetTitles()
{
    wuxbt_forceToken();

    $allowedPostTypes = explode(",",get_option('wuxbt_post_types'));
    $allowedPostTypes = array_map('trim',$allowedPostTypes);

    $posts = get_posts(array(
        'posts_per_page'=> 99999999, 
        'numberposts'=> -1,
        'post_status' => array('any'),
        'post_type' => $allowedPostTypes,
        'orderby' => 'ID',
        'order' => 'DESC',
        'fields' => 'ids'
    ));

    $posts = json_decode(json_encode($posts), true);
    $titles = [];
    foreach ($posts as $key => $postID) {
        $titles[$key] = get_the_title($postID);
    }

    if (empty($titles)) {
        return null;
    }

    return $titles;
}

add_action('rest_api_init', function () {
    register_rest_route('external-post-editor/v2', '/titles', array(
        'methods' => 'GET',
        'callback' => 'wuxbt_externalGetTitles',
        'permission_callback' => '__return_true',
    ));
});