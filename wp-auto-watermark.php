<?php
/**
 * Plugin Name: WP Auto Watermark
 * Description: Automatically applies watermark text to uploaded images with bulk processing
 * Version: 1.0.0
 * Author: Mahbub
 * License: GPL v2 or later
 * Text Domain: wp-auto-watermark
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Auto_Watermark {
    
    private $option_name = 'wp_auto_watermark_settings';
    private $meta_key = '_watermarked';
    
    public function __construct() {
        // Hook for automatic watermarking on upload
        add_filter('wp_generate_attachment_metadata', array($this, 'auto_watermark_on_upload'), 10, 2);
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX handlers
        add_action('wp_ajax_get_unwatermarked_images', array($this, 'ajax_get_unwatermarked_images'));
        add_action('wp_ajax_process_watermark_batch', array($this, 'ajax_process_watermark_batch'));
        add_action('wp_ajax_get_watermarked_images', array($this, 'ajax_get_watermarked_images'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_submenu_page(
            'upload.php',
            __('Bulk Watermark', 'wp-auto-watermark'),
            __('Bulk Watermark', 'wp-auto-watermark'),
            'manage_options',
            'bulk-watermark',
            array($this, 'bulk_watermark_page')
        );
        
        add_options_page(
            __('Auto Watermark Settings', 'wp-auto-watermark'),
            __('Auto Watermark', 'wp-auto-watermark'),
            'manage_options',
            'wp-auto-watermark-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('wp_auto_watermark_settings_group', $this->option_name);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'media_page_bulk-watermark') {
            return;
        }
        
        wp_enqueue_style(
            'wp-auto-watermark-admin',
            plugin_dir_url(__FILE__) . 'admin.css',
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'wp-auto-watermark-admin',
            plugin_dir_url(__FILE__) . 'admin.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('wp-auto-watermark-admin', 'wpAutoWatermark', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_auto_watermark_nonce')
        ));
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $settings = get_option($this->option_name, array(
            'watermark_text' => 'Copyright',
            'font_size' => 20,
            'opacity' => 50,
            'position' => 'bottom-right'
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Auto Watermark Settings', 'wp-auto-watermark'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('wp_auto_watermark_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="watermark_text"><?php echo esc_html__('Watermark Text', 'wp-auto-watermark'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="watermark_text" 
                                   name="<?php echo esc_attr($this->option_name); ?>[watermark_text]" 
                                   value="<?php echo esc_attr($settings['watermark_text']); ?>" 
                                   class="regular-text">
                            <p class="description"><?php echo esc_html__('Text to display as watermark', 'wp-auto-watermark'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="font_size"><?php echo esc_html__('Font Size', 'wp-auto-watermark'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="font_size" 
                                   name="<?php echo esc_attr($this->option_name); ?>[font_size]" 
                                   value="<?php echo esc_attr($settings['font_size']); ?>" 
                                   min="10" 
                                   max="100">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="opacity"><?php echo esc_html__('Opacity (%)', 'wp-auto-watermark'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="opacity" 
                                   name="<?php echo esc_attr($this->option_name); ?>[opacity]" 
                                   value="<?php echo esc_attr($settings['opacity']); ?>" 
                                   min="0" 
                                   max="100">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="position"><?php echo esc_html__('Position', 'wp-auto-watermark'); ?></label>
                        </th>
                        <td>
                            <select id="position" name="<?php echo esc_attr($this->option_name); ?>[position]">
                                <option value="top-left" <?php selected($settings['position'], 'top-left'); ?>>Top Left</option>
                                <option value="top-right" <?php selected($settings['position'], 'top-right'); ?>>Top Right</option>
                                <option value="bottom-left" <?php selected($settings['position'], 'bottom-left'); ?>>Bottom Left</option>
                                <option value="bottom-right" <?php selected($settings['position'], 'bottom-right'); ?>>Bottom Right</option>
                                <option value="center" <?php selected($settings['position'], 'center'); ?>>Center</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Bulk watermark page
     */
    public function bulk_watermark_page() {
        $settings = get_option($this->option_name, array(
            'watermark_text' => 'Copyright',
            'font_size' => 20,
            'opacity' => 50,
            'position' => 'bottom-right'
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WP Auto Watermark', 'wp-auto-watermark'); ?></h1>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="#tab-unwatermarked" class="nav-tab nav-tab-active" data-tab="unwatermarked"><?php echo esc_html__('Unwatermarked', 'wp-auto-watermark'); ?></a>
                <a href="#tab-watermarked" class="nav-tab" data-tab="watermarked"><?php echo esc_html__('Watermarked', 'wp-auto-watermark'); ?></a>
                <a href="#tab-settings" class="nav-tab" data-tab="settings"><?php echo esc_html__('Settings', 'wp-auto-watermark'); ?></a>
            </h2>

            <!-- Unwatermarked Images Tab -->
            <div id="tab-unwatermarked" class="tab-content">
                <div style="margin-bottom: 20px;">
                    <button type="button" id="load-unwatermarked-images" class="button button-secondary">
                        <?php echo esc_html__('Load Unwatermarked Images', 'wp-auto-watermark'); ?>
                    </button>
                </div>

                

                <!-- Watermark Controls -->
                <div id="watermark-controls" style="display:none; margin-top: 20px;">
                    <button type="button" id="start-watermark" class="button button-primary">
                        <?php echo esc_html__('Start Watermarking', 'wp-auto-watermark'); ?>
                    </button>
                    <button type="button" id="retry-failed" class="button button-secondary" style="display:none;">
                        <?php echo esc_html__('Retry Failed', 'wp-auto-watermark'); ?>
                    </button>
                </div>

                <!-- Progress Bar -->
                <div id="progress-container" style="display:none; margin-top: 20px;">
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar">
                            <div id="progress-fill" class="progress-fill"></div>
                        </div>
                        <div class="progress-text">
                            <span id="progress-current">0</span> / <span id="progress-total">0</span>
                            (<span id="progress-percent">0</span>%)
                        </div>
                    </div>
                    <div id="progress-status" style="margin-top: 10px; font-weight: 500;"></div>
                </div>

                <div id="images-table-container"></div>

                <!-- Results -->
                <div id="results-container" style="display:none; margin-top: 20px;">
                    <div id="results-summary"></div>
                    <div id="failed-images"></div>
                </div>

                <!-- Status Message -->
                <div id="watermark-status" class="notice notice-info" style="display:none;">
                    <p></p>
                </div>
            </div>

            <!-- Watermarked Images Tab -->
            <div id="tab-watermarked" class="tab-content" style="display:none;">
                <button type="button" id="load-watermarked-images" class="button button-secondary">
                    <?php echo esc_html__('Load Watermarked Images', 'wp-auto-watermark'); ?>
                </button>
                <div id="watermarked-images" style="margin-top: 20px;"></div>
            </div>

            <!-- Settings Tab -->
            <div id="tab-settings" class="tab-content" style="display:none;">
                <form method="post" action="options.php">
                    <?php settings_fields('wp_auto_watermark_settings_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="watermark_text"><?php echo esc_html__('Watermark Text', 'wp-auto-watermark'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="watermark_text" 
                                       name="<?php echo esc_attr($this->option_name); ?>[watermark_text]" 
                                       value="<?php echo esc_attr($settings['watermark_text']); ?>" 
                                       class="regular-text">
                                <p class="description"><?php echo esc_html__('Text to display as watermark', 'wp-auto-watermark'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="font_size"><?php echo esc_html__('Font Size', 'wp-auto-watermark'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="font_size" 
                                       name="<?php echo esc_attr($this->option_name); ?>[font_size]" 
                                       value="<?php echo esc_attr($settings['font_size']); ?>" 
                                       min="10" 
                                       max="100">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="opacity"><?php echo esc_html__('Opacity (%)', 'wp-auto-watermark'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="opacity" 
                                       name="<?php echo esc_attr($this->option_name); ?>[opacity]" 
                                       value="<?php echo esc_attr($settings['opacity']); ?>" 
                                       min="0" 
                                       max="100">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="position"><?php echo esc_html__('Position', 'wp-auto-watermark'); ?></label>
                            </th>
                            <td>
                                <select id="position" name="<?php echo esc_attr($this->option_name); ?>[position]">
                                    <option value="top-left" <?php selected($settings['position'], 'top-left'); ?>>Top Left</option>
                                    <option value="top-right" <?php selected($settings['position'], 'top-right'); ?>>Top Right</option>
                                    <option value="bottom-left" <?php selected($settings['position'], 'bottom-left'); ?>>Bottom Left</option>
                                    <option value="bottom-right" <?php selected($settings['position'], 'bottom-right'); ?>>Bottom Right</option>
                                    <option value="center" <?php selected($settings['position'], 'center'); ?>>Center</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get unwatermarked images
     */
    public function ajax_get_unwatermarked_images() {
        check_ajax_referer('wp_auto_watermark_nonce', 'nonce');
        
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/jpg', 'image/png'),
            'posts_per_page' => -1,
            'post_status' => 'inherit',
            'meta_query' => array(
                array(
                    'key' => $this->meta_key,
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        
        $query = new WP_Query($args);
        $images = array();
        
        foreach ($query->posts as $post) {
            $images[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => wp_get_attachment_url($post->ID),
                'thumb' => wp_get_attachment_image_url($post->ID, 'thumbnail'),
                'mime' => get_post_mime_type($post->ID)
            );
        }
        
        wp_send_json_success(array(
            'images' => $images,
            'total' => count($images)
        ));
    }
    
    /**
     * AJAX: Process watermark batch
     */
    public function ajax_process_watermark_batch() {
        check_ajax_referer('wp_auto_watermark_nonce', 'nonce');
        
        $image_ids = isset($_POST['image_ids']) ? array_map('intval', $_POST['image_ids']) : array();
        
        if (empty($image_ids)) {
            wp_send_json_error(array('message' => 'No images provided'));
        }
        
        $results = array(
            'success' => array(),
            'failed' => array()
        );
        
        foreach ($image_ids as $image_id) {
            $result = $this->apply_watermark($image_id);
            
            if ($result['success']) {
                $results['success'][] = $image_id;
            } else {
                $results['failed'][] = array(
                    'id' => $image_id,
                    'error' => $result['error']
                );
            }
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Get watermarked images
     */
    public function ajax_get_watermarked_images() {
        check_ajax_referer('wp_auto_watermark_nonce', 'nonce');

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/jpg', 'image/png'),
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'meta_query'     => array(
                array(
                    'key'     => $this->meta_key,
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $query = new WP_Query($args);
        $images = array();

        foreach ($query->posts as $post) {
            $images[] = array(
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => wp_get_attachment_url($post->ID),
                'thumb' => wp_get_attachment_image_url($post->ID, 'thumbnail'),
                'mime'  => get_post_mime_type($post->ID),
            );
        }

        wp_send_json_success(array(
            'images' => $images,
            'total'  => count($images),
        ));
    }
    
    /**
     * Automatically watermark on upload
     */
    public function auto_watermark_on_upload($metadata, $attachment_id) {
        $mime_type = get_post_mime_type($attachment_id);
        
        if (!in_array($mime_type, array('image/jpeg', 'image/jpg', 'image/png'))) {
            return $metadata;
        }
        
        $this->apply_watermark($attachment_id);
        
        return $metadata;
    }
    
    /**
     * Apply watermark to image
     */
    private function apply_watermark($attachment_id) {
        if (get_post_meta($attachment_id, $this->meta_key, true)) {
            return array(
                'success' => false,
                'error' => 'Already watermarked'
            );
        }
        
        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);
        
        if (!file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => 'File not found'
            );
        }
        
        if (!in_array($mime_type, array('image/jpeg', 'image/jpg', 'image/png'))) {
            return array(
                'success' => false,
                'error' => 'Unsupported format'
            ); 
        }
        
        $settings = get_option($this->option_name, array(
            'watermark_text' => 'Copyright',
            'font_size' => 20,
            'opacity' => 50,
            'position' => 'bottom-right'
        ));
        
        try {
            if ($mime_type === 'image/png') {
                $image = imagecreatefrompng($file_path);
                imagesavealpha($image, true);
            } else {
                $image = imagecreatefromjpeg($file_path);
            }
            
            if (!$image) {
                throw new Exception('Failed to create image resource');
            }
            
            $width = imagesx($image);
            $height = imagesy($image);
            
            $text = $settings['watermark_text'];
            $font_size = intval($settings['font_size']);
            $opacity = intval($settings['opacity']);
            
            $font = 5;
            
            $text_width = imagefontwidth($font) * strlen($text);
            $text_height = imagefontheight($font);
            
            list($x, $y) = $this->calculate_position(
                $settings['position'],
                $width,
                $height,
                $text_width,
                $text_height
            );
            
            $alpha = 127 - (127 * $opacity / 100);
            $color = imagecolorallocatealpha($image, 255, 255, 255, $alpha);
            
            imagestring($image, $font, $x, $y, $text, $color);
            
            if ($mime_type === 'image/png') {
                imagepng($image, $file_path, 9);
            } else {
                imagejpeg($image, $file_path, 90);
            }
            
            imagedestroy($image);
            
            update_post_meta($attachment_id, $this->meta_key, time());
            
            return array('success' => true);
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Calculate watermark position
     */
    private function calculate_position($position, $img_width, $img_height, $text_width, $text_height) {
        $padding = 20;
        
        switch ($position) {
            case 'top-left':
                return array($padding, $padding);
            
            case 'top-right':
                return array($img_width - $text_width - $padding, $padding);
            
            case 'bottom-left':
                return array($padding, $img_height - $text_height - $padding);
            
            case 'bottom-right':
                return array($img_width - $text_width - $padding, $img_height - $text_height - $padding);
            
            case 'center':
                return array(
                    ($img_width - $text_width) / 2,
                    ($img_height - $text_height) / 2
                );
            
            default:
                return array($img_width - $text_width - $padding, $img_height - $text_height - $padding);
        }
    }
}

// Initialize plugin
new WP_Auto_Watermark();