<?php
/**
 * AdShimmer Feature Configuration
 *
 * Toggle features on/off for MVP launch.
 * Set to true to enable, false to disable.
 *
 * @package AdSpaceReserve
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI-Powered Ad Placement feature.
 *
 * When enabled, shows OpenRouter API key settings and AI chat assistant.
 * Requires valid OpenRouter API key to function.
 *
 * Default: false (disabled for MVP - not production ready)
 */
if ( ! defined( 'ASR_FEATURE_AI' ) ) {
    define( 'ASR_FEATURE_AI', false );
}

/**
 * License System feature.
 *
 * When enabled, shows license key activation UI and validates with LemonSqueezy.
 * Requires LemonSqueezy store setup to function.
 *
 * Default: false (disabled for MVP - LemonSqueezy integration incomplete)
 */
if ( ! defined( 'ASR_FEATURE_LICENSE' ) ) {
    define( 'ASR_FEATURE_LICENSE', false );
}

/**
 * Fallback Banners feature.
 *
 * When enabled, shows fallback image options per slot for house ads.
 * Fully implemented but can be disabled for simplification.
 *
 * Default: true (enabled - fully implemented)
 */
if ( ! defined( 'ASR_FEATURE_FALLBACK' ) ) {
    define( 'ASR_FEATURE_FALLBACK', true );
}
