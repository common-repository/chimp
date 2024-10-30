<?php
/**
 * Plugin Name: Chimp Migration API
 * Description: Migrate your WordPress data to chimp. Posts, comments, users & media.
 * Author:      Chimp Team
 * Author URI:  https://chimphq.com/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Version:     1.0.0
 */

define( 'CHIMP_PLUGIN_VERSION', '1.0.0' );

/**
 * Add our rewrites to wordpress
 *
 * @return void
 */
function chimp_rewrites()
{
    add_rewrite_rule( '^chimp-api/?$','index.php?chimp_route=/', 'top' );
    add_rewrite_rule( '^chimp-api/(.*)?','index.php?chimp_route=/$matches[1]', 'top' );
}

/**
 * Called at activation of the plugin in admin gui
 *
 * @return void
 */
function chimp_activation()
{
    chimp_rewrites();
    chimp_regenerate_apikey();
    flush_rewrite_rules();
}

/**
 * Called at deactivation of the plugin in admin gui
 *
 * @return void
 */
function chimp_deactivation()
{
    delete_option( 'chimp_apikey' );
    flush_rewrite_rules();
}

/**
 * Initialize
 *
 * @return void
 */
function chimp_init()
{
    chimp_rewrites();
        
    global $wp;
    $wp->add_query_var( 'chimp_route' );
}

/**
 * (Re)generates a new API Key for Chimp and write it to database
 *
 * @return void
 */
