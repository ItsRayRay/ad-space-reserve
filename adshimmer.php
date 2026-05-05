<?php
/**
 * Plugin Name: AdShimmer
 * Plugin URI: https://github.com/ItsRayRay
 * Description: Prevents Cumulative Layout Shift (CLS) caused by dynamically injected ads by reserving space with server-side containers.
 * Version: 1.9.4
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * Author: ItsRayRay
 * Author URI: https://github.com/ItsRayRay
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: adshimmer
 * Domain Path: /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'ASR_VERSION', '1.9.4' );
define( 'ASR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASR_PLUGIN_FILE', __FILE__ );

// Feature configuration (must load before classes check these constants).
require_once __DIR__ . '/config.php';

/**
 * PSR-4-style autoloader for AdSpaceReserve namespace.
 *
 * Maps namespace AdSpaceReserve\Foo\Bar to src/Foo/Bar.php
 */
spl_autoload_register( function ( string $class ): void {
    // Namespace prefix for this plugin.
    $prefix = 'AdSpaceReserve\\';

    // Base directory for namespace classes.
    $base_dir = ASR_PLUGIN_DIR . 'src/';

    // Check if class uses our namespace prefix.
    $prefix_len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $prefix_len ) !== 0 ) {
        // Class doesn't belong to our namespace.
        return;
    }

    // Get relative class name (without namespace prefix).
    $relative_class = substr( $class, $prefix_len );

    // Build file path: replace namespace separators with directory separators.
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    // If file exists, require it.
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Initialize the plugin on plugins_loaded.
 *
 * @return \AdSpaceReserve\Plugin Plugin singleton instance.
 */
function adshimmer_init(): \AdSpaceReserve\Plugin {
    return \AdSpaceReserve\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'adshimmer_init' );
