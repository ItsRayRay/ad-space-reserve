<?php
/**
 * Admin Settings Page class.
 *
 * @package AdSpaceReserve\Admin
 */

namespace AdSpaceReserve\Admin;

use AdSpaceReserve\Core\Slot;
use AdSpaceReserve\Generator\Code_Generator;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include slot defaults for slot type dropdown.
require_once ASR_PLUGIN_DIR . 'includes/class-slot-defaults.php';

/**
 * Admin settings page for the AdShimmer plugin.
 *
 * Handles WordPress admin menu registration and page rendering.
 */
class Settings_Page {

    /**
     * AJAX nonce action name.
     */
    public const NONCE_ACTION = 'asr_admin_nonce';

    /**
     * Required capability for admin access.
     */
    public const CAPABILITY = 'manage_options';

    /**
     * Singleton instance.
     *
     * @var Settings_Page|null
     */
    private static ?Settings_Page $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Settings_Page Settings page instance.
     */
    public static function get_instance(): Settings_Page {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_asr_save_slot', [ $this, 'ajax_save_slot' ] );
        add_action( 'wp_ajax_asr_delete_slot', [ $this, 'ajax_delete_slot' ] );
        add_action( 'wp_ajax_asr_generate_code', [ $this, 'ajax_generate_code' ] );
        add_action( 'wp_ajax_asr_deploy_code', [ $this, 'ajax_deploy_code' ] );
        add_action( 'wp_ajax_asr_save_max_infinite_slots', [ $this, 'ajax_save_max_infinite_slots' ] );
        add_action( 'wp_ajax_asr_save_cleanup_setting', [ $this, 'ajax_save_cleanup_setting' ] );
        add_action( 'wp_ajax_asr_save_header_code', [ $this, 'ajax_save_header_code' ] );
        add_action( 'wp_ajax_asr_save_header_blocks', [ $this, 'ajax_save_header_blocks' ] );
        add_action( 'wp_ajax_asr_save_attribution', [ $this, 'ajax_save_attribution' ] );
        // License AJAX handlers (feature-gated).
        if ( ASR_FEATURE_LICENSE ) {
            add_action( 'wp_ajax_asr_activate_license', [ $this, 'ajax_activate_license' ] );
            add_action( 'wp_ajax_asr_deactivate_license', [ $this, 'ajax_deactivate_license' ] );
            add_action( 'wp_ajax_asr_check_license', [ $this, 'ajax_check_license' ] );
        }
        // Ads.txt AJAX handler.
        add_action( 'wp_ajax_asr_save_adstxt', [ $this, 'ajax_save_adstxt' ] );
        add_action( 'wp_ajax_asr_export_slots', [ $this, 'ajax_export_slots' ] );
        add_action( 'wp_ajax_asr_import_slots', [ $this, 'ajax_import_slots' ] );
        // AI AJAX handlers (feature-gated).
        if ( ASR_FEATURE_AI ) {
            // OpenRouter API key handler.
            add_action( 'wp_ajax_asr_save_openrouter_key', [ $this, 'ajax_save_openrouter_key' ] );
            // Chat conversation handlers.
            add_action( 'wp_ajax_asr_chat_get_conversations', [ $this, 'ajax_chat_get_conversations' ] );
            add_action( 'wp_ajax_asr_chat_get_conversation', [ $this, 'ajax_chat_get_conversation' ] );
            add_action( 'wp_ajax_asr_chat_create_conversation', [ $this, 'ajax_chat_create_conversation' ] );
            add_action( 'wp_ajax_asr_chat_add_message', [ $this, 'ajax_chat_add_message' ] );
            add_action( 'wp_ajax_asr_chat_delete_conversation', [ $this, 'ajax_chat_delete_conversation' ] );
            add_action( 'wp_ajax_asr_chat_send_message', [ $this, 'ajax_chat_send_message' ] );
            add_action( 'wp_ajax_asr_chat_get_greeting', [ $this, 'ajax_chat_get_greeting' ] );
        }
    }

    /**
     * Enqueue admin assets.
     *
     * Only loads on the settings page for performance.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_adshimmer' !== $hook ) {
            return;
        }

        // Enqueue media library for fallback image selection.
        wp_enqueue_media();

        wp_enqueue_style(
            'asr-admin',
            ASR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ASR_VERSION
        );

        wp_enqueue_script(
            'asr-admin',
            ASR_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            ASR_VERSION,
            true
        );

        wp_localize_script( 'asr-admin', 'asrAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'asr_admin_nonce' ),
            'nonces'  => $this->get_action_nonces(),
            'strings' => [
                'confirmGenerate'  => __( 'This will generate CSS and PHP code. Continue?', 'adshimmer' ),
                'generating'       => __( 'Generating...', 'adshimmer' ),
                'success'          => __( 'Success!', 'adshimmer' ),
                'error'            => __( 'Error occurred', 'adshimmer' ),
                'addSlot'          => __( 'Add New Slot', 'adshimmer' ),
                'editSlot'         => __( 'Edit Slot', 'adshimmer' ),
                'nameRequired'     => __( 'Slot name is required.', 'adshimmer' ),
                'selectorRequired' => __( 'Target selector is required.', 'adshimmer' ),
                'selectorInvalid'  => __( 'Selector must start with . or # or be a valid tag name.', 'adshimmer' ),
                'heightRequired'   => __( 'Minimum height must be a positive number.', 'adshimmer' ),
                'confirmDelete'    => __( 'Are you sure you want to delete "%s"?', 'adshimmer' ),
                'slotDeleted'      => __( 'Slot deleted successfully', 'adshimmer' ),
                'slotSaved'        => __( 'Slot saved successfully', 'adshimmer' ),
                'deploying'        => __( 'Deploying...', 'adshimmer' ),
                'deployed'         => __( 'Deployed!', 'adshimmer' ),
                'noCodeGenerated'  => __( 'Please generate code first.', 'adshimmer' ),
                'confirmDeploy'    => __( 'This will deploy generated files. Any existing files will be backed up. Continue?', 'adshimmer' ),
                'deployToTheme'    => __( 'Deploy to Theme', 'adshimmer' ),
                'saving'           => __( 'Saving...', 'adshimmer' ),
                'headerSaved'      => __( 'Header code saved.', 'adshimmer' ),
                'savingHeader'     => __( 'Saving header code...', 'adshimmer' ),
                'adstxtSaved'        => __( 'Ads.txt settings saved.', 'adshimmer' ),
                'savingAdstxt'       => __( 'Saving ads.txt settings...', 'adshimmer' ),
                'adstxtInvalidUrl'   => __( 'Please enter a valid URL for redirect mode.', 'adshimmer' ),
                'headerBlocksSaved'  => __( 'Header code blocks saved.', 'adshimmer' ),
                'savingHeaderBlocks' => __( 'Saving...', 'adshimmer' ),
                'addHeaderBlock'     => __( 'Add Code Block', 'adshimmer' ),
                'removeBlock'        => __( 'Remove', 'adshimmer' ),
                'blockLabel'         => __( 'Label', 'adshimmer' ),
                'blockCode'          => __( 'Code', 'adshimmer' ),
                'confirmRemoveBlock' => __( 'Remove this code block?', 'adshimmer' ),
                // OpenRouter API key strings.
                'savingKey'          => __( 'Validating...', 'adshimmer' ),
                'keySaved'           => __( 'API key saved and validated successfully.', 'adshimmer' ),
                'keyCleared'         => __( 'API key cleared.', 'adshimmer' ),
                'keyInvalidFormat'   => __( 'Invalid API key format. Keys should start with "sk-or-".', 'adshimmer' ),
                'exporting'          => __( 'Exporting...', 'adshimmer' ),
                'importing'          => __( 'Importing...', 'adshimmer' ),
                'importFailed'       => __( 'Import failed', 'adshimmer' ),
                'importConfirmTitle' => __( 'Import Slots', 'adshimmer' ),
                'importConfirmBody'  => __( 'This will add the following slots to your configuration:', 'adshimmer' ),
                'importSuccess'      => __( 'Import complete!', 'adshimmer' ),
                'noSlotsToExport'    => __( 'No slots to export.', 'adshimmer' ),
                'exportFailed'       => __( 'Export failed.', 'adshimmer' ),
            ],
        ] );

        // Add slot data for type dropdown auto-population.
        wp_add_inline_script( 'asr-admin', 'asrAdmin.slotDefaults = ' . wp_json_encode( [
            'desktop' => \ASR_Slot_Defaults::get_desktop_slots(),
            'mobile'  => \ASR_Slot_Defaults::get_mobile_slots(),
        ] ) . ';', 'before' );
    }

    /**
     * Generate per-action CSRF nonces for all AJAX handlers.
     *
     * @return array<string, string> Map of action name to nonce value.
     */
    private function get_action_nonces(): array {
        $actions = [
            'asr_save_slot',
            'asr_delete_slot',
            'asr_generate_code',
            'asr_deploy_code',
            'asr_save_max_infinite_slots',
            'asr_save_cleanup_setting',
            'asr_save_header_code',
            'asr_save_header_blocks',
            'asr_save_attribution',
            'asr_activate_license',
            'asr_deactivate_license',
            'asr_check_license',
            'asr_save_adstxt',
            'asr_export_slots',
            'asr_import_slots',
            'asr_save_openrouter_key',
            'asr_chat_get_conversations',
            'asr_chat_get_conversation',
            'asr_chat_create_conversation',
            'asr_chat_add_message',
            'asr_chat_delete_conversation',
            'asr_chat_send_message',
            'asr_chat_get_greeting',
        ];

        $nonces = [];
        foreach ( $actions as $action ) {
            $nonces[ $action ] = wp_create_nonce( $action );
        }

        return $nonces;
    }