function chimp_regenerate_apikey()
{
    $apikey = implode("-", str_split(substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"), 0, 28), 7));
    update_option( 'chimp_apikey', $apikey );
}

/**
 * Get Chimp API Key
 *
 * @return String
 */
function chimp_get_apikey()
{
    return get_option( 'chimp_apikey' );
}

/**
 * Add actions and hooks
 */
add_action( 'init', 'chimp_init', 11 ); // Prioritized over core rewrites
register_activation_hook( __FILE__, 'chimp_activation' );
register_deactivation_hook( __FILE__, 'chimp_deactivation' );


/**
 * Flush rewrite rules if needed.
 *
 * @return void
 */
function chimp_flush_rewrite_rules()
{
    $version = get_option( 'chimp_plugin_version', null );

    if($version !== CHIMP_PLUGIN_VERSION)
    {
        flush_rewrite_rules();
        update_option( 'chimp_plugin_version', CHIMP_PLUGIN_VERSION );
    }

}
add_action( 'init', 'chimp_flush_rewrite_rules', 900 );


/**
 * X-Headers for extra info
 *
 * @return void
 */
function chimp_headers($count, $pages)
{
    header("X-Chimp-Pages: {$pages}", true);
    header("X-Chimp-Count: {$count}", true);
}

/**
 * Outputs our API results in JSON format
 *
 * @param array $results
 * @return void
 */
function chimp_json($results)
{
    header("Content-type: application/json", true);
    echo json_encode($results);
    die();
}

/**
 * Core function with API methods
 *
 * @return void
 */
function chimp_ready()
{
    global $wp;

    if(!isset($GLOBALS['wp']->query_vars['chimp_route']))
    {
        return;
    }
    
    $apikey = chimp_get_apikey();
    
    if(!$_GET['api_key'] || !$apikey || $_GET['api_key'] != $apikey)
    {
        chimp_json(array("error" => "Access denied"));
        die();
    }
    
    $action = trim($GLOBALS['wp']->query_vars['chimp_route'], '/');
    
    /******************************************************************
     * Posts
     ******************************************************************/
    if($action == 'posts')
    {
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'post_status' => 'publish',
            'post_type' => array('post', 'page'),
            'posts_per_page' => 10,
            'paged' => $page
        );

        $wp_query = new WP_Query();
        $posts = $wp_query->query($query);

        chimp_headers($wp_query->found_posts, $wp_query->max_num_pages);
        
        $results = array();
        
        foreach ($posts as $post)
        {
            if($post->post_status != 'publish')
            {
                continue;
            }

            $wp_user = get_userdata( (int) $post->post_author );
            
            $author = null;
            
            if($wp_user)
            {
                $author = array(
                    'ID' => $wp_user->ID,
                    'login' => $wp_user->user_login,
                    'last_name' => $wp_user->last_name,
                    'first_name' => $wp_user->first_name,
                    'display_name' => $wp_user->display_name,
                    'email' => $wp_user->user_email,
                    'description' => $wp_user->description,
                    'roles' => implode(', ', $wp_user->roles),
                );
                
                $author_facebook = get_the_author_meta( 'facebook', $wp_user->ID );
                if($author_facebook)
                {
                    $author['facebook'] = $author_facebook;
                }

                $author_twitter = get_the_author_meta( 'twitter', $wp_user->ID );
                if($author_twitter)
                {
                    $author['twitter'] = $author_twitter;
                }

                $author_googleplus = get_the_author_meta( 'googleplus', $wp_user->ID );
                if($author_googleplus)
                {
                    $author['googleplus'] = $author_googleplus;
                }
            }
            
            $post_image = null;
            
            $post_image_id = get_post_thumbnail_id( $post->ID );
            
            if($post_image_id)
            {
                $attachment_metadata = wp_get_attachment_metadata( $post_image_id, true );
                $attachment_url = wp_get_attachment_url( $post_image_id );
                
                $post_image = array(
                    'ID' => $post_image_id,
                    'source' => $attachment_url,
                    'meta' => $attachment_metadata
                );
            }
            
            $post_categories = wp_get_post_categories( $post->ID );
            
            $categories = array();
            foreach($post_categories as $post_category_id)
            {
                $post_category = get_category( $post_category_id );
                
                $categories[] = array(
                    'name' => $post_category->name,
                    'slug' => $post_category->slug,
                );
            }
            
            $post_tags = wp_get_post_tags($post->ID);
            
            $tags = array();
            foreach($post_tags as $post_tag)
            {
                $tags[] = array(
                    'name' => $post_tag->name,
                    'slug' => $post_tag->slug,
                );
            }
            
            $data = array(
                'ID'              => $post->ID,
                'title'           => get_the_title( $post->ID ), // $post->post_title'],
                'status'          => $post->post_status,
                'type'            => $post->post_type,
                'date'            => $post->post_date,
                'modified'        => $post->post_modified,
                'author'          => $author,
                'content'         => apply_filters( 'the_content', $post->post_content ),
                'parent'          => (int) $post->post_parent,
                'link'            => get_permalink( $post->ID ),
                'slug'            => $post->post_name,
                'guid'            => apply_filters( 'get_the_guid', $post->guid ),
                'excerpt'         => $post->post_excerpt,
                'comment_status'  => $post->comment_status,
                'ping_status'     => $post->ping_status,
                'post_image'      => $post_image,
                'categories'      => $categories,
                'tags'            => $tags,
             );
             
            // Import keyword from bananacontent
            $postmeta = get_post_meta($post->ID, 'banana_content', true);
            if($postmeta && is_array($postmeta) && isset($postmeta['keyword']) && !empty($postmeta['keyword']))
            {
                $data['keyword'] = $postmeta['keyword'];
            }
            
            // Import keyword from Yoast
            if(!isset($data['keyword']) && !$data['keyword'])
            {
                $yoast_focuskw = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
                if($yoast_focuskw)
                {
                    $data['keyword'] = $yoast_focuskw;
                }
            }

            // Import meta from Yoast
            $yoast_seo_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
            if($yoast_seo_title)
            {
                $data['meta_title'] = $yoast_seo_title;
            }
            $yoast_seo_description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
            if($yoast_seo_description)
            {
                $data['meta_description'] = $yoast_seo_description;
            }
            $yoast_wpseo_opengraph_image = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-image', true );
            if($yoast_wpseo_opengraph_image)
            {
                $data['social_image_url'] = $yoast_wpseo_opengraph_image;
            }


            // Import meta from All In One SEO
            $aios_seo_title = get_post_meta( $post->ID, '_aioseop_title', true );
            if(!$data['meta_title'] && $aios_seo_title)
            {
                $data['meta_title'] = $aios_seo_title;
            }
            $aios_seo_description = get_post_meta( $post->ID, '_aioseop_description', true );
            if(!$data['meta_description'] && $aios_seo_description)
            {
                $data['meta_description'] = $aios_seo_description;
            }

            // Import meta from wpSEO
            $wpseo_seo_title = get_post_meta( $post->ID, '_wpseo_edit_title', true );
            if(!$data['meta_title'] && $wpseo_seo_title)
            {
                $data['meta_title'] = $wpseo_seo_title;
            }
            
            $wpseo_seo_description = get_post_meta( $post->ID, '_wpseo_edit_description', true );
            if(!$data['meta_description'] && $wpseo_seo_description)
            {
                $data['meta_description'] = $wpseo_seo_description;
            }

            $results[] = $data;
        }
        
        chimp_json($results);
        
    }
    /******************************************************************
     * Users
     ******************************************************************/
    elseif($action == 'users')
    {
        //WP_User_Query
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'number' => 50,
            'paged' => $page
        );

        $user_query = new WP_User_Query($query);

        chimp_headers($user_query->total_users, ceil($user_query->total_users/$query['number']));

        $results = array();
        
        foreach ($user_query->results as $wp_user)
        {
            $user = array(
                'ID' => $wp_user->ID,
                'login' => $wp_user->user_login,
                'last_name' => $wp_user->last_name,
                'first_name' => $wp_user->first_name,
                'display_name' => $wp_user->display_name,
                'email' => $wp_user->user_email,
                'description' => $wp_user->description,
                'roles' => implode(', ', $wp_user->roles),
            );

            $author_facebook = get_the_author_meta( 'facebook', $wp_user->ID );
            if($author_facebook)
            {
                $user['facebook'] = $author_facebook;
            }

            $author_twitter = get_the_author_meta( 'twitter', $wp_user->ID );
            if($author_twitter)
            {
                $user['twitter'] = $author_twitter;
            }

            $author_googleplus = get_the_author_meta( 'googleplus', $wp_user->ID );
            if($author_googleplus)
            {
                $user['googleplus'] = $author_googleplus;
            }
            
            $results[] = $user;
        }
        
        chimp_json($results);
    }
    /******************************************************************
     * Comments
     ******************************************************************/
    elseif($action == 'comments')
    {
        $number = 50;
        
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $offset = ( $page - 1 ) * $number;
        
        $query = array(
            'number' => $number,
            'offset' => $offset,
            'paged' => $page,
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC'
        );

        // First, check the total amount of comments
        $comments_query = new WP_Comment_Query;
        $comments = $comments_query->query();

        chimp_headers(count($comments), ceil(count($comments)/$query['number']));

        $comments_query = new WP_Comment_Query;
        $comments = $comments_query->query( $query );


        $results = array();
        
        foreach ($comments as $comment)
        {
            $results[] = array(
                'ID' => $comment->comment_ID,
                'post_id' => $comment->comment_post_ID,
                'author_name' => $comment->comment_author,
                'author_email' => $comment->comment_author_email,
                'author_url' => $comment->comment_author_url,
                'date' => $comment->comment_date,
                'content' => $comment->comment_content,
                'agent' => $comment->comment_agent,
                'type' => $comment->comment_type,
                'parent' => $comment->comment_parent,
                'user_id' => $comment->user_id,
                'approved' => $comment->comment_approved,
            );
        }
        
        chimp_json($results);
    }
    /******************************************************************
     * Media
     ******************************************************************/
    elseif($action == 'media')
    {
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'posts_per_page' => 50,
            'paged' => $page
        );

        $wp_query = new WP_Query();
        $posts = $wp_query->query($query);

        chimp_headers($wp_query->found_posts, $wp_query->max_num_pages);
        
        $results = array();
        
        foreach ($posts as $post)
        {
            $post_image = null;
            
            $post_image_id = get_post_thumbnail_id( $post->ID );
            
            $metadata = wp_get_attachment_metadata( $post->ID, true );
            $url = wp_get_attachment_url( $post->ID );
            
            if($metadata['sizes'])
            {
                foreach($metadata['sizes'] as $name => $size)
                {
                    $metadata['sizes'][$name]['url'] = dirname($url) . "/" . $size['file'];
                }
            }
            
            $wp_user = get_userdata( (int) $post->post_author );
            
            $author = null;
            
            if($wp_user)
            {
                $author = array(
                    'ID' => $wp_user->ID,
                    'login' => $wp_user->user_login,
                    'last_name' => $wp_user->last_name,
                    'first_name' => $wp_user->first_name,
                    'display_name' => $wp_user->display_name,
                    'email' => $wp_user->user_email,
                    'description' => $wp_user->description,
                    'roles' => implode(', ', $wp_user->roles),
                );
            }

            $data = array(
                'title'           => get_the_title( $post->ID ), // $post->post_title'],
                'date'            => $post->post_date,
                'modified'        => $post->post_modified,
                'author'          => $author,
                'source'          => $url,
                'slug'            => $post->post_name,
                'guid'            => apply_filters( 'get_the_guid', $post->guid ),
                'mime_type'       => $post->post_mime_type,
                'meta'            => $metadata,
             );

            $results[] = $data;
        }
        
        chimp_json($results);
    }
    // Index
    elseif(empty($action))
    {
        $results = array(
            'url' => get_bloginfo('url'),
            'self' => rtrim( get_bloginfo('url'), '/') . '/chimp-api/',
            'version' => get_bloginfo('version'),
            'charset' => get_bloginfo('charset'),
            'pingback_url' => get_bloginfo('pingback_url'),
            'rss_url' => get_bloginfo('rss_url'),
            'rss2_url' => get_bloginfo('rss2_url'),
            'chimp_plugin_version' => CHIMP_PLUGIN_VERSION,
        );
        
        chimp_json($results);
    }
    else
    {
        echo "Not provided.";
        die();
    }
    
}

