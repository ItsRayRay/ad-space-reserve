<?php
/**
 * Uninstall script for Ad Space Reserve
 *
 * Removes plugin data from WordPress database.
 * Note: Does NOT remove generated files from child theme.
 *
 * @package Ad_Space_Reserve
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('asr_settings');

// Clean up any transients
delete_transient('asr_cache');

// Note: We intentionally DO NOT remove the generated files from the child theme
// (asr-containers.php and asr-styles.css) as the user may want to keep them
// for CLS prevention even without the plugin active.
//
// To fully remove, users should:
// 1. Delete asr-containers.php from child theme
// 2. Delete asr-styles.css from child theme
// 3. Remove the require_once and wp_enqueue_style calls from functions.php