    /**
     * AJAX handler for saving a slot (create or update).
     *
     * @return void
     */
    public function ajax_save_slot(): void {
        check_ajax_referer( 'asr_save_slot', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        $slot_data = $this->sanitize_slot_data(
            [
                'id'                 => $_POST['slot_id'] ?? '',
                'name'               => $_POST['name'] ?? '',
                'type'               => $_POST['type'] ?? '',
                'device'             => $_POST['device'] ?? '',
                'selector'           => $_POST['selector'] ?? '',
                'placement'          => $_POST['placement'] ?? '',
                'min_height'         => $_POST['min_height'] ?? 250,
                'margin_top'         => $_POST['margin_top'] ?? 0,
                'margin_bottom'      => $_POST['margin_bottom'] ?? 0,
                'position'           => $_POST['position'] ?? 0,
                'target_paths'       => $_POST['target_paths'] ?? '',
                'is_sticky'          => $_POST['is_sticky'] ?? false,
                'sticky_offset'      => $_POST['sticky_offset'] ?? 0,
                'has_fallback'       => $_POST['has_fallback'] ?? false,
                'fallback_image_url' => $_POST['fallback_image_url'] ?? '',
                'fallback_timeout'   => $_POST['fallback_timeout'] ?? 2000,
                'fallback_link_url'  => $_POST['fallback_link_url'] ?? '',
                'custom_css'         => $_POST['custom_css'] ?? '',
                'selector_mode'      => $_POST['selector_mode'] ?? 'first',
            ],
            true
        );

        // Generate ID if not provided (new slot).
        if ( empty( $slot_data['id'] ) ) {
            $slot_data['id'] = Slot::generate_id( $slot_data['name'] );
        }

        // Create Slot and validate.
        $slot   = Slot::from_array( $slot_data );
        $errors = $slot->validate();

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'message' => implode( ', ', $errors ),
                'errors'  => $errors,
            ] );
        }

        // Save via Slot_Collection.
        $collection = \AdSpaceReserve\Plugin::get_instance()->get_slots();
        $is_new     = ! $collection->exists( $slot_data['id'] );

        if ( $is_new ) {
            $success = $collection->add( $slot );
        } else {
            $success = $collection->update( $slot );
        }

        if ( $success ) {
            // Clear generation transients to force re-generation.
            delete_transient( 'asr_generated_css' );
            delete_transient( 'asr_generated_js' );

            wp_send_json_success( [
                'message' => $is_new
                    ? __( 'Slot created. Regenerate and deploy to apply changes.', 'adshimmer' )
                    : __( 'Slot updated. Regenerate and deploy to apply changes.', 'adshimmer' ),
                'slot'    => $slot->to_array(),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to save slot', 'adshimmer' ) ] );
        }
    }

    /**
     * AJAX handler for deleting a slot.
     *
     * @return void
     */
    public function ajax_delete_slot(): void {
        check_ajax_referer( 'asr_delete_slot', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        $slot_id = sanitize_key( $_POST['slot_id'] ?? '' );

        if ( empty( $slot_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Slot ID required', 'adshimmer' ) ] );
        }

        $collection = \AdSpaceReserve\Plugin::get_instance()->get_slots();

        if ( ! $collection->exists( $slot_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Slot not found', 'adshimmer' ) ] );
        }

        $success = $collection->delete( $slot_id );

        if ( $success ) {
            // Clear generation transients to force re-generation.
            delete_transient( 'asr_generated_css' );
            delete_transient( 'asr_generated_js' );

            wp_send_json_success( [
                'message' => __( 'Slot deleted. Regenerate and deploy to apply changes.', 'adshimmer' ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to delete slot', 'adshimmer' ) ] );
        }
    }

    /**
     * AJAX handler for generating CSS and PHP code.
     *
     * @return void
     */
    public function ajax_generate_code(): void {
        check_ajax_referer( 'asr_generate_code', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        // Get all configured slots.
        $slots       = \AdSpaceReserve\Plugin::get_instance()->get_slots()->get_all();
        $slot_array  = array_values( $slots );
        $slots_count = count( $slot_array );

        // Expand slots with placeholders for infinite slots.
        $expander       = \AdSpaceReserve\Plugin::get_instance()->get_slot_expander();
        $expanded_slots = $expander->expand( $slot_array );
        $expanded_count = count( $expanded_slots );

        // Instantiate the code generator with expanded slots.
        $generator = new Code_Generator( $expanded_slots );

        // Validate slots configuration.
        $errors = $generator->validate();
        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors ] );
        }

        // Generate combined CSS with media query device visibility rules.
        // Single file approach works with full-page caching (Cloudflare, etc.).
        $combined_css = $generator->generate_css();

        // Store combined CSS in transient for deployment.
        set_transient( 'asr_generated_css', $combined_css, HOUR_IN_SECONDS );

        // Check if any slots need fallback script.
        $has_fallback = $generator->has_fallback_slots();
        $fallback_js  = '';

        if ( $has_fallback ) {
            $js_path = ASR_PLUGIN_DIR . 'assets/js/asr-fallback.js';
            if ( file_exists( $js_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $fallback_js = file_get_contents( $js_path );
            }
        }

        set_transient( 'asr_generated_js', $fallback_js, HOUR_IN_SECONDS );

        // Calculate CSS lines.
        $css_lines = substr_count( $combined_css, "\n" );

        // Return success with preview data.
        wp_send_json_success( [
            'css_preview'    => substr( $combined_css, 0, 500 ),
            'css_lines'      => $css_lines,
            'slots_count'    => $slots_count,
            'expanded_count' => $expanded_count,
            'slots_expanded' => $expanded_count - $slots_count,
            'has_fallback'   => $has_fallback,
        ] );
    }

    /**
     * AJAX handler for deploying generated code to the plugin directory.
     *
     * @return void
     */
    public function ajax_deploy_code(): void {
        check_ajax_referer( 'asr_deploy_code', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        // Get combined generated CSS from transient.
        $combined_css = get_transient( 'asr_generated_css' );
        $fallback_js  = get_transient( 'asr_generated_js' );

        if ( false === $combined_css || empty( $combined_css ) ) {
            wp_send_json_error( [
                'message' => __( 'No generated code found. Please generate code first.', 'adshimmer' ),
            ] );
        }

        // Deploy combined CSS via Theme_Writer.
        $writer = \AdSpaceReserve\Plugin::get_instance()->get_theme_writer();
        $result = $writer->write_files( $combined_css, $fallback_js ?: '' );

        if ( $result['success'] ) {
            // Clear transients after successful deployment.
            delete_transient( 'asr_generated_css' );
            delete_transient( 'asr_generated_js' );

            /**
             * Fires after code has been successfully deployed.
             *
             * This hook allows page cache plugins to clear their caches
             * when new CSS files are deployed.
             *
             * @since 2.0.0
             */
            do_action( 'asr_code_deployed' );

            wp_send_json_success( [
                'message' => __( 'Code deployed successfully.', 'adshimmer' ),
                'files'   => $result['files'] ?? [],
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['error'] ?? __( 'Deployment failed.', 'adshimmer' ),
            ] );
        }
    }

    /**
     * AJAX handler for saving max infinite slots setting.
     *
     * @return void
     */
    public function ajax_save_max_infinite_slots(): void {
        check_ajax_referer( 'asr_save_max_infinite_slots', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $max = isset( $_POST['max_infinite_slots'] ) ? absint( $_POST['max_infinite_slots'] ) : 10;
        $max = max( 1, min( 50, $max ) ); // Clamp to 1-50.

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'max_infinite_slots', $max );

        wp_send_json_success( [
            'message' => __( 'Setting saved.', 'adshimmer' ),
            'value'   => $max,
        ] );
    }

    /**
     * AJAX handler for saving cleanup on deactivate setting.
     *
     * @return void
     */
    public function ajax_save_cleanup_setting(): void {
        check_ajax_referer( 'asr_save_cleanup_setting', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $enabled = isset( $_POST['cleanup_on_deactivate'] ) ? rest_sanitize_boolean( $_POST['cleanup_on_deactivate'] ) : false;

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'cleanup_on_deactivate', $enabled );

        wp_send_json_success( [
            'message' => __( 'Setting saved.', 'adshimmer' ),
            'enabled' => $enabled,
        ] );
    }

    /**
     * AJAX handler for saving header code.
     *
     * @return void
     */
    public function ajax_save_header_code(): void {
        check_ajax_referer( 'asr_save_header_code', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        // Header code is emitted verbatim into wp_head, so saving it requires
        // unfiltered_html rather than manage_options alone. Core denies
        // unfiltered_html to non-super-admins on multisite and to everyone when
        // DISALLOW_UNFILTERED_HTML is set; manage_options would bypass both.
        $header_code = isset( $_POST['header_code'] ) ? wp_unslash( $_POST['header_code'] ) : '';
        if ( ! is_string( $header_code ) ) {
            $header_code = '';
        }

        if ( '' !== trim( $header_code ) && ! current_user_can( 'unfiltered_html' ) ) {
            wp_send_json_error( [ 'message' => __( 'Your account cannot save header scripts on this site.', 'adshimmer' ) ] );
            return;
        }

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'header_code', $header_code );

        wp_send_json_success( [
            'message' => __( 'Header code saved.', 'adshimmer' ),
        ] );
    }

    /**
     * AJAX handler for saving header code blocks.
     *
     * Receives blocks as JSON array, validates and sanitizes each block,
     * then saves to header_code_blocks option.
     *
     * @return void
     */
    public function ajax_save_header_blocks(): void {
        check_ajax_referer( 'asr_save_header_blocks', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        // Get blocks from POST data.
        $blocks_raw = isset( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : '[]';
        $blocks     = json_decode( $blocks_raw, true );

        if ( ! is_array( $blocks ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid blocks data.', 'adshimmer' ) ] );
            return;
        }

        // Block code is emitted verbatim into wp_head, so saving any non-empty
        // code requires unfiltered_html rather than manage_options alone.
        $has_code = false;
        foreach ( $blocks as $block ) {
            if ( is_array( $block ) && '' !== trim( (string) ( $block['code'] ?? '' ) ) ) {
                $has_code = true;
                break;
            }
        }

        if ( $has_code && ! current_user_can( 'unfiltered_html' ) ) {
            wp_send_json_error( [ 'message' => __( 'Your account cannot save header scripts on this site.', 'adshimmer' ) ] );
            return;
        }

        $sanitized_blocks = [];

        foreach ( $blocks as $block ) {
            // Skip invalid blocks.
            if ( ! is_array( $block ) ) {
                continue;
            }

            // Generate ID if empty.
            $id = ! empty( $block['id'] ) ? sanitize_key( $block['id'] ) : 'block-' . time() . '-' . wp_rand( 1000, 9999 );

            // Sanitize label (max 100 chars).
            $label = ! empty( $block['label'] ) ? sanitize_text_field( substr( $block['label'], 0, 100 ) ) : '';

            // Code is trusted admin input - just unslash, don't sanitize.
            $code = isset( $block['code'] ) ? $block['code'] : '';

            // Enabled boolean.
            $enabled = isset( $block['enabled'] ) ? rest_sanitize_boolean( $block['enabled'] ) : true;

            $sanitized_blocks[] = [
                'id'      => $id,
                'label'   => $label,
                'code'    => $code,
                'enabled' => $enabled,
            ];
        }

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'header_code_blocks', $sanitized_blocks );

        wp_send_json_success( [
            'message' => __( 'Header code blocks saved.', 'adshimmer' ),
            'count'   => count( $sanitized_blocks ),
        ] );
    }

    /**
     * AJAX handler for saving attribution setting.
     *
     * @return void
     */
    public function ajax_save_attribution(): void {
        check_ajax_referer( 'asr_save_attribution', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $enabled = isset( $_POST['show_attribution'] ) ? rest_sanitize_boolean( $_POST['show_attribution'] ) : false;

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'show_attribution', $enabled );

        wp_send_json_success( [
            'message' => __( 'Setting saved.', 'adshimmer' ),
            'enabled' => $enabled,
        ] );
    }

    /**
     * AJAX handler for activating a license.
     *
     * Stub - API integration will be added in plan 20-02.
     *
     * @return void
     */
    public function ajax_activate_license(): void {
        check_ajax_referer( 'asr_activate_license', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        // Stub - LemonSqueezy API integration pending.
        wp_send_json_error( [
            'message' => __( 'License API not configured. This will be enabled in the next update.', 'adshimmer' ),
        ] );
    }

    /**
     * AJAX handler for deactivating a license.
     *
     * Stub - API integration will be added in plan 20-02.
     *
     * @return void
     */
    public function ajax_deactivate_license(): void {
        check_ajax_referer( 'asr_deactivate_license', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        // Stub - LemonSqueezy API integration pending.
        wp_send_json_error( [
            'message' => __( 'License API not configured. This will be enabled in the next update.', 'adshimmer' ),
        ] );
    }

    /**
     * AJAX handler for checking license status.
     *
     * Returns current stored license data.
     *
     * @return void
     */
    public function ajax_check_license(): void {
        check_ajax_referer( 'asr_check_license', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();

        wp_send_json_success( [
            'status'           => $options->get( 'license_status', 'inactive' ),
            'key'              => $options->get( 'license_key', '' ),
            'tier'             => $options->get( 'license_tier', '' ),
            'activation_limit' => $options->get( 'license_activation_limit', 0 ),
            'activation_usage' => $options->get( 'license_activation_usage', 0 ),
            'expires_at'       => $options->get( 'license_expires_at', null ),
        ] );
    }

    /**
     * AJAX handler for saving ads.txt settings.
     *
     * Validates mode and redirect URL, then saves settings.
     * Flushes rewrite rules to update the ads.txt redirect rule.
     *
     * @return void
     */
    public function ajax_save_adstxt(): void {
        check_ajax_referer( 'asr_save_adstxt', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        // Validate mode.
        $valid_modes = [ 'disabled', 'content', 'redirect' ];
        $mode        = isset( $_POST['adstxt_mode'] ) ? sanitize_key( $_POST['adstxt_mode'] ) : 'disabled';

        if ( ! in_array( $mode, $valid_modes, true ) ) {
            $mode = 'disabled';
        }

        // Get content (sanitize but preserve newlines).
        $content = isset( $_POST['adstxt_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['adstxt_content'] ) ) : '';

        // Get and validate redirect URL.
        $redirect_url = isset( $_POST['adstxt_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['adstxt_redirect_url'] ) ) : '';

        // If mode is redirect, URL must be valid.
        $redirect_scheme = ! empty( $redirect_url ) ? wp_parse_url( $redirect_url, PHP_URL_SCHEME ) : '';
        if (
            'redirect' === $mode
            && (
                empty( $redirect_url )
                || ! filter_var( $redirect_url, FILTER_VALIDATE_URL )
                || ! in_array( $redirect_scheme, [ 'http', 'https' ], true )
            )
        ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid URL for redirect mode.', 'adshimmer' ) ] );
            return;
        }

        // Save options.
        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'adstxt_mode', $mode );
        $options->set( 'adstxt_content', $content );
        $options->set( 'adstxt_redirect_url', $redirect_url );

        // Flush rewrite rules to update the ads.txt redirect rule.
        flush_rewrite_rules();

        wp_send_json_success( [
            'message' => __( 'Ads.txt settings saved.', 'adshimmer' ),
            'mode'    => $mode,
        ] );
    }

    /**
     * AJAX handler for exporting all slots as JSON.
     *
     * @return void
     */
    public function ajax_export_slots(): void {
        check_ajax_referer( 'asr_export_slots', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        $collection = \AdSpaceReserve\Plugin::get_instance()->get_slots();
        $slots      = $collection->get_all();
        $slot_data  = [];

        foreach ( $slots as $slot ) {
            $slot_data[] = $slot->to_array();
        }

        $export = [
            'version'        => '1.0',
            'plugin_version' => ASR_VERSION,
            'export_date'    => gmdate( 'c' ),
            'site_url'       => home_url(),
            'slot_count'     => count( $slot_data ),
            'slots'          => $slot_data,
        ];

        $json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( false === $json ) {
            wp_send_json_error( [ 'message' => __( 'Failed to encode slot data.', 'adshimmer' ) ] );
        }

        $filename = 'adshimmer-slots-' . gmdate( 'Y-m-d' ) . '.json';

        // Send as file download.
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }

    /**
     * AJAX handler for importing slots from uploaded JSON file.
     *
     * @return void
     */
    public function ajax_import_slots(): void {
        check_ajax_referer( 'asr_import_slots', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'adshimmer' ) ] );
        }

        // Validate file upload.
        if ( empty( $_FILES['import_file'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Checking file existence.
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'adshimmer' ) ] );
        }

        $file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- File upload handled via finfo + tmp_name.

        if ( UPLOAD_ERR_OK !== $file['error'] ) {
            wp_send_json_error( [ 'message' => __( 'File upload error.', 'adshimmer' ) ] );
        }

        // Max 2MB.
        if ( $file['size'] > 2 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => __( 'File too large. Maximum size is 2MB.', 'adshimmer' ) ] );
        }

        // Validate MIME type.
        $allowed_mimes = [ 'application/json', 'text/plain', 'text/json' ];
        $finfo         = finfo_open( FILEINFO_MIME_TYPE );
        $mime          = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid file type. Please upload a JSON file.', 'adshimmer' ) ] );
        }

        // Read and parse JSON.
        $contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading upload temp file.
        $data     = json_decode( $contents, true );

        if ( null === $data || JSON_ERROR_NONE !== json_last_error() ) {
            wp_send_json_error( [ 'message' => __( 'Invalid JSON file.', 'adshimmer' ) ] );
        }

        // Validate structure.
        if ( ! isset( $data['version'], $data['slots'] ) || ! is_array( $data['slots'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid export file format. Missing version or slots data.', 'adshimmer' ) ] );
        }

        if ( count( $data['slots'] ) > 100 ) {
            wp_send_json_error( [ 'message' => __( 'Too many slots. Maximum 100 slots per import.', 'adshimmer' ) ] );
        }

        $collection = \AdSpaceReserve\Plugin::get_instance()->get_slots();
        $imported   = 0;
        $skipped    = 0;
        $renamed    = 0;
        $errors     = [];

        foreach ( $data['slots'] as $index => $slot_array ) {
            if ( ! is_array( $slot_array ) ) {
                ++$skipped;
                continue;
            }

            // Remove timestamps and sanitize before collision checks.
            unset( $slot_array['created_at'], $slot_array['updated_at'] );
            $slot_array = $this->sanitize_slot_data( $slot_array );

            // If ID already exists, generate a new one (rename, don't replace).
            $was_renamed = false;
            if ( ! empty( $slot_array['id'] ) && $collection->exists( $slot_array['id'] ) ) {
                $slot_array['id'] = Slot::generate_id( $slot_array['name'] ?? 'imported-slot' );
                $was_renamed      = true;
            } elseif ( empty( $slot_array['id'] ) ) {
                $slot_array['id'] = Slot::generate_id( $slot_array['name'] ?? 'imported-slot' );
            }

            try {
                $slot       = Slot::from_array( $slot_array );
                $validation = $slot->validate();

                if ( ! empty( $validation ) ) {
                    ++$skipped;
                    $slot_name = sanitize_text_field( $slot_array['name'] ?? 'Slot ' . ( $index + 1 ) );
                    $errors[]  = sprintf(
                        /* translators: 1: slot name, 2: validation errors */
                        __( 'Skipped "%1$s": %2$s', 'adshimmer' ),
                        $slot_name,
                        implode( ', ', $validation )
                    );
                    continue;
                }

                if ( $collection->add( $slot ) ) {
                    ++$imported;
                    if ( $was_renamed ) {
                        ++$renamed;
                    }
                } else {
                    ++$skipped;
                }
            } catch ( \Exception $e ) {
                ++$skipped;
            }
        }

        // Clear generation transients.
        delete_transient( 'asr_generated_css' );
        delete_transient( 'asr_generated_js' );

        $message = sprintf(
            /* translators: 1: imported count, 2: skipped count, 3: renamed count */
            __( 'Imported %1$d slot(s). Skipped: %2$d. Renamed: %3$d.', 'adshimmer' ),
            $imported,
            $skipped,
            $renamed
        );

        wp_send_json_success( [
            'message'  => $message,
            'imported' => $imported,
            'skipped'  => $skipped,
            'renamed'  => $renamed,
            'errors'   => $errors,
        ] );
    }

    /**
     * Register the settings page in WordPress admin menu.
     *
     * @return void
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'AdShimmer', 'adshimmer' ),
            __( 'AdShimmer', 'adshimmer' ),
            'manage_options',
            'adshimmer',
            [ $this, 'render_page' ],
            'dashicons-money-alt',
            26
        );
    }

    /**
     * Render the settings page content.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();

        ?>
        <div class="wrap asr-admin">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <div class="asr-admin-grid">
                <?php if ( ASR_FEATURE_LICENSE ) : ?>
                <!-- License Card -->
                <div class="asr-card asr-card-license">
                    <h2><?php esc_html_e( 'License', 'adshimmer' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Enter your AdShimmer license key to unlock all features.', 'adshimmer' ); ?>
                    </p>

                    <!-- Status display area (populated by JS based on license_status) -->
                    <div id="asr-license-status" class="asr-license-status"></div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="asr-license-key"><?php esc_html_e( 'License Key', 'adshimmer' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="asr-license-key"
                                       name="license_key"
                                       value="<?php echo esc_attr( $options->get( 'license_key', '' ) ); ?>"
                                       class="regular-text"
                                       placeholder="ASR-XXX-XXXX-XXXX-XXXX">
                                <button type="button" id="asr-activate-license" class="button button-primary">
                                    <?php esc_html_e( 'Activate', 'adshimmer' ); ?>
                                </button>
                                <button type="button" id="asr-deactivate-license" class="button" style="display:none;">
                                    <?php esc_html_e( 'Deactivate', 'adshimmer' ); ?>
                                </button>
                            </td>
                        </tr>
                    </table>

                    <!-- License details (shown when active) -->
                    <div id="asr-license-details" class="asr-license-details" style="display:none;">
                        <p><strong><?php esc_html_e( 'Status:', 'adshimmer' ); ?></strong> <span id="asr-license-status-text"></span></p>
                        <p><strong><?php esc_html_e( 'Sites:', 'adshimmer' ); ?></strong> <span id="asr-license-sites"></span></p>
                        <p><strong><?php esc_html_e( 'Expires:', 'adshimmer' ); ?></strong> <span id="asr-license-expires"></span></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Configured Slots Card -->
                <div class="asr-card">
                    <?php $this->render_slots_table(); ?>
                </div>

                <!-- Slot Expansion Settings Card -->
                <div class="asr-card">
                    <h2><?php esc_html_e( 'Slot Expansion Settings', 'adshimmer' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Configure how placeholder containers are generated for infinite/in-content ad slots.', 'adshimmer' ); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="asr-max-infinite-slots"><?php esc_html_e( 'Max Infinite Slots', 'adshimmer' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="asr-max-infinite-slots"
                                       name="max_infinite_slots"
                                       value="<?php echo esc_attr( $options->get( 'max_infinite_slots', 10 ) ); ?>"
                                       min="1"
                                       max="50"
                                       class="small-text">
                                <button type="button" class="button" id="asr-save-max-infinite">
                                    <?php esc_html_e( 'Save', 'adshimmer' ); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e( 'Generate this many infinite/in-content slot containers during code generation. Ensures articles with varying lengths have pre-sized containers.', 'adshimmer' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Code Generation Card -->
                <div class="asr-card">
                    <div class="asr-card-header">
                        <h2><?php esc_html_e( 'Code Generation', 'adshimmer' ); ?></h2>
                    </div>
                    <div class="asr-card-body">
                        <p><?php esc_html_e( 'Generate CSS and PHP code based on your configured slots. The generated code will reserve space for ads to prevent layout shifts.', 'adshimmer' ); ?></p>

                        <div class="asr-button-group">
                            <button type="button" id="asr-generate-code" class="button button-secondary">
                                <?php esc_html_e( 'Generate Code', 'adshimmer' ); ?>
                            </button>
                            <button type="button" id="asr-deploy-code" class="button button-primary" disabled>
                                <?php esc_html_e( 'Deploy to Theme', 'adshimmer' ); ?>
                            </button>
                        </div>

                        <div id="asr-generate-status" class="asr-generate-status" style="display:none;"></div>
                        <div id="asr-deploy-status" class="asr-deploy-status" style="display:none;"></div>
                        <div id="asr-code-preview" class="asr-code-preview" style="display:none;"></div>
                    </div>
                </div>

                <!-- Header Code Injection Card -->
                <div class="asr-card">
                    <h2><?php esc_html_e( 'Header Code Injection', 'adshimmer' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Add custom code to the <head> section of your site. Use for ad network initialization scripts, tracking pixels, or meta tags. Organize different scripts as separate blocks (e.g., "Google AdSense", "Facebook Pixel").', 'adshimmer' ); ?>
                    </p>
                    <div class="asr-notice asr-notice-warning">
                        <p><?php esc_html_e( 'Only add code from trusted sources. This code will be output exactly as entered.', 'adshimmer' ); ?></p>
                    </div>

                    <?php
                    // Trigger migration if needed.
                    $options->migrate_legacy_header_code();
                    $blocks = $options->get( 'header_code_blocks', [] );
                    ?>

                    <div class="asr-header-blocks" id="asr-header-blocks">
                        <?php if ( ! empty( $blocks ) ) : ?>
                            <?php foreach ( $blocks as $block ) : ?>
                                <div class="asr-header-block" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
                                    <div class="asr-header-block-header">
                                        <input type="checkbox"
                                               class="asr-block-enabled"
                                               <?php checked( ! empty( $block['enabled'] ) ); ?>>
                                        <input type="text"
                                               class="asr-block-label"
                                               value="<?php echo esc_attr( $block['label'] ); ?>"
                                               placeholder="<?php esc_attr_e( 'Block label (e.g., Google AdSense)', 'adshimmer' ); ?>">
                                        <button type="button" class="asr-remove-block button-link-delete">
                                            <?php esc_html_e( 'Remove', 'adshimmer' ); ?>
                                        </button>
                                    </div>
                                    <textarea class="asr-block-code asr-code-textarea"
                                              rows="4"
                                              placeholder="<!-- Paste code here -->"><?php echo esc_textarea( $block['code'] ); ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <p class="asr-button-row">
                        <button type="button" id="asr-add-header-block" class="button">
                            <?php esc_html_e( 'Add Code Block', 'adshimmer' ); ?>
                        </button>
                        <button type="button" id="asr-save-header-blocks" class="button button-primary">
                            <?php esc_html_e( 'Save All Blocks', 'adshimmer' ); ?>
                        </button>
                        <span id="asr-header-blocks-status" class="asr-inline-status"></span>
                    </p>
                </div>

                <?php $this->render_adstxt_section(); ?>

                <?php if ( ASR_FEATURE_AI ) : ?>
                    <?php $this->render_ai_settings_card(); ?>
                <?php endif; ?>

                <!-- Plugin Settings Card -->
                <div class="asr-card">
                    <h2><?php esc_html_e( 'Plugin Settings', 'adshimmer' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="asr-cleanup-on-deactivate"><?php esc_html_e( 'Cleanup on Deactivate', 'adshimmer' ); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="asr-cleanup-on-deactivate"
                                           name="cleanup_on_deactivate"
                                           value="1"
                                           <?php checked( $options->get( 'cleanup_on_deactivate', false ) ); ?>>
                                    <?php esc_html_e( 'Clean up generated files on deactivation', 'adshimmer' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, deactivating the plugin will remove generated CSS/PHP/JS files. The slot configuration will be preserved.', 'adshimmer' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="asr-show-attribution"><?php esc_html_e( 'Show Attribution Link', 'adshimmer' ); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="asr-show-attribution"
                                           name="show_attribution"
                                           value="1"
                                           <?php checked( $options->get( 'show_attribution', true ) ); ?>>
                                    <?php esc_html_e( 'Display "Ad slots by AdShimmer" link in footer', 'adshimmer' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'This tiny link hides behind most sticky footers anyway so nobody is going to notice but it means the world to us. Thanks for keeping it on! ♥', 'adshimmer' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php $this->render_slot_modal(); ?>
            <?php $this->render_import_modal(); ?>

            <?php if ( ASR_FEATURE_AI ) : ?>
                <?php $this->render_ai_chat_trigger(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the configured slots table.
     *
     * @return void
     */
    private function render_slots_table(): void {
        $slots = \AdSpaceReserve\Plugin::get_instance()->get_slots()->get_all();
        $count = count( $slots );

        ?>
        <div class="asr-card-header">
            <h2><?php esc_html_e( 'Configured Slots', 'adshimmer' ); ?></h2>
            <div class="asr-slot-actions">
                <button type="button" class="asr-btn asr-btn-secondary" id="asr-export-slots">
                    <?php esc_html_e( 'Export', 'adshimmer' ); ?>
                </button>
                <button type="button" class="asr-btn asr-btn-secondary" id="asr-import-slots-trigger">
                    <?php esc_html_e( 'Import', 'adshimmer' ); ?>
                </button>
                <button type="button" class="asr-btn asr-btn-primary" id="asr-add-slot">
                    <?php esc_html_e( 'Add Slot', 'adshimmer' ); ?>
                </button>
            </div>
        </div>
        <input type="file" id="asr-import-file-input" accept=".json" style="display:none;">

        <?php if ( $count > 0 ) : ?>
            <table class="asr-slots-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'adshimmer' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'adshimmer' ); ?></th>
                        <th><?php esc_html_e( 'Device', 'adshimmer' ); ?></th>
                        <th><?php esc_html_e( 'Selector', 'adshimmer' ); ?></th>
                        <th><?php esc_html_e( 'Height', 'adshimmer' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'adshimmer' ); ?></th>
                    </tr>
                </thead>
                <tbody id="asr-slots-tbody">
                    <?php foreach ( $slots as $slot ) : ?>
                        <?php $this->render_slot_row( $slot ); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <div class="asr-empty-state">
                <p><?php esc_html_e( 'No slots configured yet.', 'adshimmer' ); ?></p>
                <p class="asr-empty-state-action">
                    <?php esc_html_e( 'Click "Add Slot" to configure your first ad slot.', 'adshimmer' ); ?>
                </p>
            </div>
        <?php endif;
    }

    /**
     * Render a single slot row in the table.
     *
     * @param \AdSpaceReserve\Core\Slot $slot Slot to render.
     * @return void
     */
    private function render_slot_row( \AdSpaceReserve\Core\Slot $slot ): void {
        $type_name    = $this->get_slot_type_name( $slot->get_type() );
        $device       = ucfirst( $slot->get_device() );
        $is_ai_slot   = $this->is_ai_generated_slot( $slot );
        $display_name = $this->get_display_name( $slot );

        ?>
        <tr data-slot-id="<?php echo esc_attr( $slot->get_id() ); ?>">
            <td>
                <?php echo esc_html( $display_name ); ?>
                <?php if ( $is_ai_slot ) : ?>
                    <span class="asr-ai-badge" title="<?php esc_attr_e( 'Created by AI Ad Placement Wizard', 'adshimmer' ); ?>">AI</span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html( $type_name ); ?></td>
            <td><?php echo esc_html( $device ); ?></td>
            <td class="asr-selector-cell" title="<?php echo esc_attr( $slot->get_selector() ); ?>">
                <?php echo esc_html( $slot->get_selector() ); ?>
            </td>
            <td><?php echo esc_html( $slot->get_min_height() ); ?>px</td>
            <td>
                <div class="asr-row-actions">
                    <button type="button" class="asr-btn asr-btn-small asr-btn-edit"
                            data-slot-id="<?php echo esc_attr( $slot->get_id() ); ?>"
                            data-slot='<?php echo esc_attr( wp_json_encode( $slot->to_array() ) ); ?>'>
                        <?php esc_html_e( 'Edit', 'adshimmer' ); ?>
                    </button>
                    <button type="button" class="asr-btn asr-btn-small asr-btn-danger asr-btn-delete"
                            data-slot-id="<?php echo esc_attr( $slot->get_id() ); ?>"
                            data-slot-name="<?php echo esc_attr( $slot->get_name() ); ?>">
                        <?php esc_html_e( 'Delete', 'adshimmer' ); ?>
                    </button>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Get human-readable name for a slot type.
     *
     * @param string $type The slot type key.
     * @return string Human-readable name.
     */
    private function get_slot_type_name( string $type ): string {
        if ( 'custom' === $type ) {
            return __( 'Custom', 'adshimmer' );
        }

        // Check if type has device prefix (e.g., "desktop-leaderboard")
        if ( strpos( $type, 'desktop-' ) === 0 ) {
            $key = substr( $type, 8 );
            $slots = \ASR_Slot_Defaults::get_desktop_slots();
            if ( isset( $slots[ $key ]['name'] ) ) {
                return $slots[ $key ]['name'];
            }
        } elseif ( strpos( $type, 'mobile-' ) === 0 ) {
            $key = substr( $type, 7 );
            $slots = \ASR_Slot_Defaults::get_mobile_slots();
            if ( isset( $slots[ $key ]['name'] ) ) {
                return $slots[ $key ]['name'];
            }
        }

        // Check slot defaults without prefix (legacy support)
        $desktop_slots = \ASR_Slot_Defaults::get_desktop_slots();
        $mobile_slots  = \ASR_Slot_Defaults::get_mobile_slots();

        if ( isset( $desktop_slots[ $type ]['name'] ) ) {
            return $desktop_slots[ $type ]['name'];
        }

        if ( isset( $mobile_slots[ $type ]['name'] ) ) {
            return $mobile_slots[ $type ]['name'];
        }

        return __( 'Custom', 'adshimmer' );
    }

    /**
     * Check if a slot was created by the AI wizard.
     *
     * AI-generated slots have names prefixed with "[AI] ".
     *
     * @param \AdSpaceReserve\Core\Slot $slot Slot to check.
     * @return bool True if AI-generated.
     */
    private function is_ai_generated_slot( \AdSpaceReserve\Core\Slot $slot ): bool {
        return strpos( $slot->get_name(), '[AI] ' ) === 0;
    }

    /**
     * Get display name for a slot, stripping AI prefix if present.
     *
     * @param \AdSpaceReserve\Core\Slot $slot Slot to get name for.
     * @return string Display name without prefix.
     */
    private function get_display_name( \AdSpaceReserve\Core\Slot $slot ): string {
        $name = $slot->get_name();

        // Strip [AI] prefix for cleaner display (badge shows AI status).
        if ( strpos( $name, '[AI] ' ) === 0 ) {
            return substr( $name, 5 );
        }

        return $name;
    }

    /**
     * Render the slot add/edit modal.
     *
     * @return void
     */
    private function render_slot_modal(): void {
        $desktop_slots = \ASR_Slot_Defaults::get_desktop_slots();
        $mobile_slots  = \ASR_Slot_Defaults::get_mobile_slots();
        ?>
        <div class="asr-modal-overlay" id="asr-slot-modal-overlay">
            <div class="asr-modal" data-mode="add">
                <div class="asr-modal-header">
                    <h2 id="asr-modal-title"><?php esc_html_e( 'Add New Slot', 'adshimmer' ); ?></h2>
                    <button type="button" class="asr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'adshimmer' ); ?>">&times;</button>
                </div>

                <div class="asr-modal-body">
                    <form id="asr-slot-form">
                        <input type="hidden" name="slot_id" id="asr-slot-id" value="">

                        <div class="asr-form-row">
                            <label for="asr-slot-name"><?php esc_html_e( 'Slot Name', 'adshimmer' ); ?> <span class="required">*</span></label>
                            <input type="text" id="asr-slot-name" name="name" required>
                            <p class="description"><?php esc_html_e( 'A unique identifier for this ad slot.', 'adshimmer' ); ?></p>
                            <span class="error-message"></span>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-type"><?php esc_html_e( 'Slot Type', 'adshimmer' ); ?></label>
                            <select id="asr-slot-type" name="type">
                                <option value="custom"><?php esc_html_e( 'Custom', 'adshimmer' ); ?></option>
                                <optgroup label="<?php esc_attr_e( 'Desktop Slots', 'adshimmer' ); ?>">
                                    <?php foreach ( $desktop_slots as $key => $slot ) : ?>
                                        <option value="desktop-<?php echo esc_attr( $key ); ?>" data-height="<?php echo esc_attr( $slot['height'] ); ?>" data-device="desktop">
                                            <?php echo esc_html( $slot['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="<?php esc_attr_e( 'Mobile Slots', 'adshimmer' ); ?>">
                                    <?php foreach ( $mobile_slots as $key => $slot ) : ?>
                                        <option value="mobile-<?php echo esc_attr( $key ); ?>" data-height="<?php echo esc_attr( $slot['height'] ); ?>" data-device="mobile">
                                            <?php echo esc_html( $slot['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <p class="description"><?php esc_html_e( 'Select a slot type or "Custom" for manual configuration.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-device"><?php esc_html_e( 'Device', 'adshimmer' ); ?></label>
                            <select id="asr-slot-device" name="device">
                                <option value="desktop"><?php esc_html_e( 'Desktop', 'adshimmer' ); ?></option>
                                <option value="mobile"><?php esc_html_e( 'Mobile', 'adshimmer' ); ?></option>
                                <option value="both"><?php esc_html_e( 'Both', 'adshimmer' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Which devices should this slot appear on.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-selector"><?php esc_html_e( 'Target Selector', 'adshimmer' ); ?> <span class="required">*</span></label>
                            <input type="text" id="asr-slot-selector" name="selector" placeholder=".article-content" required>
                            <p class="description"><?php esc_html_e( 'CSS selector for the target element. Supports nth-of-type for repeating patterns.', 'adshimmer' ); ?></p>
                            <details class="asr-selector-help">
                                <summary><?php esc_html_e( 'Selector Examples & Supported Patterns', 'adshimmer' ); ?></summary>
                                <div class="asr-selector-help-content">
                                    <p><strong><?php esc_html_e( 'Basic Selectors:', 'adshimmer' ); ?></strong></p>
                                    <ul>
                                        <li><code>.article-content</code> - <?php esc_html_e( 'Class selector', 'adshimmer' ); ?></li>
                                        <li><code>#main-content</code> - <?php esc_html_e( 'ID selector', 'adshimmer' ); ?></li>
                                        <li><code>h2</code> - <?php esc_html_e( 'Tag selector', 'adshimmer' ); ?></li>
                                        <li><code>.content > h2</code> - <?php esc_html_e( 'Direct child', 'adshimmer' ); ?></li>
                                        <li><code>h2:not(.title)</code> - <?php esc_html_e( 'Excluding a class', 'adshimmer' ); ?></li>
                                    </ul>
                                    <p><strong><?php esc_html_e( 'Repeating Patterns (Supported):', 'adshimmer' ); ?></strong></p>
                                    <ul>
                                        <li><code>h2:nth-of-type(3)</code> - <?php esc_html_e( '3rd h2 only', 'adshimmer' ); ?></li>
                                        <li><code>h2:nth-of-type(2n)</code> - <?php esc_html_e( 'Every 2nd h2 (2, 4, 6...)', 'adshimmer' ); ?></li>
                                        <li><code>h2:nth-of-type(2n+1)</code> - <?php esc_html_e( 'Odd h2s (1, 3, 5...)', 'adshimmer' ); ?></li>
                                        <li><code>h2:nth-of-type(3n+2)</code> - <?php esc_html_e( 'Every 3rd starting at 2nd (2, 5, 8...)', 'adshimmer' ); ?></li>
                                        <li><code>h2:nth-of-type(odd)</code> - <?php esc_html_e( 'Odd positions', 'adshimmer' ); ?></li>
                                        <li><code>h2:nth-of-type(even)</code> - <?php esc_html_e( 'Even positions', 'adshimmer' ); ?></li>
                                        <li><code>h2:first-of-type</code> - <?php esc_html_e( 'First h2 only', 'adshimmer' ); ?></li>
                                        <li><code>h2:last-of-type</code> - <?php esc_html_e( 'Last h2 only', 'adshimmer' ); ?></li>
                                    </ul>
                                    <p><strong><?php esc_html_e( 'Page Targeting (Supported):', 'adshimmer' ); ?></strong></p>
                                    <ul>
                                        <li><code>body.home .sidebar</code> - <?php esc_html_e( 'Only on home page', 'adshimmer' ); ?></li>
                                        <li><code>body:not(.home) .container</code> - <?php esc_html_e( 'Everywhere except home page', 'adshimmer' ); ?></li>
                                        <li><code>body.single .entry-content h2</code> - <?php esc_html_e( 'Only on single posts', 'adshimmer' ); ?></li>
                                    </ul>
                                    <p><strong><?php esc_html_e( 'Not Supported:', 'adshimmer' ); ?></strong></p>
                                    <ul>
                                        <li><code>:hover</code>, <code>:focus</code> - <?php esc_html_e( 'User interaction states', 'adshimmer' ); ?></li>
                                        <li><code>:has()</code> - <?php esc_html_e( 'Complex selectors', 'adshimmer' ); ?></li>
                                        <li><code>:empty</code> - <?php esc_html_e( 'Content-based selectors', 'adshimmer' ); ?></li>
                                    </ul>
                                    <p class="asr-help-note"><?php esc_html_e( 'Tip: Spaces in formulas are automatically handled. Both "2n+2" and "2n + 2" work.', 'adshimmer' ); ?></p>
                                </div>
                            </details>
                            <span class="error-message"></span>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-target-paths"><?php esc_html_e( 'URL / Path Targeting', 'adshimmer' ); ?></label>
                            <textarea id="asr-slot-target-paths"
                                      name="target_paths"
                                      class="asr-code-textarea"
                                      rows="3"
                                      placeholder="/articles/*&#10;/reviews/specific-post/"></textarea>
                            <p class="description"><?php esc_html_e( 'Optional. Leave empty to run site-wide. Add one path per line; * matches any characters. If the current URL does not match, AdShimmer leaves the page unchanged.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-placement"><?php esc_html_e( 'Placement', 'adshimmer' ); ?></label>
                            <select id="asr-slot-placement" name="placement">
                                <option value="before"><?php esc_html_e( 'Before', 'adshimmer' ); ?></option>
                                <option value="after"><?php esc_html_e( 'After', 'adshimmer' ); ?></option>
                                <option value="inside"><?php esc_html_e( 'Inside', 'adshimmer' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Where to place the ad slot relative to the target element.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-position"><?php esc_html_e( 'Paragraph Position', 'adshimmer' ); ?></label>
                            <input type="number" id="asr-slot-position" name="position" min="0" max="1000" value="0">
                            <p class="description"><?php esc_html_e( 'Optional. 0 uses the selector directly. Enter 3 to place before/after paragraph 3 inside the target selector only; it never falls back outside that selector.', 'adshimmer' ); ?></p>
                            <span class="error-message"></span>
                        </div>

                        <div class="asr-form-row">
                            <label for="asr-slot-min-height"><?php esc_html_e( 'Minimum Height (px)', 'adshimmer' ); ?> <span class="required">*</span></label>
                            <input type="number" id="asr-slot-min-height" name="min_height" min="0" value="250" data-default="250" required>
                            <p class="description"><?php esc_html_e( 'Reserved height to prevent CLS. Auto-populated for predefined slot types.', 'adshimmer' ); ?></p>
                            <span class="error-message"></span>
                        </div>

                        <div class="asr-form-row asr-form-row-inline">
                            <div class="asr-form-field">
                                <label for="asr-slot-margin-top"><?php esc_html_e( 'Margin Top (px)', 'adshimmer' ); ?></label>
                                <input type="number" id="asr-slot-margin-top" name="margin_top" min="0" value="15">
                            </div>
                            <div class="asr-form-field">
                                <label for="asr-slot-margin-bottom"><?php esc_html_e( 'Margin Bottom (px)', 'adshimmer' ); ?></label>
                                <input type="number" id="asr-slot-margin-bottom" name="margin_bottom" min="0" value="15">
                            </div>
                        </div>

                        <div class="asr-form-section-header">
                            <h3><?php esc_html_e( 'Sticky Position', 'adshimmer' ); ?></h3>
                        </div>

                        <div class="asr-form-row asr-form-row-checkbox">
                            <label for="asr-slot-sticky">
                                <input type="checkbox" id="asr-slot-sticky" name="is_sticky" value="1">
                                <?php esc_html_e( 'Make this slot sticky', 'adshimmer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'When enabled, the slot will become fixed at a scroll position.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row asr-sticky-offset-row">
                            <label for="asr-slot-sticky-offset"><?php esc_html_e( 'Offset from top (px)', 'adshimmer' ); ?></label>
                            <input type="number" id="asr-slot-sticky-offset" name="sticky_offset" min="0" max="500" value="0">
                            <p class="description"><?php esc_html_e( 'Distance from the top of the viewport when sticky (0-500 pixels).', 'adshimmer' ); ?></p>
                        </div>

                        <?php if ( ASR_FEATURE_FALLBACK ) : ?>
                        <div class="asr-form-section-header">
                            <h3><?php esc_html_e( 'Fallback Banner', 'adshimmer' ); ?></h3>
                            <span class="asr-experimental-badge"><?php esc_html_e( 'Experimental', 'adshimmer' ); ?></span>
                        </div>
                        <p class="asr-experimental-notice">
                            <?php esc_html_e( 'Use at your own risk. This feature is useful if you want to display an in-house banner while sharing the same ad slot with programmatic ads. The fallback shows only when the programmatic ad fails to load.', 'adshimmer' ); ?>
                        </p>

                        <div class="asr-form-row asr-form-row-checkbox">
                            <label for="asr-slot-fallback">
                                <input type="checkbox" id="asr-slot-fallback" name="has_fallback" value="1">
                                <?php esc_html_e( 'Enable fallback image', 'adshimmer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Show a fallback image if the ad fails to load within the timeout period.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row asr-fallback-url-row">
                            <label for="asr-slot-fallback-url"><?php esc_html_e( 'Fallback Image URL', 'adshimmer' ); ?></label>
                            <div class="asr-media-input">
                                <input type="url" id="asr-slot-fallback-url" name="fallback_image_url" placeholder="https://...">
                                <button type="button" class="button" id="asr-select-fallback-image"><?php esc_html_e( 'Select Image', 'adshimmer' ); ?></button>
                            </div>
                            <p class="description"><?php esc_html_e( 'URL of the image to display when the ad fails to load.', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row asr-fallback-timeout-row">
                            <label for="asr-slot-fallback-timeout"><?php esc_html_e( 'Timeout (ms)', 'adshimmer' ); ?></label>
                            <input type="number" id="asr-slot-fallback-timeout" name="fallback_timeout" min="1000" max="30000" value="2000">
                            <p class="description"><?php esc_html_e( 'Milliseconds to wait before showing fallback (1000-30000).', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row asr-fallback-link-row">
                            <label for="asr-slot-fallback-link"><?php esc_html_e( 'Fallback Link URL', 'adshimmer' ); ?></label>
                            <input type="url" id="asr-slot-fallback-link" name="fallback_link_url" placeholder="https://...">
                            <p class="description"><?php esc_html_e( 'Optional URL to navigate to when the fallback image is clicked.', 'adshimmer' ); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="asr-form-section-header">
                            <h3><?php esc_html_e( 'Advanced Options', 'adshimmer' ); ?></h3>
                        </div>

                        <div class="asr-form-row asr-form-row-checkbox">
                            <label for="asr-show-advanced">
                                <input type="checkbox" id="asr-show-advanced" name="show_advanced" value="1">
                                <?php esc_html_e( 'Show advanced options', 'adshimmer' ); ?>
                            </label>
                        </div>

                        <div class="asr-form-row asr-selector-mode-row">
                            <label for="asr-slot-selector-mode"><?php esc_html_e( 'Selector Mode', 'adshimmer' ); ?></label>
                            <select id="asr-slot-selector-mode" name="selector_mode">
                                <option value="first"><?php esc_html_e( 'First Match Only', 'adshimmer' ); ?></option>
                                <option value="all"><?php esc_html_e( 'All Matches', 'adshimmer' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Target only the first matching element, or all matching elements (useful for infinite scroll or repeating content).', 'adshimmer' ); ?></p>
                        </div>

                        <div class="asr-form-row asr-custom-css-row">
                            <label for="asr-slot-custom-css"><?php esc_html_e( 'Custom CSS', 'adshimmer' ); ?></label>
                            <textarea id="asr-slot-custom-css"
                                      name="custom_css"
                                      class="asr-code-textarea"
                                      rows="4">background-color: #e0e0e0;</textarea>
                            <p class="description"><?php esc_html_e( 'Additional CSS rules to apply to this slot. Do not include selector - rules will be wrapped in .asr-slot-{id} { ... }', 'adshimmer' ); ?></p>
                        </div>
                    </form>
                </div>

                <div class="asr-modal-footer">
                    <button type="button" class="asr-btn asr-btn-secondary" id="asr-modal-cancel"><?php esc_html_e( 'Cancel', 'adshimmer' ); ?></button>
                    <button type="button" class="asr-btn asr-btn-primary" id="asr-modal-save"><?php esc_html_e( 'Save Slot', 'adshimmer' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the import confirmation modal.
     *
     * @return void
     */
    private function render_import_modal(): void {
        ?>
        <div class="asr-modal-overlay" id="asr-import-modal-overlay">
            <div class="asr-modal">
                <div class="asr-modal-header">
                    <h2 id="asr-import-modal-title"><?php esc_html_e( 'Import Slots', 'adshimmer' ); ?></h2>
                    <button type="button" class="asr-modal-close asr-import-modal-close" aria-label="<?php esc_attr_e( 'Close', 'adshimmer' ); ?>">&times;</button>
                </div>

                <div class="asr-modal-body">
                    <div class="asr-import-preview">
                        <div class="asr-import-meta">
                            <p><strong><?php esc_html_e( 'Source:', 'adshimmer' ); ?></strong> <span id="asr-import-source"></span></p>
                            <p><strong><?php esc_html_e( 'Slots:', 'adshimmer' ); ?></strong> <span id="asr-import-count"></span></p>
                            <p><strong><?php esc_html_e( 'Exported:', 'adshimmer' ); ?></strong> <span id="asr-import-date"></span></p>
                        </div>
                        <div class="asr-import-slot-list" id="asr-import-slot-list"></div>
                    </div>
                </div>

                <div class="asr-modal-footer">
                    <button type="button" class="asr-btn asr-btn-secondary asr-import-modal-close"><?php esc_html_e( 'Cancel', 'adshimmer' ); ?></button>
                    <button type="button" class="asr-btn asr-btn-primary" id="asr-import-confirm"><?php esc_html_e( 'Import Slots', 'adshimmer' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the ads.txt management section.
     *
     * Outputs a card with mode selection, textarea for content,
     * URL input for redirect, and save button.
     *
     * @return void
     */
    private function render_adstxt_section(): void {
        $options      = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $mode         = $options->get( 'adstxt_mode', 'disabled' );
        $content      = $options->get( 'adstxt_content', '' );
        $redirect_url = $options->get( 'adstxt_redirect_url', '' );

        ?>
        <div class="asr-card asr-card-secondary">
            <h2><?php esc_html_e( 'Ads.txt Management', 'adshimmer' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Configure your ads.txt file for ad network verification. Choose to write custom content or redirect to an external ads.txt URL.', 'adshimmer' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Mode', 'adshimmer' ); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="adstxt_mode" value="disabled" <?php checked( $mode, 'disabled' ); ?>>
                                <?php esc_html_e( 'Disabled', 'adshimmer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'No ads.txt file will be served by this plugin.', 'adshimmer' ); ?></p>

                            <br>

                            <label>
                                <input type="radio" name="adstxt_mode" value="content" <?php checked( $mode, 'content' ); ?>>
                                <?php esc_html_e( 'Custom Content', 'adshimmer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Serve custom ads.txt content that you define below.', 'adshimmer' ); ?></p>

                            <br>

                            <label>
                                <input type="radio" name="adstxt_mode" value="redirect" <?php checked( $mode, 'redirect' ); ?>>
                                <?php esc_html_e( 'Redirect to External URL', 'adshimmer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Redirect /ads.txt requests to an external URL (e.g., your ad network\'s ads.txt).', 'adshimmer' ); ?></p>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <div id="asr-adstxt-content-section" class="asr-adstxt-section" style="<?php echo 'content' === $mode ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="asr-adstxt-content"><?php esc_html_e( 'Ads.txt Content', 'adshimmer' ); ?></label>
                        </th>
                        <td>
                            <textarea id="asr-adstxt-content"
                                      name="adstxt_content"
                                      class="asr-code-textarea"
                                      rows="8"
                                      placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"><?php echo esc_textarea( $content ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Enter your ads.txt lines. Each line should follow the format: domain, publisher-id, relationship, [certification-authority-id]', 'adshimmer' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="asr-adstxt-redirect-section" class="asr-adstxt-section" style="<?php echo 'redirect' === $mode ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="asr-adstxt-redirect-url"><?php esc_html_e( 'Redirect URL', 'adshimmer' ); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                   id="asr-adstxt-redirect-url"
                                   name="adstxt_redirect_url"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $redirect_url ); ?>"
                                   placeholder="https://example.com/ads.txt">
                            <p class="description">
                                <?php esc_html_e( 'Enter the full URL to redirect /ads.txt requests to. Must be a valid URL starting with https://', 'adshimmer' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="asr-button-row">
                <button type="button" id="asr-save-adstxt" class="button button-primary">
                    <?php esc_html_e( 'Save Ads.txt Settings', 'adshimmer' ); ?>
                </button>
                <span id="asr-adstxt-status" class="asr-inline-status"></span>
            </p>
        </div>
        <?php
    }

    /**
     * Render the AI Ad Placement settings card.
     *
     * Contains OpenRouter API key input with save/validation and status indicator.
     *
     * @return void
     */
    private function render_ai_settings_card(): void {
        $options       = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $api_key       = $options->get( 'openrouter_api_key', '' );
        $api_key_valid = $options->get( 'openrouter_api_key_valid', false );

        // Get masked key for display (show only last 4 chars).
        $masked_key = '';
        if ( ! empty( $api_key ) ) {
            $decrypted_key = $this->decrypt_api_key( $api_key );
            if ( $decrypted_key && strlen( $decrypted_key ) > 4 ) {
                $masked_key = str_repeat( '*', strlen( $decrypted_key ) - 4 ) . substr( $decrypted_key, -4 );
            }
        }

        // Determine status.
        $status_class = 'status-not-configured';
        $status_text  = __( 'Not configured', 'adshimmer' );

        if ( ! empty( $api_key ) ) {
            if ( $api_key_valid ) {
                $status_class = 'status-valid';
                $status_text  = __( 'Valid', 'adshimmer' );
            } else {
                $status_class = 'status-invalid';
                $status_text  = __( 'Invalid', 'adshimmer' );
            }
        }

        ?>
        <div class="asr-card asr-card-ai">
            <h2>
                <?php esc_html_e( 'AI Ad Placement', 'adshimmer' ); ?>
                <span class="asr-experimental-badge" title="<?php esc_attr_e( 'This feature is experimental and may change', 'adshimmer' ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M7 2V4H8V8L4 14V16H8.19L9 22H11L11.81 16H12.19L13 22H15L15.81 16H20V14L16 8V4H17V2H7ZM10 4H14V8.5L17.33 14H6.67L10 8.5V4Z" fill="currentColor"/>
                    </svg>
                    <?php esc_html_e( 'Experimental', 'adshimmer' ); ?>
                </span>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Configure your OpenRouter API key to enable AI-powered ad placement recommendations. This uses Claude Opus 4.5 for intelligent ad slot suggestions based on IAB standards and Google best practices.', 'adshimmer' ); ?>
            </p>

            <div class="asr-ai-status <?php echo esc_attr( $status_class ); ?>" id="asr-ai-status">
                <span class="asr-ai-status-indicator"></span>
                <span class="asr-ai-status-text"><?php echo esc_html( $status_text ); ?></span>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="asr-openrouter-key"><?php esc_html_e( 'OpenRouter API Key', 'adshimmer' ); ?></label>
                    </th>
                    <td>
                        <div class="asr-api-key-input">
                            <input type="password"
                                   id="asr-openrouter-key"
                                   name="openrouter_api_key"
                                   value=""
                                   class="regular-text"
                                   placeholder="<?php echo ! empty( $masked_key ) ? esc_attr( $masked_key ) : 'sk-or-v1-...'; ?>"
                                   autocomplete="off">
                            <button type="button" id="asr-toggle-key-visibility" class="button" aria-label="<?php esc_attr_e( 'Toggle visibility', 'adshimmer' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: OpenRouter signup URL */
                                esc_html__( 'Enter your OpenRouter API key. Get one at %s', 'adshimmer' ),
                                '<a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>'
                            );
                            ?>
                        </p>
                        <?php if ( ! empty( $masked_key ) ) : ?>
                            <p class="asr-key-configured">
                                <?php
                                printf(
                                    /* translators: %s: Masked API key */
                                    esc_html__( 'Current key: %s', 'adshimmer' ),
                                    '<code>' . esc_html( $masked_key ) . '</code>'
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="asr-button-row">
                <button type="button" id="asr-save-openrouter-key" class="button button-primary">
                    <?php esc_html_e( 'Save API Key', 'adshimmer' ); ?>
                </button>
                <?php if ( ! empty( $api_key ) ) : ?>
                    <button type="button" id="asr-remove-openrouter-key" class="button button-link-delete">
                        <?php esc_html_e( 'Remove API Key', 'adshimmer' ); ?>
                    </button>
                <?php endif; ?>
                <span id="asr-openrouter-status" class="asr-inline-status"></span>
            </p>

            <div class="asr-notice asr-notice-info">
                <p>
                    <strong><?php esc_html_e( 'Recommended Model:', 'adshimmer' ); ?></strong>
                    <?php esc_html_e( 'Claude Opus 4.5 via OpenRouter provides the best ad placement recommendations. Your API key is encrypted before storage.', 'adshimmer' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Sanitize CSS input to prevent CSS injection attacks.
     *
     * Strips dangerous CSS constructs like expression(), @import,
     * javascript: URLs, and browser-specific bindings.
     *
     * @param string $css Raw CSS input.
     * @return string Sanitized CSS.
     */
    private function sanitize_css( string $css ): string {
        // Remove null bytes.
        $css = str_replace( "\0", '', $css );

        // Strip CSS expressions (IE).
        $css = preg_replace( '/expression\s*\(/i', '/* removed */(', $css );

        // Strip @import directives.
        $css = preg_replace( '/@import\b/i', '/* @import removed */', $css );

        // Strip javascript: URLs.
        $css = preg_replace( '/javascript\s*:/i', '/* removed */:', $css );

        // Strip behavior: (IE).
        $css = preg_replace( '/behavior\s*:/i', '/* removed */:', $css );

        // Strip -moz-binding (Firefox).
        $css = preg_replace( '/-moz-binding\s*:/i', '/* removed */:', $css );

        // Strip url() with data: or javascript: schemes.
        $css = preg_replace( '/url\s*\(\s*["\']?\s*(data|javascript)\s*:/i', 'url(/* removed */:', $css );

        return $css;
    }

    /**
     * Sanitize slot data from AJAX requests or imported JSON.
     *
     * @param array $data         Raw slot data.
     * @param bool  $from_request Whether the data came from a slashed request payload.
     * @return array Sanitized slot data.
     */
    private function sanitize_slot_data( array $data, bool $from_request = false ): array {
        $get = static function ( string $key, $default = '' ) use ( $data, $from_request ) {
            $value = $data[ $key ] ?? $default;
            return $from_request ? wp_unslash( $value ) : $value;
        };

        $get_string = static function ( string $key, string $default = '' ) use ( $get ): string {
            $value = $get( $key, $default );
            return is_scalar( $value ) ? (string) $value : $default;
        };

        $get_bool = static function ( string $key, bool $default = false ) use ( $get ): bool {
            $value = $get( $key, $default );
            return is_array( $value ) ? $default : rest_sanitize_boolean( $value );
        };

        return [
            'id'                 => sanitize_key( $get_string( 'id' ) ),
            'name'               => sanitize_text_field( $get_string( 'name' ) ),
            'type'               => sanitize_key( $get_string( 'type' ) ),
            'device'             => sanitize_key( $get_string( 'device' ) ),
            'selector'           => sanitize_text_field( $get_string( 'selector' ) ),
            'placement'          => sanitize_key( $get_string( 'placement' ) ),
            'min_height'         => absint( $get_string( 'min_height', '250' ) ),
            'margin_top'         => absint( $get_string( 'margin_top', '0' ) ),
            'margin_bottom'      => absint( $get_string( 'margin_bottom', '0' ) ),
            'position'           => absint( $get_string( 'position', '0' ) ),
            'target_paths'       => sanitize_textarea_field( $get_string( 'target_paths' ) ),
            'is_sticky'          => $get_bool( 'is_sticky' ),
            'sticky_offset'      => absint( $get_string( 'sticky_offset', '0' ) ),
            'has_fallback'       => $get_bool( 'has_fallback' ),
            'fallback_image_url' => esc_url_raw( $get_string( 'fallback_image_url' ) ),
            'fallback_timeout'   => absint( $get_string( 'fallback_timeout', '2000' ) ),
            'fallback_link_url'  => esc_url_raw( $get_string( 'fallback_link_url' ) ),
            'custom_css'         => $this->sanitize_css( $get_string( 'custom_css' ) ),
            'selector_mode'      => sanitize_key( $get_string( 'selector_mode', 'first' ) ),
        ];
    }

    /**
     * Encrypt an API key for storage.
     *
     * Uses AES-256-CBC with HMAC-derived key from LOGGED_IN_KEY.
     *
     * @param string $api_key The plaintext API key.
     * @return string|\WP_Error Encrypted key (base64 encoded) or WP_Error on failure.
     */
    private function encrypt_api_key( string $api_key ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new \WP_Error( 'no_openssl', __( 'OpenSSL extension is required for API key encryption.', 'adshimmer' ) );
        }

        if ( ! defined( 'LOGGED_IN_KEY' ) || '' === LOGGED_IN_KEY ) {
            return new \WP_Error( 'no_key', __( 'LOGGED_IN_KEY must be defined in wp-config.php for API key encryption.', 'adshimmer' ) );
        }

        $derived_key = hash_hmac( 'sha256', 'asr_api_key_encryption', LOGGED_IN_KEY, true );
        $iv          = openssl_random_pseudo_bytes( 16 );
        $encrypted   = openssl_encrypt( $api_key, 'AES-256-CBC', $derived_key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            return new \WP_Error( 'encrypt_failed', __( 'Failed to encrypt API key.', 'adshimmer' ) );
        }

        // Prefix with 'v2:' to identify new format.
        return 'v2:' . base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt an API key from storage.
     *
     * Supports both v2 (HMAC-derived key) and legacy (direct key) formats.
     * Auto-migrates legacy keys to v2 format on successful decryption.
     *
     * @param string $encrypted_key The encrypted key (base64 encoded).
     * @return string|false Decrypted API key or false on failure.
     */
    private function decrypt_api_key( string $encrypted_key ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }

        if ( ! defined( 'LOGGED_IN_KEY' ) || '' === LOGGED_IN_KEY ) {
            return false;
        }

        // Try v2 format first.
        if ( 0 === strpos( $encrypted_key, 'v2:' ) ) {
            $data = base64_decode( substr( $encrypted_key, 3 ) );

            if ( false === $data || strlen( $data ) < 17 ) {
                return false;
            }

            $derived_key = hash_hmac( 'sha256', 'asr_api_key_encryption', LOGGED_IN_KEY, true );
            $iv          = substr( $data, 0, 16 );
            $encrypted   = substr( $data, 16 );

            return openssl_decrypt( $encrypted, 'AES-256-CBC', $derived_key, OPENSSL_RAW_DATA, $iv );
        }

        // Legacy format fallback - decrypt with old method then auto-migrate.
        $data = base64_decode( $encrypted_key );

        if ( false === $data || strlen( $data ) < 17 ) {
            return false;
        }

        $iv        = substr( $data, 0, 16 );
        $encrypted = substr( $data, 16 );
        $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', LOGGED_IN_KEY, 0, $iv );

        // Auto-migrate to v2 format on successful legacy decryption.
        if ( false !== $decrypted ) {
            $new_encrypted = $this->encrypt_api_key( $decrypted );
            if ( ! is_wp_error( $new_encrypted ) ) {
                $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
                $options->set( 'openrouter_api_key', $new_encrypted );
            }
        }

        return $decrypted;
    }

    /**
     * AJAX handler for saving and validating OpenRouter API key.
     *
     * Validates format, tests against OpenRouter API, encrypts and stores.
     *
     * @return void
     */
    public function ajax_save_openrouter_key(): void {
        check_ajax_referer( 'asr_save_openrouter_key', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        // Allow clearing the key.
        if ( empty( $api_key ) ) {
            $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
            $options->set( 'openrouter_api_key', '' );
            $options->set( 'openrouter_api_key_valid', false );

            wp_send_json_success( [
                'message' => __( 'API key cleared.', 'adshimmer' ),
                'status'  => 'not_configured',
            ] );
            return;
        }

        // Validate key format (should start with 'sk-or-').
        if ( strpos( $api_key, 'sk-or-' ) !== 0 ) {
            wp_send_json_error( [
                'message' => __( 'Invalid API key format. OpenRouter keys should start with "sk-or-".', 'adshimmer' ),
            ] );
            return;
        }

        // Test the key against OpenRouter API.
        $validation_result = $this->validate_openrouter_key( $api_key );

        if ( ! $validation_result['valid'] ) {
            wp_send_json_error( [
                'message' => $validation_result['message'],
            ] );
            return;
        }

        // Encrypt and store the key.
        $encrypted_key = $this->encrypt_api_key( $api_key );

        if ( is_wp_error( $encrypted_key ) ) {
            wp_send_json_error( [ 'message' => $encrypted_key->get_error_message() ] );
            return;
        }

        $options = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $options->set( 'openrouter_api_key', $encrypted_key );
        $options->set( 'openrouter_api_key_valid', true );

        // Generate masked key for response.
        $masked_key = str_repeat( '*', strlen( $api_key ) - 4 ) . substr( $api_key, -4 );

        wp_send_json_success( [
            'message'    => __( 'API key saved and validated successfully.', 'adshimmer' ),
            'status'     => 'valid',
            'masked_key' => $masked_key,
        ] );
    }

    /**
     * Validate an OpenRouter API key by making a test request.
     *
     * @param string $api_key The API key to validate.
     * @return array{valid: bool, message: string} Validation result.
     */
    private function validate_openrouter_key( string $api_key ): array {
        $response = wp_remote_get( 'https://openrouter.ai/api/v1/auth/key', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __( 'Network error: %s', 'adshimmer' ),
                    $response->get_error_message()
                ),
            ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $status_code && isset( $body['data'] ) ) {
            return [
                'valid'   => true,
                'message' => __( 'API key is valid.', 'adshimmer' ),
            ];
        }

        if ( 401 === $status_code || 403 === $status_code ) {
            return [
                'valid'   => false,
                'message' => __( 'Invalid API key. Please check your key and try again.', 'adshimmer' ),
            ];
        }

        return [
            'valid'   => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                __( 'Unexpected response from OpenRouter (status: %d). Please try again.', 'adshimmer' ),
                $status_code
            ),
        ];
    }

    /**
     * Render the floating AI chat trigger button.
     *
     * Outputs a fixed-position button that opens the AI chat modal.
     * Only shown when OpenRouter API key is configured and valid.
     *
     * @return void
     */
    private function render_ai_chat_trigger(): void {
        $options       = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $api_key       = $options->get( 'openrouter_api_key', '' );
        $api_key_valid = $options->get( 'openrouter_api_key_valid', false );

        // Only show trigger if API key is configured and valid.
        if ( empty( $api_key ) || ! $api_key_valid ) {
            return;
        }

        ?>
        <button type="button" id="asr-ai-chat-trigger" class="asr-ai-chat-trigger" aria-label="<?php esc_attr_e( 'Open AI Assistant', 'adshimmer' ); ?>">
            <span class="asr-ai-chat-trigger-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 20C7.59 20 4 16.41 4 12C4 7.59 7.59 4 12 4C16.41 4 20 7.59 20 12C20 16.41 16.41 20 12 20Z" fill="currentColor"/>
                    <path d="M12 6C9.79 6 8 7.79 8 10H10C10 8.9 10.9 8 12 8C13.1 8 14 8.9 14 10C14 11.1 13.1 12 12 12C11.45 12 11 12.45 11 13V15H13V13.83C14.72 13.42 16 11.86 16 10C16 7.79 14.21 6 12 6Z" fill="currentColor"/>
                    <circle cx="12" cy="18" r="1" fill="currentColor"/>
                </svg>
            </span>
            <span class="asr-ai-chat-trigger-label"><?php esc_html_e( 'AI Assistant', 'adshimmer' ); ?></span>
        </button>

        <?php $this->render_ai_chat_modal(); ?>
        <?php
    }

    /**
     * Render the AI chat modal container.
     *
     * Outputs the modal structure with header, message area, and input field.
     *
     * @return void
     */
    private function render_ai_chat_modal(): void {
        ?>
        <div id="asr-ai-chat-modal" class="asr-ai-chat-modal" role="dialog" aria-modal="true" aria-labelledby="asr-ai-chat-title">
            <div class="asr-ai-chat-modal-container">
                <!-- Modal Header -->
                <div class="asr-ai-chat-header">
                    <div class="asr-ai-chat-header-left">
                        <span class="asr-ai-chat-avatar">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 20C7.59 20 4 16.41 4 12C4 7.59 7.59 4 12 4C16.41 4 20 7.59 20 12C20 16.41 16.41 20 12 20Z" fill="currentColor"/>
                                <path d="M12 6C9.79 6 8 7.79 8 10H10C10 8.9 10.9 8 12 8C13.1 8 14 8.9 14 10C14 11.1 13.1 12 12 12C11.45 12 11 12.45 11 13V15H13V13.83C14.72 13.42 16 11.86 16 10C16 7.79 14.21 6 12 6Z" fill="currentColor"/>
                                <circle cx="12" cy="18" r="1" fill="currentColor"/>
                            </svg>
                        </span>
                        <h3 id="asr-ai-chat-title" class="asr-ai-chat-title"><?php esc_html_e( 'AI Ad Placement Assistant', 'adshimmer' ); ?></h3>
                    </div>
                    <div class="asr-ai-chat-header-actions">
                        <button type="button" id="asr-ai-chat-history-btn" class="asr-ai-chat-header-btn" aria-label="<?php esc_attr_e( 'Conversation history', 'adshimmer' ); ?>" title="<?php esc_attr_e( 'History', 'adshimmer' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13 3C8.03 3 4 7.03 4 12H1L4.89 15.89L4.96 16.03L9 12H6C6 8.13 9.13 5 13 5C16.87 5 20 8.13 20 12C20 15.87 16.87 19 13 19C11.07 19 9.32 18.21 8.06 16.94L6.64 18.36C8.27 19.99 10.51 21 13 21C17.97 21 22 16.97 22 12C22 7.03 17.97 3 13 3ZM12 8V13L16.28 15.54L17 14.33L13.5 12.25V8H12Z" fill="currentColor"/>
                            </svg>
                        </button>
                        <button type="button" id="asr-ai-chat-close" class="asr-ai-chat-header-btn asr-ai-chat-close" aria-label="<?php esc_attr_e( 'Close chat', 'adshimmer' ); ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 6.41L17.59 5L12 10.59L6.41 5L5 6.41L10.59 12L5 17.59L6.41 19L12 13.41L17.59 19L19 17.59L13.41 12L19 6.41Z" fill="currentColor"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- History Sidebar -->
                <div id="asr-ai-chat-history" class="asr-ai-chat-history">
                    <div class="asr-ai-chat-history-header">
                        <h4><?php esc_html_e( 'Conversations', 'adshimmer' ); ?></h4>
                        <button type="button" id="asr-ai-chat-history-close" class="asr-ai-chat-history-close" aria-label="<?php esc_attr_e( 'Close history', 'adshimmer' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 6.41L17.59 5L12 10.59L6.41 5L5 6.41L10.59 12L5 17.59L6.41 19L12 13.41L17.59 19L19 17.59L13.41 12L19 6.41Z" fill="currentColor"/>
                            </svg>
                        </button>
                    </div>
                    <button type="button" id="asr-ai-chat-new-conversation" class="asr-ai-chat-new-conversation">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M19 13H13V19H11V13H5V11H11V5H13V11H19V13Z" fill="currentColor"/>
                        </svg>
                        <?php esc_html_e( 'New Conversation', 'adshimmer' ); ?>
                    </button>
                    <div id="asr-ai-chat-history-list" class="asr-ai-chat-history-list">
                        <!-- Conversation items will be rendered here -->
                    </div>
                </div>

                <!-- Messages Area -->
                <div id="asr-ai-chat-messages" class="asr-ai-chat-messages">
                    <!-- Welcome message -->
                    <div class="asr-ai-chat-message asr-ai-chat-message-assistant">
                        <div class="asr-ai-chat-message-content">
                            <p><?php esc_html_e( 'Hello! I\'m your AI Ad Placement Assistant. I can help you find the best locations for your ads based on IAB standards and Google best practices.', 'adshimmer' ); ?></p>
                            <p><?php esc_html_e( 'Tell me about your website and I\'ll suggest optimal ad placements. For example:', 'adshimmer' ); ?></p>
                            <ul>
                                <li><?php esc_html_e( '"I have a news blog and want to add display ads"', 'adshimmer' ); ?></li>
                                <li><?php esc_html_e( '"Help me set up ads for my recipe website"', 'adshimmer' ); ?></li>
                                <li><?php esc_html_e( '"What ad sizes work best for mobile?"', 'adshimmer' ); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Quick Replies Area -->
                <div id="asr-ai-chat-quick-replies" class="asr-ai-chat-quick-replies"></div>

                <!-- Input Area -->
                <div class="asr-ai-chat-input-area">
                    <form id="asr-ai-chat-form" class="asr-ai-chat-form">
                        <textarea
                            id="asr-ai-chat-input"
                            class="asr-ai-chat-input"
                            placeholder="<?php esc_attr_e( 'Describe your website or ask about ad placements...', 'adshimmer' ); ?>"
                            rows="1"
                            autocomplete="off"
                        ></textarea>
                        <button type="submit" id="asr-ai-chat-send" class="asr-ai-chat-send" aria-label="<?php esc_attr_e( 'Send message', 'adshimmer' ); ?>" disabled>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M2.01 21L23 12L2.01 3L2 10L17 12L2 14L2.01 21Z" fill="currentColor"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting all conversations.
     *
     * Returns list of conversations without messages for sidebar display.
     *
     * @return void
     */
    public function ajax_chat_get_conversations(): void {
        check_ajax_referer( 'asr_chat_get_conversations', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $storage       = \AdSpaceReserve\AI\Chat_Storage::get_instance();
        $conversations = $storage->get_all_conversations();
        $active_id     = $storage->get_active_conversation_id();

        wp_send_json_success( [
            'conversations'          => $conversations,
            'active_conversation_id' => $active_id,
        ] );
    }

    /**
     * AJAX handler for getting a single conversation with messages.
     *
     * @return void
     */
    public function ajax_chat_get_conversation(): void {
        check_ajax_referer( 'asr_chat_get_conversation', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $conv_id = isset( $_POST['conversation_id'] ) ? sanitize_key( $_POST['conversation_id'] ) : '';

        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Conversation ID is required.', 'adshimmer' ) ] );
            return;
        }

        $storage      = \AdSpaceReserve\AI\Chat_Storage::get_instance();
        $conversation = $storage->get_conversation( $conv_id );

        if ( ! $conversation ) {
            wp_send_json_error( [ 'message' => __( 'Conversation not found.', 'adshimmer' ) ] );
            return;
        }

        // Set this as the active conversation.
        $storage->set_active_conversation_id( $conv_id );

        wp_send_json_success( [
            'conversation' => $conversation,
        ] );
    }

    /**
     * AJAX handler for creating a new conversation.
     *
     * @return void
     */
    public function ajax_chat_create_conversation(): void {
        check_ajax_referer( 'asr_chat_create_conversation', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $storage = \AdSpaceReserve\AI\Chat_Storage::get_instance();
        $conv_id = $storage->create_conversation();

        // Get the full conversation to return.
        $conversation = $storage->get_conversation( $conv_id );

        wp_send_json_success( [
            'conversation_id' => $conv_id,
            'conversation'    => $conversation,
        ] );
    }

    /**
     * AJAX handler for adding a message to a conversation.
     *
     * @return void
     */
    public function ajax_chat_add_message(): void {
        check_ajax_referer( 'asr_chat_add_message', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $conv_id = isset( $_POST['conversation_id'] ) ? sanitize_key( $_POST['conversation_id'] ) : '';
        $role    = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : '';
        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Conversation ID is required.', 'adshimmer' ) ] );
            return;
        }

        if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid message role.', 'adshimmer' ) ] );
            return;
        }

        if ( empty( $content ) ) {
            wp_send_json_error( [ 'message' => __( 'Message content is required.', 'adshimmer' ) ] );
            return;
        }

        $storage = \AdSpaceReserve\AI\Chat_Storage::get_instance();
        $success = $storage->add_message( $conv_id, $role, $content );

        if ( ! $success ) {
            wp_send_json_error( [ 'message' => __( 'Conversation not found.', 'adshimmer' ) ] );
            return;
        }

        // Return updated conversation.
        $conversation = $storage->get_conversation( $conv_id );

        wp_send_json_success( [
            'conversation' => $conversation,
        ] );
    }

    /**
     * AJAX handler for deleting a conversation.
     *
     * @return void
     */
    public function ajax_chat_delete_conversation(): void {
        check_ajax_referer( 'asr_chat_delete_conversation', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $conv_id = isset( $_POST['conversation_id'] ) ? sanitize_key( $_POST['conversation_id'] ) : '';

        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Conversation ID is required.', 'adshimmer' ) ] );
            return;
        }

        $storage = \AdSpaceReserve\AI\Chat_Storage::get_instance();
        $deleted = $storage->delete_conversation( $conv_id );

        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => __( 'Conversation not found.', 'adshimmer' ) ] );
            return;
        }

        wp_send_json_success( [
            'message' => __( 'Conversation deleted.', 'adshimmer' ),
        ] );
    }

    /**
     * AJAX handler for sending a chat message and getting AI response.
     *
     * This is the main handler for processing user messages through the
     * Chat_Conductor conversation flow.
     *
     * @return void
     */
    public function ajax_chat_send_message(): void {
        check_ajax_referer( 'asr_chat_send_message', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $conv_id = isset( $_POST['conversation_id'] ) ? sanitize_key( $_POST['conversation_id'] ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Conversation ID is required.', 'adshimmer' ) ] );
            return;
        }

        if ( empty( $message ) ) {
            wp_send_json_error( [ 'message' => __( 'Message is required.', 'adshimmer' ) ] );
            return;
        }

        $storage = \AdSpaceReserve\AI\Chat_Storage::get_instance();

        // Verify conversation exists.
        $conversation = $storage->get_conversation( $conv_id );
        if ( ! $conversation ) {
            wp_send_json_error( [ 'message' => __( 'Conversation not found.', 'adshimmer' ) ] );
            return;
        }

        // Store user message.
        $storage->add_message( $conv_id, 'user', $message );

        // Process message through Chat_Conductor.
        $conductor = new \AdSpaceReserve\AI\Chat_Conductor();
        $result    = $conductor->process_message( $conv_id, $message );

        // Check for errors.
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['response'] ] );
            return;
        }

        // Store AI response.
        $storage->add_message( $conv_id, 'assistant', $result['response'] );

        wp_send_json_success( [
            'response'        => $result['response'],
            'quick_replies'   => $result['quick_replies'] ?? null,
            'stage'           => $result['stage'],
            'recommendations' => $result['recommendations'] ?? null,
        ] );
    }

    /**
     * AJAX handler for getting the greeting message for a new conversation.
     *
     * @return void
     */
    public function ajax_chat_get_greeting(): void {
        check_ajax_referer( 'asr_chat_get_greeting', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'adshimmer' ) ] );
            return;
        }

        $conv_id = isset( $_POST['conversation_id'] ) ? sanitize_key( $_POST['conversation_id'] ) : '';

        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Conversation ID is required.', 'adshimmer' ) ] );
            return;
        }

        $storage = \AdSpaceReserve\AI\Chat_Storage::get_instance();

        // Verify conversation exists.
        $conversation = $storage->get_conversation( $conv_id );
        if ( ! $conversation ) {
            wp_send_json_error( [ 'message' => __( 'Conversation not found.', 'adshimmer' ) ] );
            return;
        }

        // Initialize wizard_data with greeting stage.
        $storage->update_wizard_data( $conv_id, [ 'stage' => 'greeting' ] );

        // Get greeting from conductor.
        $conductor = new \AdSpaceReserve\AI\Chat_Conductor();
        $greeting  = $conductor->get_greeting_message();

        // Store greeting message.
        $storage->add_message( $conv_id, 'assistant', $greeting['response'] );

        wp_send_json_success( [
            'response'        => $greeting['response'],
            'quick_replies'   => $greeting['quick_replies'],
            'stage'           => $greeting['stage'],
        ] );
    }
}
