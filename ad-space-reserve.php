<?php
/**
 * Plugin Name: Ad Space Reserve
 * Plugin URI: https://github.com/ItsRayRay
 * Description: Prevents Cumulative Layout Shift (CLS) caused by dynamically injected ads by reserving space with server-side containers.
 * Version: 1.0.0
 * Author: ItsRayRay
 * Author URI: https://github.com/ItsRayRay
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: ad-space-reserve
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ASR_VERSION', '1.0.0');
define('ASR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class Ad_Space_Reserve {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once ASR_PLUGIN_DIR . 'includes/class-refinery89-defaults.php';
        require_once ASR_PLUGIN_DIR . 'includes/class-scanner.php';
        require_once ASR_PLUGIN_DIR . 'includes/class-code-generator.php';
        require_once ASR_PLUGIN_DIR . 'includes/class-theme-writer.php';

        // Admin classes
        if (is_admin()) {
            require_once ASR_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Initialize components
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'admin_menu']);

        // Frontend scanner script (only when scan mode is active)
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_scanner']);

        // AJAX handlers
        add_action('wp_ajax_asr_save_scan_data', [$this, 'ajax_save_scan_data']);
        add_action('wp_ajax_asr_generate_code', [$this, 'ajax_generate_code']);
        add_action('wp_ajax_asr_toggle_scan_mode', [$this, 'ajax_toggle_scan_mode']);
        add_action('wp_ajax_asr_clear_scan_data', [$this, 'ajax_clear_scan_data']);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $defaults = [
            'scan_mode' => false,
            'detected_slots' => [],
            'configured_slots' => [],
            'last_scan' => null,
        ];

        if (!get_option('asr_settings')) {
            add_option('asr_settings', $defaults);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Disable scan mode on deactivation
        $settings = get_option('asr_settings', []);
        $settings['scan_mode'] = false;
        update_option('asr_settings', $settings);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load translations
        load_plugin_textdomain('ad-space-reserve', false, dirname(ASR_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Initialize admin components
        ASR_Settings_Page::get_instance();
    }

    /**
     * Register admin menu
     */
    public function admin_menu() {
        add_options_page(
            __('Ad Space Reserve', 'ad-space-reserve'),
            __('Ad Space Reserve', 'ad-space-reserve'),
            'manage_options',
            'ad-space-reserve',
            [ASR_Settings_Page::get_instance(), 'render_page']
        );
    }

    /**
     * Enqueue scanner script on frontend if scan mode is active
     */
    public function maybe_enqueue_scanner() {
        $settings = get_option('asr_settings', []);

        // Only load scanner if scan mode is enabled and user is admin
        if (!empty($settings['scan_mode']) && current_user_can('manage_options')) {
            wp_enqueue_script(
                'asr-scanner',
                ASR_PLUGIN_URL . 'assets/js/scanner.js',
                [],
                ASR_VERSION,
                true // Load in footer
            );

            wp_localize_script('asr-scanner', 'asrScanner', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('asr_scanner_nonce'),
                'isAdmin' => current_user_can('manage_options'),
            ]);
        }
    }

    /**
     * AJAX: Save scan data from frontend
     */
    public function ajax_save_scan_data() {
        check_ajax_referer('asr_scanner_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $slots_raw = $_POST['slots'] ?? [];

        // Parse JSON string if needed
        if (is_string($slots_raw)) {
            $slots_raw = json_decode(stripslashes($slots_raw), true);
        }

        $scanner = new ASR_Scanner();
        $result = $scanner->save_scan_data($slots_raw);

        if ($result) {
            wp_send_json_success(['message' => 'Scan data saved', 'count' => count($slots_raw)]);
        } else {
            wp_send_json_error(['message' => 'Failed to save scan data']);
        }
    }

    /**
     * AJAX: Generate code to child theme
     */
    public function ajax_generate_code() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $generator = new ASR_Code_Generator();
            $writer = new ASR_Theme_Writer();

            // Get configured slots
            $settings = get_option('asr_settings', []);
            $slots = $settings['configured_slots'] ?? [];

            if (empty($slots) || !is_array($slots)) {
                wp_send_json_error(['message' => 'No slots configured']);
            }

            // Expand slots with placeholder infinite slots up to max setting
            // This method is defensive and won't throw on malformed data
            $expanded_slots = $generator->expand_infinite_slots($slots);

            // Fallback to original slots if expansion returned empty
            if (empty($expanded_slots)) {
                $expanded_slots = $slots;
            }

            // Generate PHP and CSS with expanded slots
            $php_code = $generator->generate_php($expanded_slots);
            $css_code = $generator->generate_css($expanded_slots);

            // Validate generated code is not empty
            if (empty($php_code) || empty($css_code)) {
                wp_send_json_error(['message' => 'Failed to generate code. Please check your slot configuration.']);
            }

            // Write to child theme
            $result = $writer->write_files($php_code, $css_code);

            if ($result['success']) {
                wp_send_json_success([
                    'message' => 'Code generated successfully',
                    'files' => $result['files'],
                    'php_preview' => $php_code,
                    'css_preview' => $css_code,
                ]);
            } else {
                wp_send_json_error(['message' => $result['error']]);
            }

        } catch (Exception $e) {
            // Log error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ASR code generation error: ' . $e->getMessage());
            }
            wp_send_json_error(['message' => 'An unexpected error occurred during code generation.']);
        }
    }

    /**
     * AJAX: Toggle scan mode
     */
    public function ajax_toggle_scan_mode() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $settings = get_option('asr_settings', []);
        $settings['scan_mode'] = !empty($_POST['enabled']);

        if ($settings['scan_mode']) {
            $settings['last_scan'] = current_time('mysql');
        }

        update_option('asr_settings', $settings);

        wp_send_json_success([
            'scan_mode' => $settings['scan_mode'],
            'message' => $settings['scan_mode'] ? 'Scan mode enabled' : 'Scan mode disabled',
        ]);
    }

    /**
     * AJAX: Clear scan data
     */
    public function ajax_clear_scan_data() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $settings = get_option('asr_settings', []);
        $settings['detected_slots'] = [];
        update_option('asr_settings', $settings);

        wp_send_json_success(['message' => 'Scan data cleared']);
    }

    /**
     * Get plugin settings
     */
    public static function get_settings() {
        return get_option('asr_settings', []);
    }

    /**
     * Update plugin settings
     */
    public static function update_settings($settings) {
        return update_option('asr_settings', $settings);
    }
}

// Initialize plugin
function asr_init() {
    return Ad_Space_Reserve::get_instance();
}
add_action('plugins_loaded', 'asr_init');