add_action( 'template_redirect', 'chimp_ready', -100 );

if( is_admin() )
{
    function chimp_admin_page()
    {
        if( !current_user_can('administrator') )
        {
            //return;
        }

        $chimp_apikey  = chimp_get_apikey();
        $chimp_api_url = rtrim( get_bloginfo('url'), '/') . '/chimp-api/';
    
    ?>
            <div class="wrap">
                <h2>Chimp</h2>
                API-Plugin <?= CHIMP_PLUGIN_VERSION; ?>
                <p>
                    Folgende Daten kannst du für einen neuen Import in Chimp nutzen:
                </p>
                <table class="form-table">
                    <tr>
                        <td colspan="2">
                            <label for="chimp_api_url"><strong>API URL:</strong></label><br/>
                            <a href="<?php echo add_query_arg( 'api_key', $chimp_apikey, $chimp_api_url ); ?>" target="_blank"><?php echo $chimp_api_url; ?></a>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <label for="stetic_api_key"><strong>API-Key:</strong></label><br/>
                            <?php echo $chimp_apikey; ?><br>
                            <a href="<?php echo add_query_arg(
                                array(
                                    'page' => 'chimp/chimp.php',
                                    'chimp_action' => 'reload_apikey',
                                ),
                                admin_url('options-general.php')
                            ); ?>">Neu generieren</a>
                        </td>
                    </tr>
                </table>
                <p>
                    Bitte beachte: Jedes Mal, wenn du einen neuen API-Key erstellst, werden alle vorhergehenden API-Keys ungültig.
                </p>
            </div>
    <?php
    }

    function chimp_add_admin_menu()
    {
        if ( function_exists('add_options_page') && current_user_can('administrator') )
        {
            add_options_page('chimp', 'Chimp', 'manage_options', 'chimp/chimp.php', 'chimp_admin_page');
        }

        if ( function_exists('add_menu_page') && current_user_can('administrator') )
        {
            add_menu_page('chimp', 'Chimp', 'read', __FILE__, 'chimp_admin_page', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyhpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuNi1jMTExIDc5LjE1ODMyNSwgMjAxNS8wOS8xMC0wMToxMDoyMCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCkiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6RkJBQzVEODcyRjA4MTFFNjlFNzdCQzgyMkVDOTdBRjAiIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6RkJBQzVEODgyRjA4MTFFNjlFNzdCQzgyMkVDOTdBRjAiPiA8eG1wTU06RGVyaXZlZEZyb20gc3RSZWY6aW5zdGFuY2VJRD0ieG1wLmlpZDpGQkFDNUQ4NTJGMDgxMUU2OUU3N0JDODIyRUM5N0FGMCIgc3RSZWY6ZG9jdW1lbnRJRD0ieG1wLmRpZDpGQkFDNUQ4NjJGMDgxMUU2OUU3N0JDODIyRUM5N0FGMCIvPiA8L3JkZjpEZXNjcmlwdGlvbj4gPC9yZGY6UkRGPiA8L3g6eG1wbWV0YT4gPD94cGFja2V0IGVuZD0iciI/PmYHvW0AAAE3SURBVHjapNM7SwNBFIbhzCaaTsUomEIsBDsFqzSCiIWVpLRQ0MYfYeUPEExjIZb2FjaCEFCQtIKFiJWNRDBR0nhH13fgC5wss1HIwAOzmTlnTubi4jjO9NJyKb+tYhCnaGEFfqUj1Dtm+wqMIm7jzvZq+i+YszHJBMfx362BXDsmUrkXmEVRhR2gYgo9wbb6I1hADesZk3kYe+pPoN+MrWlF3+6Qbw/4DdvHPfIoa5UdvJkKNlFSfwzzWMSyM8fYUHn/bQU8R/rYCAR/4hLneAgk2LX3YDowIYtxJRoIjE/ZBFcpCUa7/IVre5H8uW6himaXO/COGioo+FiXeAtLmEFTV7cPDl/41sY94TD0FrIaHNKeRAr60Tyf6BE3OvIPH+RSXmNJt21S3z7wDNXkRNfrc/4VYADqaxw9ohgPfgAAAABJRU5ErkJggg==');
        }
        
    }
    
    function chimp_add_plugin_action_link($links, $file)
    {
        if($file ==  plugin_basename(__FILE__))
        {
            $settings_link = '<a href="options-general.php?page=chimp/chimp.php">' . __('Settings') . '</a>';
            array_push( $links, $settings_link );
        }
        return $links;
    }

    add_filter( 'plugin_action_links', 'chimp_add_plugin_action_link', 11, 2 );
    
	add_action( 'admin_menu', 'chimp_add_admin_menu' );
    
    function admin_request_changes()
    {
        if(basename(__FILE__) == 'chimp.php' && $_GET['chimp_action'] && $_GET['chimp_action'] == 'reload_apikey')
        {
            chimp_regenerate_apikey();
            wp_redirect(admin_url('/options-general.php?page=chimp/chimp.php'), 301);
            exit;
        }
    }
    
    add_action( 'admin_init', 'admin_request_changes' );
    
}
