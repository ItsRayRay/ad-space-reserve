<?php
/**
 * Theme Writer class for deploying generated code to the plugin directory.
 *
 * @package AdSpaceReserve\Theme
 */

namespace AdSpaceReserve\Theme;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes generated CSS files to the plugin's generated directory.
 *
 * Handles WP_Filesystem integration and backup creation.
 * CSS files are automatically enqueued by the Plugin class.
 * Slot injection is handled by Frontend_Injector at runtime.
 */
class Theme_Writer {

    /**
     * Generated files subdirectory within the plugin.
     */
    public const GENERATED_DIR = 'generated';

    /**
     * CSS filename for generated styles (legacy/combined).
     */
    public const CSS_FILENAME = 'asr-styles.css';

    /**
     * CSS filename for desktop-specific styles.
     */
    public const CSS_DESKTOP_FILENAME = 'asr-desktop.css';

    /**
     * CSS filename for mobile-specific styles.
     */
    public const CSS_MOBILE_FILENAME = 'asr-mobile.css';

    /**
     * JS filename for fallback detection script.
     */
    public const JS_FALLBACK_FILENAME = 'asr-fallback.js';

    /**
     * Get the plugin's generated files directory.
     *
     * Creates the directory if it doesn't exist.
     *
     * @return string Generated files directory path.
     */
    public function get_generated_directory(): string {
        return ASR_PLUGIN_DIR . self::GENERATED_DIR;
    }

    /**
     * Get the URL to the generated files directory.
     *
     * @return string Generated files directory URL.
     */
    public function get_generated_url(): string {
        return ASR_PLUGIN_URL . self::GENERATED_DIR;
    }

    /**
     * Check if the generated directory is writable.
     *
     * @return bool True if writable, false otherwise.
     */
    public function is_writable(): bool {
        $gen_dir = $this->get_generated_directory();

        // Create directory if it doesn't exist.
        if ( ! file_exists( $gen_dir ) ) {
            wp_mkdir_p( $gen_dir );
        }

        return wp_is_writable( $gen_dir );
    }

