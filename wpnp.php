<?php
/**
 * Plugin Name: WP Notes
 * Description: Draggable sticky notes per-user and per-page. Notes scroll with the page and never drift across resolutions by anchoring X to viewport center. Hidden under 1200px.
 * Version: 1.0.0
 * Author: Clara Muranyi
 * Text Domain: wpnp-center
 */
if ( ! defined('ABSPATH') ) exit;

class WPNP_Center {
    const CPT = 'wpnp_note3'; // NEW CPT + meta keys

    public function __construct() {
        add_action('init', [$this,'register_cpt']);

        add_action('add_meta_boxes', [$this,'add_metaboxes']);
        add_action('save_post_' . self::CPT, [$this,'save_meta']);
        add_action('save_post_' . self::CPT, [$this,'save_visibility']);
        add_filter('views_edit-' . self::CPT, [$this, 'fix_admin_counts']);


        add_action('pre_get_posts', [$this,'limit_admin_list']);
        add_action('load-post.php', [$this,'block_edit_access']);
        add_action('load-post-new.php', [$this,'block_edit_access']);

        add_action('wp_enqueue_scripts', [$this,'enqueue']);
        add_action('wp_footer', [$this,'print_wrapper'], 5);
        
        add_action('wp_footer', [$this,'print_payload'], 6);

        add_action('wp_ajax_wpnp3_save_position', [$this,'ajax_save_position']);
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'label' => 'Personal Notes',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'supports' => ['title','editor','author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-sticky',
        ]);
    }

    public function add_metaboxes() {
        add_meta_box('wpnp3_note_settings', __('Note Settings','wpnp-center'), [$this,'render_settings_box'], self::CPT, 'side');
        add_meta_box('wpnp3_note_visibility', __('Visibility / Share','wpnp-center'), [$this,'render_visibility_box'], self::CPT, 'normal');
    }

    public function render_settings_box($post) {
        wp_nonce_field('wpnp3_save_meta','wpnp3_meta_nonce');
        $color = get_post_meta($post->ID,'_wpnp3_color',true) ?: '#ffeb3b';
        $page = (int)get_post_meta($post->ID,'_wpnp3_page',true);
        ?>
        <p><strong><?php esc_html_e('Background color','wpnp-center'); ?></strong></p>
        <input type="color" name="wpnp3_color" value="<?php echo esc_attr($color); ?>">
        <p style="margin-top:10px;"><strong><?php esc_html_e('Display on page','wpnp-center'); ?></strong></p>
        <select name="wpnp3_page" style="width:100%;">
            <option value=""><?php esc_html_e('— Select Page —','wpnp-center'); ?></option>
            <?php foreach ( get_pages() as $p ): ?>
              <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($page, $p->ID); ?>>
                <?php echo esc_html($p->post_title); ?>
              </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_visibility_box($post){
        wp_nonce_field('wpnp3_save_visibility','wpnp3_vis_nonce');
        $is_public = get_post_meta($post->ID,'_wpnp3_is_public',true) === '1';
        $shared = get_post_meta($post->ID,'_wpnp3_shared_users',true);
        if(!is_array($shared)) $shared=[];
        $users = get_users(['fields'=>'all']);
        ?>
        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="wpnp3_is_public" value="1" <?php checked($is_public); ?>>
            <?php esc_html_e('Public note (all logged-in users)','wpnp-center'); ?>
        </label>
        <p><strong><?php esc_html_e('Share with users','wpnp-center'); ?></strong></p>
        <select name="wpnp3_shared_users[]" multiple style="width:100%;min-height:120px;">
            <?php foreach($users as $u): ?>
              <option value="<?php echo esc_attr($u->ID); ?>" <?php echo in_array($u->ID,$shared,true)?'selected':''; ?>>
                <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
              </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function save_meta($post_id){
        if(!isset($_POST['wpnp3_meta_nonce']) || !wp_verify_nonce($_POST['wpnp3_meta_nonce'],'wpnp3_save_meta')) return;
        if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if(!current_user_can('edit_post',$post_id)) return;

        $color = isset($_POST['wpnp3_color']) ? sanitize_hex_color($_POST['wpnp3_color']) : '#ffeb3b';
        update_post_meta($post_id,'_wpnp3_color',$color);

        $page = isset($_POST['wpnp3_page']) ? (int)$_POST['wpnp3_page'] : 0;
        update_post_meta($post_id,'_wpnp3_page',$page);
    }

    public function save_visibility($post_id){
        if(!isset($_POST['wpnp3_vis_nonce']) || !wp_verify_nonce($_POST['wpnp3_vis_nonce'],'wpnp3_save_visibility')) return;
        if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if(!current_user_can('edit_post',$post_id)) return;

        update_post_meta($post_id,'_wpnp3_is_public', isset($_POST['wpnp3_is_public']) ? '1' : '0' );

        $shared = (isset($_POST['wpnp3_shared_users']) && is_array($_POST['wpnp3_shared_users']))
            ? array_map('intval', $_POST['wpnp3_shared_users'])
            : [];
        update_post_meta($post_id,'_wpnp3_shared_users',$shared);
    }

    public function limit_admin_list( $query ) {
        // Only filter in admin main list screen
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // Only for our CPT
        if ( $query->get('post_type') !== self::CPT ) {
            return;
        }

        // Admin SHOULD NOT see other users (Option A)
        // So we ALWAYS limit to the current user's posts
        $query->set( 'author', get_current_user_id() );
    }


    public function block_edit_access(){
        if(!is_admin()) return;
        $s = get_current_screen();
        if(!$s || $s->post_type !== self::CPT) return;
        if(current_user_can('manage_options')) return;
        $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if($post_id){
            $p = get_post($post_id);
            if($p && (int)$p->post_author !== (int)get_current_user_id()){
                wp_die(__('You do not have permission to edit this note.','wpnp-center'));
            }
        }
    }

    public function enqueue(){
        if(is_admin()) return;
        wp_enqueue_style('wpnp3-style', plugin_dir_url(__FILE__) . 'assets/css/style.css', [], '3.0.0');
        wp_enqueue_script('wpnp3-script', plugin_dir_url(__FILE__) . 'assets/js/script.js', [], '3.0.0', true);
        wp_localize_script('wpnp3-script','WPNP3',[
            'ajaxUrl'=> admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('wpnp3_save_position'),
            'uid'    => get_current_user_id(),
        ]);
    }

    public function print_wrapper(){
        if(is_admin()) return;
        echo '<div id="wpnp3-notes-wrapper" aria-hidden="true"></div>';
    }

    public function print_payload(){
        if(is_admin()) return;
        $page_id = get_queried_object_id();
        $uid = get_current_user_id();

        $notes = get_posts([
            'post_type'=> self::CPT,
            'numberposts'=> -1,
            'post_status'=> ['publish','private','draft'],
            'meta_key'=> '_wpnp3_page',
            'meta_value'=> $page_id
        ]);
        if(!$notes) return;

        $visible = [];
        foreach($notes as $n){
            if((int)$n->post_author === (int)$uid){ $visible[]=$n; continue; }

            if($uid>0 && get_post_meta($n->ID,'_wpnp3_is_public',true)==='1'){
                $visible[]=$n; continue;
            }
            $shared = get_post_meta($n->ID,'_wpnp3_shared_users',true);
            if(!is_array($shared)) $shared=[];
            if($uid>0 && in_array($uid,$shared,true)){
                $visible[]=$n; continue;
            }
        }
        if(!$visible) return;

        echo '<script>window.wpnp3NotesPayload = window.wpnp3NotesPayload || [];</script>';
        foreach($visible as $n){
            $id=$n->ID;
            $obj=[
                'id'=>$id,
                'title'=> get_the_title($n),
                'content'=> wpautop( wp_kses_post($n->post_content) ),
                'color'=> get_post_meta($id,'_wpnp3_color',true) ?: '#ffeb3b',
                'author'=> (int)$n->post_author,
                // center-anchored coords
                'cx'=> metadata_exists('post',$id,'_wpnp3_cx') ? floatval(get_post_meta($id,'_wpnp3_cx',true)) : null,
                'cy'=> metadata_exists('post',$id,'_wpnp3_cy') ? floatval(get_post_meta($id,'_wpnp3_cy',true)) : null,
            ];
            echo '<script>window.wpnp3NotesPayload.push('.wp_json_encode($obj).');</script>';
        }
    }

    public function ajax_save_position(){
        check_ajax_referer('wpnp3_save_position','nonce');

        $note_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $post = get_post($note_id);
        if(!$post || $post->post_type !== self::CPT){
            wp_send_json_error(['msg'=>'invalid note'],404);
        }

        $uid = get_current_user_id();
        if(!$uid){
            wp_send_json_error(['msg'=>'not logged'],401);
        }

        if((int)$post->post_author !== (int)$uid && !current_user_can('manage_options')){
            wp_send_json_error(['msg'=>'no permission'],403);
        }

        $cx = isset($_POST['cx']) ? floatval($_POST['cx']) : null;
        $cy = isset($_POST['cy']) ? floatval($_POST['cy']) : null;

        if($cx === null || $cy === null){
            wp_send_json_error(['msg'=>'missing coords'],400);
        }

        update_post_meta($note_id,'_wpnp3_cx',$cx);
        update_post_meta($note_id,'_wpnp3_cy',$cy);

        wp_send_json_success(['saved'=>true,'cx'=>$cx,'cy'=>$cy]);
    }

    public function fix_admin_counts($views) {
        // Only run for our CPT
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== self::CPT) {
            return $views;
        }

        $uid = get_current_user_id();
        if (!$uid) return $views;

        // Count ONLY this user's posts
        $mine_args = [
            'post_type'      => self::CPT,
            'author'         => $uid,
            'posts_per_page' => -1,
            'post_status'    => ['private','publish','draft'],
            'fields'         => 'ids'
        ];
        $mine_count = count( get_posts($mine_args) );

        // Replace counts in All and Mine
        foreach (['all','mine'] as $key) {
            if (isset($views[$key])) {
                $views[$key] = preg_replace(
                    '/\([\d]+\)/',
                    '(' . intval($mine_count) . ')',
                    $views[$key]
                );
            }
        }

        return $views;
    }

    
}

new WPNP_Center();
