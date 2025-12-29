<?php
/**
 * Admin Settings Page
 *
 * @package Ad_Space_Reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASR_Settings_Page {

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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_asr_configure_slot', [$this, 'ajax_configure_slot']);
        add_action('wp_ajax_asr_unconfigure_slot', [$this, 'ajax_unconfigure_slot']);
        add_action('wp_ajax_asr_update_slot', [$this, 'ajax_update_slot']);
        add_action('wp_ajax_asr_save_max_infinite_slots', [$this, 'ajax_save_max_infinite_slots']);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'settings_page_ad-space-reserve') {
            return;
        }

        wp_enqueue_style(
            'asr-admin',
            ASR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ASR_VERSION
        );

        wp_enqueue_script(
            'asr-admin',
            ASR_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ASR_VERSION,
            true
        );

        wp_localize_script('asr-admin', 'asrAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asr_admin_nonce'),
            'strings' => [
                'confirmClear' => __('Are you sure you want to clear all scan data?', 'ad-space-reserve'),
                'confirmGenerate' => __('This will write files to your child theme. Continue?', 'ad-space-reserve'),
                'generating' => __('Generating...', 'ad-space-reserve'),
                'success' => __('Success!', 'ad-space-reserve'),
                'error' => __('Error occurred', 'ad-space-reserve'),
            ],
        ]);
    }

    /**
     * Render the settings page
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('asr_settings', []);
        $scanner = new ASR_Scanner();
        $detected_slots = $scanner->get_detected_slots();
        $configured_slots = $scanner->get_configured_slots();
        $scan_mode = !empty($settings['scan_mode']);
        $last_scan = $settings['last_scan'] ?? null;

        ?>
        <div class="wrap asr-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="asr-admin-grid">
                <!-- Scan Mode Card -->
                <div class="asr-card">
                    <h2><?php _e('Scan Mode', 'ad-space-reserve'); ?></h2>
                    <p class="description">
                        <?php _e('Enable scan mode, then visit your site\'s pages as an admin. The plugin will detect R89 ad slots automatically.', 'ad-space-reserve'); ?>
                    </p>

                    <div class="asr-scan-controls">
                        <label class="asr-toggle">
                            <input type="checkbox" id="asr-scan-mode" <?php checked($scan_mode); ?>>
                            <span class="asr-toggle-slider"></span>
                            <span class="asr-toggle-label">
                                <?php echo $scan_mode ? __('Scan Mode Active', 'ad-space-reserve') : __('Scan Mode Disabled', 'ad-space-reserve'); ?>
                            </span>
                        </label>

                        <?php if ($last_scan): ?>
                            <p class="asr-last-scan">
                                <?php printf(__('Last scan: %s', 'ad-space-reserve'), esc_html($last_scan)); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($scan_mode): ?>
                        <div class="asr-notice asr-notice-info">
                            <p>
                                <?php _e('Scan mode is active. Visit your site pages to detect ad slots. Detected slots will appear below.', 'ad-space-reserve'); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" class="button">
                                    <?php _e('Visit Site', 'ad-space-reserve'); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Detected Slots Card -->
                <div class="asr-card">
                    <div class="asr-card-header">
                        <h2><?php _e('Detected Slots', 'ad-space-reserve'); ?></h2>
                        <?php if (!empty($detected_slots)): ?>
                            <button type="button" class="button" id="asr-clear-scan">
                                <?php _e('Clear All', 'ad-space-reserve'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($detected_slots)): ?>
                        <p class="asr-empty-state">
                            <?php _e('No ad slots detected yet. Enable scan mode and visit your site.', 'ad-space-reserve'); ?>
                        </p>
                    <?php else: ?>
                        <table class="widefat asr-slots-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Slot', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Device', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Height', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Page', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Actions', 'ad-space-reserve'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detected_slots as $slot): ?>
                                    <?php
                                    $is_configured = isset($configured_slots[$slot['wrapperId']]);
                                    $suggested_height = $scanner->get_suggested_height($slot);
                                    ?>
                                    <tr class="<?php echo $is_configured ? 'asr-configured' : ''; ?>">
                                        <td>
                                            <strong><?php echo esc_html($slot['slotType']); ?></strong>
                                            <br>
                                            <code class="asr-wrapper-id"><?php echo esc_html($slot['wrapperId']); ?></code>
                                        </td>
                                        <td>
                                            <span class="asr-device-badge asr-device-<?php echo esc_attr($slot['device']); ?>">
                                                <?php echo esc_html(ucfirst($slot['device'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo esc_html($slot['renderedHeight']); ?>px
                                            <?php if ($slot['renderedHeight'] != $suggested_height): ?>
                                                <br>
                                                <small>(suggested: <?php echo esc_html($suggested_height); ?>px)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo esc_html($slot['pageUrl']); ?></small>
                                            <br>
                                            <span class="asr-page-type"><?php echo esc_html($slot['pageType']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($is_configured): ?>
                                                <span class="asr-status-configured">
                                                    <?php _e('Configured', 'ad-space-reserve'); ?>
                                                </span>
                                            <?php else: ?>
                                                <button type="button" class="button asr-configure-slot"
                                                    data-wrapper-id="<?php echo esc_attr($slot['wrapperId']); ?>"
                                                    data-height="<?php echo esc_attr($suggested_height); ?>">
                                                    <?php _e('Configure', 'ad-space-reserve'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Configured Slots Card -->
                <div class="asr-card">
                    <div class="asr-card-header">
                        <h2><?php _e('Configured Slots', 'ad-space-reserve'); ?></h2>
                        <?php if (!empty($configured_slots)): ?>
                            <button type="button" class="button button-primary" id="asr-generate-code">
                                <?php _e('Generate Code', 'ad-space-reserve'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($configured_slots)): ?>
                        <p class="asr-empty-state">
                            <?php _e('No slots configured yet. Configure detected slots above.', 'ad-space-reserve'); ?>
                        </p>
                    <?php else: ?>
                        <table class="widefat asr-slots-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Slot', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('CSS Class', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Min Height', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Injection', 'ad-space-reserve'); ?></th>
                                    <th><?php _e('Actions', 'ad-space-reserve'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($configured_slots as $wrapperId => $slot): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($slot['slotType']); ?></strong>
                                            <span class="asr-device-badge asr-device-<?php echo esc_attr($slot['device']); ?>">
                                                <?php echo esc_html(ucfirst($slot['device'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code>.<?php echo esc_html($slot['cssClass']); ?></code>
                                        </td>
                                        <td>
                                            <input type="number" class="asr-height-input"
                                                value="<?php echo esc_attr($slot['minHeight']); ?>"
                                                data-wrapper-id="<?php echo esc_attr($wrapperId); ?>"
                                                min="0" step="10">px
                                        </td>
                                        <td>
                                            <select class="asr-injection-select" data-wrapper-id="<?php echo esc_attr($wrapperId); ?>">
                                                <option value="after_paragraph" <?php selected($slot['injectionLocation'] ?? '', 'after_paragraph'); ?>>
                                                    <?php _e('After paragraph', 'ad-space-reserve'); ?>
                                                </option>
                                                <option value="before_paragraph" <?php selected($slot['injectionLocation'] ?? '', 'before_paragraph'); ?>>
                                                    <?php _e('Before paragraph', 'ad-space-reserve'); ?>
                                                </option>
                                                <option value="content_start" <?php selected($slot['injectionLocation'] ?? '', 'content_start'); ?>>
                                                    <?php _e('Content start', 'ad-space-reserve'); ?>
                                                </option>
                                                <option value="content_end" <?php selected($slot['injectionLocation'] ?? '', 'content_end'); ?>>
                                                    <?php _e('Content end', 'ad-space-reserve'); ?>
                                                </option>
                                            </select>
                                            <input type="number" class="asr-position-input small-text"
                                                value="<?php echo esc_attr($slot['injectionPosition'] ?? 3); ?>"
                                                data-wrapper-id="<?php echo esc_attr($wrapperId); ?>"
                                                min="1" max="99">
                                        </td>
                                        <td>
                                            <button type="button" class="button asr-remove-slot"
                                                data-wrapper-id="<?php echo esc_attr($wrapperId); ?>">
                                                <?php _e('Remove', 'ad-space-reserve'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Code Preview Card -->
                <div class="asr-card" id="asr-preview-card" style="display: none;">
                    <h2><?php _e('Generated Code Preview', 'ad-space-reserve'); ?></h2>

                    <div class="asr-code-preview">
                        <h3><?php _e('PHP (asr-containers.php)', 'ad-space-reserve'); ?></h3>
                        <pre id="asr-php-preview"></pre>
                    </div>

                    <div class="asr-code-preview">
                        <h3><?php _e('CSS (asr-styles.css)', 'ad-space-reserve'); ?></h3>
                        <pre id="asr-css-preview"></pre>
                    </div>

                    <div class="asr-r89-helper">
                        <h3><?php _e('R89 Dashboard Configuration', 'ad-space-reserve'); ?></h3>
                        <p><?php _e('Update your R89 dashboard to target these CSS selectors:', 'ad-space-reserve'); ?></p>
                        <ul id="asr-r89-targets"></ul>
                    </div>
                </div>

                <!-- Generation Settings Card -->
                <div class="asr-card">
                    <h2><?php _e('Generation Settings', 'ad-space-reserve'); ?></h2>
                    <p class="description">
                        <?php _e('Configure how placeholder containers are generated for infinite/in-content ad slots.', 'ad-space-reserve'); ?>
                    </p>

                    <table class="form-table asr-settings-table">
                        <tr>
                            <th scope="row">
                                <label for="asr-max-infinite-slots"><?php _e('Max Infinite Slots', 'ad-space-reserve'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="asr-max-infinite-slots" name="max_infinite_slots"
                                    value="<?php echo esc_attr($settings['max_infinite_slots'] ?? 10); ?>"
                                    min="1" max="50" class="small-text">
                                <button type="button" class="button" id="asr-save-max-infinite">
                                    <?php _e('Save', 'ad-space-reserve'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Generate this many infinite/in-content slot containers, even if fewer were detected during scanning. This ensures articles with varying numbers of ads all have pre-sized containers.', 'ad-space-reserve'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Help Card -->
                <div class="asr-card asr-help-card">
                    <h2><?php _e('How It Works', 'ad-space-reserve'); ?></h2>
                    <ol>
                        <li><?php _e('Enable Scan Mode and visit your site pages', 'ad-space-reserve'); ?></li>
                        <li><?php _e('The plugin detects R89 ad wrappers automatically', 'ad-space-reserve'); ?></li>
                        <li><?php _e('Configure detected slots with appropriate heights', 'ad-space-reserve'); ?></li>
                        <li><?php _e('Click "Generate Code" to write files to your child theme', 'ad-space-reserve'); ?></li>
                        <li><?php _e('Update R89 dashboard to target the new .asr-* classes', 'ad-space-reserve'); ?></li>
                    </ol>
                    <p class="asr-help-note">
                        <?php _e('The generated code creates server-side containers with min-height, preventing CLS before any JavaScript runs.', 'ad-space-reserve'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Configure a slot
     */
    public function ajax_configure_slot() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $wrapperId = sanitize_text_field($_POST['wrapperId'] ?? '');
        $config = [
            'minHeight' => absint($_POST['minHeight'] ?? 250),
            'enabled' => true,
            'injectionLocation' => sanitize_text_field($_POST['injectionLocation'] ?? 'after_paragraph'),
            'injectionPosition' => absint($_POST['injectionPosition'] ?? 3),
        ];

        $scanner = new ASR_Scanner();
        $result = $scanner->configure_slot($wrapperId, $config);

        if ($result) {
            wp_send_json_success(['message' => 'Slot configured']);
        } else {
            wp_send_json_error(['message' => 'Failed to configure slot']);
        }
    }

    /**
     * AJAX: Unconfigure a slot
     */
    public function ajax_unconfigure_slot() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $wrapperId = sanitize_text_field($_POST['wrapperId'] ?? '');

        $scanner = new ASR_Scanner();
        $result = $scanner->unconfigure_slot($wrapperId);

        if ($result) {
            wp_send_json_success(['message' => 'Slot removed']);
        } else {
            wp_send_json_error(['message' => 'Failed to remove slot']);
        }
    }

    /**
     * AJAX: Update a configured slot
     */
    public function ajax_update_slot() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $wrapperId = sanitize_text_field($_POST['wrapperId'] ?? '');
        $field = sanitize_text_field($_POST['field'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        $settings = get_option('asr_settings', []);
        $configured = $settings['configured_slots'] ?? [];

        if (!isset($configured[$wrapperId])) {
            wp_send_json_error(['message' => 'Slot not found']);
        }

        // Update the specific field
        switch ($field) {
            case 'minHeight':
                $configured[$wrapperId]['minHeight'] = absint($value);
                break;
            case 'injectionLocation':
                $configured[$wrapperId]['injectionLocation'] = $value;
                break;
            case 'injectionPosition':
                $configured[$wrapperId]['injectionPosition'] = absint($value);
                break;
        }

        $settings['configured_slots'] = $configured;
        update_option('asr_settings', $settings);

        wp_send_json_success(['message' => 'Slot updated']);
    }

    /**
     * AJAX: Save max infinite slots setting
     */
    public function ajax_save_max_infinite_slots() {
        check_ajax_referer('asr_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $max_infinite_slots = absint($_POST['max_infinite_slots'] ?? 10);

            // Clamp value between 1 and 50
            $max_infinite_slots = max(1, min(50, $max_infinite_slots));

            $settings = get_option('asr_settings', []);

            // Ensure settings is an array (defensive against corrupted option)
            if (!is_array($settings)) {
                $settings = [];
            }

            $settings['max_infinite_slots'] = $max_infinite_slots;
            $updated = update_option('asr_settings', $settings);

            if ($updated === false && get_option('asr_settings') !== $settings) {
                // update_option returns false if value unchanged OR if it failed
                // Check if the stored value matches what we tried to save
                wp_send_json_error(['message' => 'Failed to save setting. Please try again.']);
            }

            wp_send_json_success([
                'message' => 'Setting saved',
                'value' => $max_infinite_slots,
            ]);

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ASR save max infinite slots error: ' . $e->getMessage());
            }
            wp_send_json_error(['message' => 'An unexpected error occurred.']);
        }
    }
}