    /**
     * Write CSS file to the plugin's generated directory.
     *
     * Creates backups of existing files and writes new content.
     *
     * @param string $css         CSS code to write.
     * @param string $fallback_js Optional fallback JavaScript code.
     * @return array{success: bool, error?: string, files?: string[]} Result array.
     */
    public function write_files( string $css, string $fallback_js = '' ): array {
        $gen_dir = $this->get_generated_directory();

        // Create directory if it doesn't exist.
        if ( ! file_exists( $gen_dir ) ) {
            wp_mkdir_p( $gen_dir );
        }

        // Check directory is writable.
        if ( ! wp_is_writable( $gen_dir ) ) {
            return [
                'success' => false,
                'error'   => sprintf(
                    /* translators: %s: directory path */
                    __( 'Generated directory is not writable: %s', 'adshimmer' ),
                    $gen_dir
                ),
            ];
        }

        // Initialize WP_Filesystem.
        if ( ! $this->init_filesystem() ) {
            return [
                'success' => false,
                'error'   => __( 'Could not initialize WordPress filesystem.', 'adshimmer' ),
            ];
        }

        $files_to_deploy = [
            [
                'final_path' => $gen_dir . '/' . self::CSS_FILENAME,
                'content'    => $css,
                'name'       => self::CSS_FILENAME,
            ],
        ];

        // Add fallback JS file if content provided.
        if ( ! empty( $fallback_js ) ) {
            $files_to_deploy[] = [
                'final_path' => $gen_dir . '/' . self::JS_FALLBACK_FILENAME,
                'content'    => $fallback_js,
                'name'       => self::JS_FALLBACK_FILENAME,
            ];
        }

        $result = $this->atomic_deploy( $files_to_deploy );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false,
                'error'   => $result->get_error_message(),
            ];
        }

        // Set deploy version for cache-busting CSS URLs.
        set_transient( 'asr_deploy_version', time(), 0 );

        return [
            'success' => true,
            'files'   => $result['files'],
        ];
    }

    /**
     * Write device-split CSS files to the plugin's generated directory.
     *
     * Creates backups of existing files and writes new content.
     *
     * @param string $desktop_css Desktop-specific CSS code.
     * @param string $mobile_css  Mobile-specific CSS code.
     * @param string $fallback_js Optional fallback JavaScript code.
     * @return array{success: bool, error?: string, files?: string[]} Result array.
     */
    public function write_device_files( string $desktop_css, string $mobile_css, string $fallback_js = '' ): array {
        $gen_dir = $this->get_generated_directory();

        // Create directory if it doesn't exist.
        if ( ! file_exists( $gen_dir ) ) {
            wp_mkdir_p( $gen_dir );
        }

        // Check directory is writable.
        if ( ! wp_is_writable( $gen_dir ) ) {
            return [
                'success' => false,
                'error'   => sprintf(
                    /* translators: %s: directory path */
                    __( 'Generated directory is not writable: %s', 'adshimmer' ),
                    $gen_dir
                ),
            ];
        }

        // Initialize WP_Filesystem.
        if ( ! $this->init_filesystem() ) {
            return [
                'success' => false,
                'error'   => __( 'Could not initialize WordPress filesystem.', 'adshimmer' ),
            ];
        }

        $files_to_deploy = [
            [
                'final_path' => $gen_dir . '/' . self::CSS_DESKTOP_FILENAME,
                'content'    => $desktop_css,
                'name'       => self::CSS_DESKTOP_FILENAME,
            ],
            [
                'final_path' => $gen_dir . '/' . self::CSS_MOBILE_FILENAME,
                'content'    => $mobile_css,
                'name'       => self::CSS_MOBILE_FILENAME,
            ],
        ];

        // Add fallback JS file if content provided.
        if ( ! empty( $fallback_js ) ) {
            $files_to_deploy[] = [
                'final_path' => $gen_dir . '/' . self::JS_FALLBACK_FILENAME,
                'content'    => $fallback_js,
                'name'       => self::JS_FALLBACK_FILENAME,
            ];
        }

        $result = $this->atomic_deploy( $files_to_deploy );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false,
                'error'   => $result->get_error_message(),
            ];
        }

        // Set deploy version for cache-busting CSS URLs.
        set_transient( 'asr_deploy_version', time(), 0 );

        return [
            'success' => true,
            'files'   => $result['files'],
        ];
    }

    /**
     * Check the status of deployed files.
     *
     * @return array Status array with file existence and paths.
     */
    public function check_files(): array {
        $gen_dir = $this->get_generated_directory();

        $css_path         = $gen_dir . '/' . self::CSS_FILENAME;
        $desktop_css_path = $gen_dir . '/' . self::CSS_DESKTOP_FILENAME;
        $mobile_css_path  = $gen_dir . '/' . self::CSS_MOBILE_FILENAME;
        $js_fallback_path = $gen_dir . '/' . self::JS_FALLBACK_FILENAME;

        return [
            'gen_dir'            => $gen_dir,
            'css_exists'         => file_exists( $css_path ),
            'desktop_css_exists' => file_exists( $desktop_css_path ),
            'mobile_css_exists'  => file_exists( $mobile_css_path ),
            'js_fallback_exists' => file_exists( $js_fallback_path ),
            'css_path'           => $css_path,
            'desktop_css_path'   => $desktop_css_path,
            'mobile_css_path'    => $mobile_css_path,
            'js_fallback_path'   => $js_fallback_path,
        ];
    }

    /**
     * Remove deployed files from the plugin's generated directory.
     *
     * @return array{success: bool, removed: string[]} Result array.
     */
    public function remove_files(): array {
        $gen_dir = $this->get_generated_directory();

        if ( ! file_exists( $gen_dir ) ) {
            return [
                'success' => true,
                'removed' => [],
            ];
        }

        // Initialize WP_Filesystem.
        if ( ! $this->init_filesystem() ) {
            return [
                'success' => false,
                'removed' => [],
            ];
        }

        global $wp_filesystem;

        $removed = [];

        // Remove legacy CSS file.
        $css_path = $gen_dir . '/' . self::CSS_FILENAME;
        if ( file_exists( $css_path ) ) {
            if ( $wp_filesystem->delete( $css_path ) ) {
                $removed[] = self::CSS_FILENAME;
            }
        }

        // Remove desktop CSS file.
        $desktop_css_path = $gen_dir . '/' . self::CSS_DESKTOP_FILENAME;
        if ( file_exists( $desktop_css_path ) ) {
            if ( $wp_filesystem->delete( $desktop_css_path ) ) {
                $removed[] = self::CSS_DESKTOP_FILENAME;
            }
        }

        // Remove mobile CSS file.
        $mobile_css_path = $gen_dir . '/' . self::CSS_MOBILE_FILENAME;
        if ( file_exists( $mobile_css_path ) ) {
            if ( $wp_filesystem->delete( $mobile_css_path ) ) {
                $removed[] = self::CSS_MOBILE_FILENAME;
            }
        }

        // Remove fallback JS file.
        $js_fallback_path = $gen_dir . '/' . self::JS_FALLBACK_FILENAME;
        if ( file_exists( $js_fallback_path ) ) {
            if ( $wp_filesystem->delete( $js_fallback_path ) ) {
                $removed[] = self::JS_FALLBACK_FILENAME;
            }
        }

        return [
            'success' => true,
            'removed' => $removed,
        ];
    }

    /**
     * Initialize the WordPress filesystem.
     *
     * @return bool True if filesystem is ready, false otherwise.
     */
    private function init_filesystem(): bool {
        global $wp_filesystem;

        if ( ! empty( $wp_filesystem ) ) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        return WP_Filesystem();
    }

    /**
     * Write content to a file with backup creation.
     *
     * @param string $path    File path to write.
     * @param string $content Content to write.
     * @return bool True on success, false on failure.
     */
    private function write_file( string $path, string $content ): bool {
        global $wp_filesystem;

        // Create backup if file exists - verify before proceeding.
        if ( file_exists( $path ) ) {
            $backup_path = $path . '.backup.' . gmdate( 'Y-m-d-His' );

            if ( ! $wp_filesystem->copy( $path, $backup_path ) ) {
                return false;
            }

            if ( ! $wp_filesystem->exists( $backup_path ) || ! $wp_filesystem->is_readable( $backup_path ) ) {
                return false;
            }
        }

        return $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
    }

    /**
     * Atomic deployment of multiple files.
     *
     * Writes to temp files first, verifies, then moves to final locations.
     * Rolls back on any failure.
     *
     * @param array $files_to_deploy Array of file specs with final_path, content, and name.
     * @return array|\WP_Error Result array on success, WP_Error on failure.
     */
    private function atomic_deploy( array $files_to_deploy ) {
        global $wp_filesystem;

        $temp_files = [];
        $backups    = [];

        // Step 1: Write all files to temp locations.
        foreach ( $files_to_deploy as $file_spec ) {
            $final_path = $file_spec['final_path'];
            $content    = $file_spec['content'];
            $temp_path  = $final_path . '.tmp.' . wp_generate_password( 12, false );

            if ( ! $wp_filesystem->put_contents( $temp_path, $content, FS_CHMOD_FILE ) ) {
                $this->cleanup_temp_files( $temp_files );
                return new \WP_Error(
                    'temp_write_failed',
                    sprintf(
                        /* translators: %s: file name */
                        __( 'Failed to write temporary file: %s', 'adshimmer' ),
                        $file_spec['name']
                    )
                );
            }

            if ( ! $wp_filesystem->exists( $temp_path ) || ! $wp_filesystem->is_readable( $temp_path ) ) {
                $this->cleanup_temp_files( $temp_files );
                return new \WP_Error(
                    'temp_verify_failed',
                    sprintf(
                        /* translators: %s: file name */
                        __( 'Temporary file verification failed: %s', 'adshimmer' ),
                        $file_spec['name']
                    )
                );
            }

            $temp_files[] = [
                'temp_path'  => $temp_path,
                'final_path' => $final_path,
                'name'       => $file_spec['name'],
            ];
        }

        // Step 2: Create verified backups of existing files.
        foreach ( $temp_files as $file_info ) {
            $final_path = $file_info['final_path'];

            if ( $wp_filesystem->exists( $final_path ) ) {
                $backup_path = $final_path . '.backup.' . gmdate( 'Y-m-d-His' );

                if ( ! $wp_filesystem->copy( $final_path, $backup_path ) ) {
                    $this->cleanup_temp_files( $temp_files );
                    return new \WP_Error(
                        'backup_failed',
                        sprintf(
                            /* translators: %s: file name */
                            __( 'Failed to create backup: %s', 'adshimmer' ),
                            $file_info['name']
                        )
                    );
                }

                if ( ! $wp_filesystem->exists( $backup_path ) || ! $wp_filesystem->is_readable( $backup_path ) ) {
                    $this->cleanup_temp_files( $temp_files );
                    $wp_filesystem->delete( $backup_path );
                    return new \WP_Error(
                        'backup_verify_failed',
                        sprintf(
                            /* translators: %s: file name */
                            __( 'Backup verification failed: %s', 'adshimmer' ),
                            $file_info['name']
                        )
                    );
                }

                $backups[] = [
                    'backup_path' => $backup_path,
                    'final_path'  => $final_path,
                ];
            }
        }

        // Step 3: Move temp files to final locations.
        foreach ( $temp_files as $file_info ) {
            if ( ! $wp_filesystem->move( $file_info['temp_path'], $file_info['final_path'], true ) ) {
                $this->rollback_deployment( $backups, $temp_files );
                return new \WP_Error(
                    'move_failed',
                    sprintf(
                        /* translators: %s: file name */
                        __( 'Failed to deploy file: %s', 'adshimmer' ),
                        $file_info['name']
                    )
                );
            }
        }

        return [
            'files'   => array_column( $temp_files, 'name' ),
            'backups' => $backups,
        ];
    }

    /**
     * Cleanup temporary files on failure.
     *
     * @param array $temp_files Array of temp file info.
     * @return void
     */
    private function cleanup_temp_files( array $temp_files ): void {
        global $wp_filesystem;

        foreach ( $temp_files as $file_info ) {
            if ( isset( $file_info['temp_path'] ) && $wp_filesystem->exists( $file_info['temp_path'] ) ) {
                $wp_filesystem->delete( $file_info['temp_path'] );
            }
        }
    }

    /**
     * Rollback deployment by restoring from backups.
     *
     * @param array $backups    Array of backup info.
     * @param array $temp_files Array of temp file info.
     * @return void
     */
    private function rollback_deployment( array $backups, array $temp_files ): void {
        global $wp_filesystem;

        foreach ( $backups as $backup_info ) {
            $wp_filesystem->move( $backup_info['backup_path'], $backup_info['final_path'], true );
        }

        $this->cleanup_temp_files( $temp_files );
    }
}
