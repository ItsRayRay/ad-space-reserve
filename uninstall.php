<?php
/**
 * Uninstall script for AdShimmer
 *
 * Removes all plugin data from WordPress database and generated theme files.
 * Called when the plugin is deleted via wp-admin/plugins.php.
 *
 * @package AdSpaceReserve
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete plugin options.
delete_option( 'asr_settings' );

// Delete ALL plugin transients.
delete_transient( 'asr_generated_css' );
delete_transient( 'asr_generated_css_desktop' ); // Legacy.
delete_transient( 'asr_generated_css_mobile' );  // Legacy.
delete_transient( 'asr_generated_php' );
delete_transient( 'asr_generated_js' );
delete_transient( 'asr_deploy_version' );
delete_transient( 'asr_cache' ); // Legacy transient.

// Remove generated files from plugin directory.
$generated_dir = __DIR__ . '/generated';

if ( is_dir( $generated_dir ) && wp_is_writable( $generated_dir ) ) {
    // Initialize WP_Filesystem.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();

    global $wp_filesystem;

    if ( $wp_filesystem ) {
        // Generated file names.
        $files = [
            'asr-containers.php',
            'asr-styles.css',
            'asr-desktop.css',
            'asr-mobile.css',
            'asr-fallback.js',
            'asr-injection.js', // Legacy file cleanup.
        ];

        // Delete each generated file.
        foreach ( $files as $file ) {
            $file_path = $generated_dir . '/' . $file;
            if ( file_exists( $file_path ) ) {
                $wp_filesystem->delete( $file_path );
            }
        }

        // Delete backup files (*.backup.*).
        $backup_files = glob( $generated_dir . '/*.backup.*' );
        if ( $backup_files ) {
            foreach ( $backup_files as $backup_file ) {
                $wp_filesystem->delete( $backup_file );
            }
        }

        // Remove the generated directory if empty.
        $remaining = glob( $generated_dir . '/*' );
        if ( empty( $remaining ) ) {
            $wp_filesystem->rmdir( $generated_dir );
        }
    }
}
