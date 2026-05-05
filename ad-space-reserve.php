<?php
/**
 * Compatibility loader for legacy Ad Space Reserve installs.
 *
 * AdShimmer 1.9.x uses adshimmer.php as the primary plugin file. Keep this file
 * so sites that previously had ad-space-reserve.php active do not point at a
 * missing plugin file after updating from the old GitHub repository.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/adshimmer.php';
