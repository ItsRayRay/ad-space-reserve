<?php
/**
 * Options class for centralized settings management.
 *
 * @package AdSpaceReserve\Core
 */

namespace AdSpaceReserve\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized options handler for the AdShimmer plugin.
 *
 * Provides typed access to plugin settings with defaults merging.
 */
class Options {

    /**
     * WordPress option key for plugin settings.
     */
    private const OPTION_KEY = 'asr_settings';

    /**
     * Default option values.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [
        'configured_slots'        => [],
        'max_infinite_slots'      => 10,
        'cleanup_on_deactivate'   => true,
        'header_code'             => '',
        'header_code_blocks'      => [],
        'show_attribution'        => false,
        // License fields.
        'license_key'             => '',
        'license_status'          => 'inactive',
        'license_instance_id'     => '',
        'license_activated_at'    => null,
        'license_expires_at'      => null,
        'license_tier'            => '',
        'license_activation_limit' => 0,
        'license_activation_usage' => 0,
        // Ads.txt management fields.
        'adstxt_mode'              => 'disabled',
        'adstxt_content'           => '',
        'adstxt_redirect_url'      => '',
        // OpenRouter API key fields.
        'openrouter_api_key'       => '',
        'openrouter_api_key_valid' => false,
    ];

    /**
     * Get a single option value.
     *
     * Falls back to default if option doesn't exist.
     *
     * @param string $key     Option key to retrieve.
     * @param mixed  $default Optional. Override default value.
     * @return mixed Option value or default.
     */
    public function get( string $key, $default = null ) {
        $options = $this->get_all();

        if ( array_key_exists( $key, $options ) ) {
            return $options[ $key ];
        }

        return $default ?? ( $this->defaults[ $key ] ?? null );
    }

    /**
     * Set a single option value.
     *
     * Preserves existing options when updating.
     *
     * @param string $key   Option key to set.
     * @param mixed  $value Value to set.
     * @return bool True on success, false on failure.
     */
    public function set( string $key, $value ): bool {
        $options = $this->get_all();
        $options[ $key ] = $value;

        return update_option( self::OPTION_KEY, $options );
    }

    /**
     * Get all options merged with defaults.
     *
     * Ensures new default options are available even if stored options
     * were created before those defaults existed.
     *
     * @return array<string, mixed> All options with defaults applied.
     */
    public function get_all(): array {
        $stored = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return array_merge( $this->defaults, $stored );
    }

    /**
     * Initialize default options on activation.
     *
     * Uses add_option() to avoid overwriting existing settings.
     *
     * @return void
     */
    public function set_defaults(): void {
        add_option( self::OPTION_KEY, $this->defaults );
    }

    /**
     * Delete all plugin options.
     *
     * Used during uninstall to clean up database.
     *
     * @return void
     */
    public function delete_all(): void {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Migrate legacy header_code to header_code_blocks.
     *
     * If 'header_code' has content and 'header_code_blocks' is empty,
     * creates a single block from the legacy content. Does NOT delete
     * legacy 'header_code' to preserve backward compatibility.
     *
     * @return bool True if migration occurred, false otherwise.
     */
    public function migrate_legacy_header_code(): bool {
        $header_code        = $this->get( 'header_code', '' );
        $header_code_blocks = $this->get( 'header_code_blocks', [] );

        // Only migrate if legacy has content AND blocks are empty.
        if ( empty( $header_code ) || ! empty( $header_code_blocks ) ) {
            return false;
        }

        // Create a single block from legacy content.
        $migrated_block = [
            'id'      => 'block-' . time(),
            'label'   => 'Header Code',
            'code'    => $header_code,
            'enabled' => true,
        ];

        $this->set( 'header_code_blocks', [ $migrated_block ] );

        return true;
    }
}
