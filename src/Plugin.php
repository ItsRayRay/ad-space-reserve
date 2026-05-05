<?php
/**
 * Main Plugin class.
 *
 * @package AdSpaceReserve
 */

namespace AdSpaceReserve;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin orchestrator class.
 *
 * Singleton that manages plugin lifecycle and coordinates all components.
 */
class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Options handler instance.
     *
     * @var Core\Options
     */
    private Core\Options $options;

    /**
     * Settings page instance (admin only).
     *
     * @var Admin\Settings_Page|null
     */
    private ?Admin\Settings_Page $settings_page = null;

    /**
     * Slot collection instance.
     *
     * @var Core\Slot_Collection|null
     */
    private ?Core\Slot_Collection $slots = null;

    /**
     * Theme writer instance.
     *
     * @var Theme\Theme_Writer|null
     */
    private ?Theme\Theme_Writer $theme_writer = null;

    /**
     * Slot expander instance.
     *
     * @var Generator\Slot_Expander|null
     */
    private ?Generator\Slot_Expander $slot_expander = null;

    /**
     * Frontend injector instance.
     *
     * @var Frontend\Frontend_Injector|null
     */
    private ?Frontend\Frontend_Injector $frontend_injector = null;

    /**
     * Get the singleton instance.
     *
     * @return Plugin Plugin instance.
     */
    public static function get_instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        $this->options = new Core\Options();

        // Register lifecycle hooks.
        register_activation_hook( ASR_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( ASR_PLUGIN_FILE, [ $this, 'deactivate' ] );

        $this->init_hooks();
    }

    /**
     * Plugin activation handler.
     *
     * Creates default options and flushes rewrite rules.
     *
     * @return void
     */
    public function activate(): void {
        $this->options->set_defaults();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation handler.
     *
     * Clears generation transients and optionally removes generated files.
     * User settings (slots, options) are preserved for potential reactivation.
     *
     * @return void
     */
    public function deactivate(): void {
        // Always clear generation transients.
        delete_transient( 'asr_generated_css' );

        // Optionally remove generated files.
        if ( $this->options->get( 'cleanup_on_deactivate', false ) ) {
            $this->get_theme_writer()->remove_files();
        }

        flush_rewrite_rules();
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Load admin classes conditionally.
        if ( is_admin() ) {
            $this->settings_page = Admin\Settings_Page::get_instance();
        }

        // Frontend: Server-side slot injection via output buffering.
        if ( ! is_admin() ) {
            $this->get_frontend_injector()->register_hooks();
        }

        // Frontend: Output custom header code.
        add_action( 'wp_head', [ $this, 'output_header_code' ], 1 );

        // Frontend: Output attribution link in footer.
        add_action( 'wp_footer', [ $this, 'output_attribution' ], 100 );

        // Frontend: Enqueue generated CSS and JS from plugin directory.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_generated_assets' ], 5 );

        // Ads.txt: Register rewrite rules and query vars.
        add_action( 'init', [ $this, 'register_adstxt_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'add_adstxt_query_var' ] );

        // Ads.txt: Serve ads.txt content or redirect.
        add_action( 'template_redirect', [ $this, 'serve_adstxt' ] );
    }

    /**
     * Output custom header code in wp_head.
     *
     * Outputs user-defined code (scripts, meta tags, etc.) early in the head.
     * Supports multiple code blocks with enable/disable toggles.
     * Only admins can set this value, so output is trusted.
     *
     * @return void
     */
    public function output_header_code(): void {
        // Ensure migration happens (in case frontend loads before admin).
        $this->options->migrate_legacy_header_code();

        $blocks = $this->options->get( 'header_code_blocks', [] );

        // If no blocks, try legacy header_code for backward compatibility.
        if ( empty( $blocks ) ) {
            $legacy_code = $this->options->get( 'header_code', '' );
            if ( ! empty( $legacy_code ) ) {
                echo "\n<!-- AdShimmer Header Code -->\n";
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted admin input
                echo $legacy_code;
                echo "\n<!-- End AdShimmer Header Code -->\n";
            }
            return;
        }

        // Output each enabled block.
        $has_output = false;
        foreach ( $blocks as $block ) {
            // Skip disabled blocks.
            if ( empty( $block['enabled'] ) ) {
                continue;
            }

            // Skip empty code.
            if ( empty( $block['code'] ) ) {
                continue;
            }

            if ( ! $has_output ) {
                echo "\n<!-- AdShimmer Header Code -->\n";
                $has_output = true;
            }

            $label = ! empty( $block['label'] ) ? esc_html( $block['label'] ) : 'Unnamed Block';
            echo "<!-- Block: {$label} -->\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted admin input
            echo $block['code'];
            echo "\n";
        }

        if ( $has_output ) {
            echo "<!-- End AdShimmer Header Code -->\n";
        }
    }

    /**
     * Output attribution link in wp_footer.
     *
     * Displays a small, unobtrusive attribution link when enabled.
     * Helps support plugin development.
     *
     * @return void
     */
    public function output_attribution(): void {
        if ( ! $this->options->get( 'show_attribution', true ) ) {
            return;
        }

        // Only show on frontend, not admin.
        if ( is_admin() ) {
            return;
        }

        echo '<p style="text-align:center;font-size:11px;color:#999;margin:10px 0;">';
        echo 'Ad slots by <a href="https://adshimmer.com/?utm_source=plugin&utm_medium=footer" target="_blank" rel="noopener nofollow sponsored" style="color:#666;">AdShimmer</a>';
        echo '</p>';
    }

    /**
     * Get the Options instance.
     *
     * @return Core\Options Options handler.
     */
    public function get_options(): Core\Options {
        return $this->options;
    }

    /**
     * Get the Settings Page instance.
     *
     * @return Admin\Settings_Page|null Settings page instance, or null if not in admin context.
     */
    public function get_settings_page(): ?Admin\Settings_Page {
        return $this->settings_page;
    }

    /**
     * Get the Slot Collection instance.
     *
     * Lazy-loaded on first access.
     *
     * @return Core\Slot_Collection Slot collection instance.
     */
    public function get_slots(): Core\Slot_Collection {
        if ( null === $this->slots ) {
            $this->slots = new Core\Slot_Collection( $this->get_options() );
        }

        return $this->slots;
    }

    /**
     * Get the Theme Writer instance.
     *
     * Lazy-loaded on first access.
     *
     * @return Theme\Theme_Writer Theme writer instance.
     */
    public function get_theme_writer(): Theme\Theme_Writer {
        if ( null === $this->theme_writer ) {
            $this->theme_writer = new Theme\Theme_Writer();
        }

        return $this->theme_writer;
    }

    /**
     * Get the Slot Expander instance.
     *
     * Lazy-loaded on first access.
     *
     * @return Generator\Slot_Expander Slot expander instance.
     */
    public function get_slot_expander(): Generator\Slot_Expander {
        if ( null === $this->slot_expander ) {
            $this->slot_expander = new Generator\Slot_Expander( $this->get_options() );
        }

        return $this->slot_expander;
    }

    /**
     * Get the Frontend Injector instance.
     *
     * Lazy-loaded on first access.
     *
     * @return Frontend\Frontend_Injector Frontend injector instance.
     */
    public function get_frontend_injector(): Frontend\Frontend_Injector {
        if ( null === $this->frontend_injector ) {
            $this->frontend_injector = new Frontend\Frontend_Injector( $this->get_slots() );
        }

        return $this->frontend_injector;
    }

    /**
     * Enqueue generated CSS and JS assets from the plugin directory.
     *
     * Enqueues combined CSS with media queries for device visibility.
     * Uses deploy version transient for cache-busting to ensure
     * freshly deployed styles are loaded immediately.
     * Also enqueues fallback JS if it exists.
     *
     * @return void
     */
    public function enqueue_generated_assets(): void {
        $theme_writer = $this->get_theme_writer();
        $gen_dir      = $theme_writer->get_generated_directory();
        $gen_url      = $theme_writer->get_generated_url();

        // Get deploy version for cache-busting (falls back to filemtime).
        $deploy_version = get_transient( 'asr_deploy_version' );

        // Enqueue combined CSS (media queries handle device visibility).
        // Single file approach is compatible with full-page caching.
        $combined_css = $gen_dir . '/' . Theme\Theme_Writer::CSS_FILENAME;
        if ( file_exists( $combined_css ) ) {
            $version = $deploy_version ? $deploy_version : filemtime( $combined_css );
            wp_enqueue_style(
                'asr-styles',
                $gen_url . '/' . Theme\Theme_Writer::CSS_FILENAME,
                [],
                $version
            );
        }

        // Enqueue fallback JS if it exists.
        $fallback_js = $gen_dir . '/' . Theme\Theme_Writer::JS_FALLBACK_FILENAME;
        if ( file_exists( $fallback_js ) ) {
            // Use deploy version if available, otherwise filemtime.
            $version = $deploy_version ? $deploy_version : filemtime( $fallback_js );
            wp_enqueue_script(
                'asr-fallback',
                $gen_url . '/' . Theme\Theme_Writer::JS_FALLBACK_FILENAME,
                [],
                $version,
                true // Load in footer.
            );
        }
    }

    /**
     * Register rewrite rule for ads.txt.
     *
     * Adds a rewrite rule to intercept /ads.txt requests before WordPress
     * looks for an actual file.
     *
     * @return void
     */
    public function register_adstxt_rewrite(): void {
        add_rewrite_rule( '^ads\.txt$', 'index.php?asr_adstxt=1', 'top' );
    }

    /**
     * Add ads.txt query var to WordPress.
     *
     * @param array $vars Existing query vars.
     * @return array Modified query vars.
     */
    public function add_adstxt_query_var( array $vars ): array {
        $vars[] = 'asr_adstxt';
        return $vars;
    }

    /**
     * Serve ads.txt content or redirect based on settings.
     *
     * Handles /ads.txt requests:
     * - 'disabled': Let WordPress handle (will 404 if no file exists)
     * - 'content': Output custom ads.txt content with text/plain header
     * - 'redirect': 301 redirect to external URL
     *
     * @return void
     */
    public function serve_adstxt(): void {
        // Check if this is an ads.txt request.
        if ( ! get_query_var( 'asr_adstxt' ) ) {
            return;
        }

        $mode = $this->options->get( 'adstxt_mode', 'disabled' );

        // If disabled, let WordPress handle (404 if no file).
        if ( 'disabled' === $mode ) {
            return;
        }

        // Content mode: Output custom content.
        if ( 'content' === $mode ) {
            $content = $this->options->get( 'adstxt_content', '' );

            // Set proper headers for text file.
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            header( 'Cache-Control: max-age=86400' ); // Cache for 24 hours.

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text file
            echo $content;
            exit;
        }

        // Redirect mode: 301 redirect to external URL.
        if ( 'redirect' === $mode ) {
            $redirect_url = $this->options->get( 'adstxt_redirect_url', '' );

            if ( ! empty( $redirect_url ) ) {
                wp_redirect( $redirect_url, 301 );
                exit;
            }
        }
    }
}
