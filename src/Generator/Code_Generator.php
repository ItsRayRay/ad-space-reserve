<?php
/**
 * Code Generator class for producing CSS code from Slot configurations.
 *
 * @package AdSpaceReserve\Generator
 */

namespace AdSpaceReserve\Generator;

use AdSpaceReserve\Core\Slot;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates CSS code from Slot objects for CLS prevention.
 *
 * Transforms configured slots into deployable CSS that reserves
 * space for ad containers before ads load. Slot injection is
 * handled by Frontend_Injector at runtime.
 */
class Code_Generator {

    /**
     * Desktop breakpoint in pixels.
     */
    private const DESKTOP_BREAKPOINT = 992;

    /**
     * Array of Slot objects to generate code for.
     *
     * @var Slot[]
     */
    private array $slots;

    /**
     * Valid device values.
     */
    private const VALID_DEVICES = [ 'desktop', 'mobile', 'both' ];

    /**
     * Warning threshold for large min_height values.
     */
    private const LARGE_HEIGHT_THRESHOLD = 1000;

    /**
     * Construct the generator with an array of Slot objects.
     *
     * @param Slot[] $slots Array of Slot objects.
     */
    public function __construct( array $slots ) {
        $this->slots = $slots;
    }

    /**
     * Check if any slots have fallback enabled.
     *
     * @return bool True if at least one slot has fallback.
     */
    public function has_fallback_slots(): bool {
        foreach ( $this->slots as $slot ) {
            if ( $slot->get_has_fallback() && ! empty( $slot->get_fallback_image_url() ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate the slots configuration.
     *
     * Returns an array of error messages for invalid configurations.
     * An empty array indicates all slots are valid.
     *
     * @return array Array of error messages.
     */
    public function validate(): array {
        $errors = [];

        // Allow empty slots - this generates base styles only, which is valid
        // and necessary for clearing previously generated slot-specific CSS.
        if ( empty( $this->slots ) ) {
            return $errors; // Valid - generates base styles only.
        }

        foreach ( $this->slots as $index => $slot ) {
            if ( ! ( $slot instanceof Slot ) ) {
                $errors[] = sprintf(
                    /* translators: %d: slot index */
                    __( 'Slot at index %d is not a valid Slot object.', 'adshimmer' ),
                    $index
                );
                continue;
            }

            $slot_name = $slot->get_name() ?: sprintf( 'Slot %d', $index );

            // Check device value.
            $device = $slot->get_device();
            if ( ! in_array( $device, self::VALID_DEVICES, true ) ) {
                $errors[] = sprintf(
                    /* translators: 1: slot name, 2: invalid device value */
                    __( '%1$s: Invalid device value "%2$s". Must be desktop, mobile, or both.', 'adshimmer' ),
                    $slot_name,
                    $device
                );
            }

            // Check selector.
            $selector = $slot->get_selector();
            if ( empty( trim( $selector ) ) ) {
                $errors[] = sprintf(
                    /* translators: %s: slot name */
                    __( '%s: Selector is required.', 'adshimmer' ),
                    $slot_name
                );
            }
        }

        return $errors;
    }

    /**
     * Check if the slots configuration is valid.
     *
     * Convenience wrapper for validate() that returns a boolean.
     *
     * @return bool True if valid, false otherwise.
     */
    public function is_valid(): bool {
        return empty( $this->validate() );
    }

    /**
     * Get warnings for the slots configuration.
     *
     * Returns non-fatal issues that may indicate configuration problems
     * but don't prevent code generation.
     *
     * @return array Array of warning messages.
     */
    public function get_warnings(): array {
        $warnings = [];

        if ( empty( $this->slots ) ) {
            return $warnings;
        }

        // Track selectors for duplicate detection.
        $selectors = [];

        foreach ( $this->slots as $index => $slot ) {
            if ( ! ( $slot instanceof Slot ) ) {
                continue;
            }

            $slot_name = $slot->get_name() ?: sprintf( 'Slot %d', $index );

            // Check for duplicate selectors.
            $selector = $slot->get_selector();
            if ( ! empty( $selector ) ) {
                if ( isset( $selectors[ $selector ] ) ) {
                    $warnings[] = sprintf(
                        /* translators: 1: slot name, 2: CSS selector, 3: previous slot name */
                        __( '%1$s: Duplicate selector "%2$s" (also used by %3$s).', 'adshimmer' ),
                        $slot_name,
                        $selector,
                        $selectors[ $selector ]
                    );
                } else {
                    $selectors[ $selector ] = $slot_name;
                }
            }

            // Check for very large min_height.
            $min_height = $slot->get_min_height();
            if ( $min_height > self::LARGE_HEIGHT_THRESHOLD ) {
                $warnings[] = sprintf(
                    /* translators: 1: slot name, 2: min_height value */
                    __( '%1$s: Large min-height value (%2$dpx) may cause layout issues.', 'adshimmer' ),
                    $slot_name,
                    $min_height
                );
            }

            // Check for zero margins (may be intentional but worth flagging).
            $margin_top    = $slot->get_margin_top();
            $margin_bottom = $slot->get_margin_bottom();
            if ( 0 === $margin_top && 0 === $margin_bottom ) {
                $warnings[] = sprintf(
                    /* translators: %s: slot name */
                    __( '%s: Both margins are zero. Slot may appear cramped.', 'adshimmer' ),
                    $slot_name
                );
            }
        }

        return $warnings;
    }

    /**
     * Generate CSS code split by device type.
     *
     * Returns separate CSS files for each device without media queries,
     * since the file itself provides the device targeting.
     *
     * @return array{desktop: string, mobile: string, combined: string} Device-specific CSS.
     */
    public function generate_css_by_device(): array {
        // Return empty strings if validation fails.
        if ( ! $this->is_valid() ) {
            return [
                'desktop'  => '',
                'mobile'   => '',
                'combined' => '',
            ];
        }

        return [
            'desktop'  => $this->generate_device_css( 'desktop' ),
            'mobile'   => $this->generate_device_css( 'mobile' ),
            'combined' => $this->generate_css(),
        ];
    }

    /**
     * Generate CSS for a specific device type.
     *
     * Produces CSS without media queries since the file itself
     * provides device targeting via conditional loading.
     *
     * @param string $device Device type: 'desktop' or 'mobile'.
     * @return string Generated CSS for the device.
     */
    private function generate_device_css( string $device ): string {
        $timestamp    = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $device_label = ucfirst( $device );

        $css = "/**\n";
        $css .= " * AdShimmer - CLS Prevention Styles ({$device_label})\n";
        $css .= " *\n";
        $css .= " * Auto-generated by AdShimmer plugin.\n";
        $css .= " * Generated: {$timestamp}\n";
        $css .= " *\n";
        $css .= " * DO NOT EDIT DIRECTLY - Changes will be overwritten.\n";
        $css .= " * Configure slots in: Settings > AdShimmer\n";
        $css .= " */\n\n";

        // Base styles for all containers.
        $css .= "/* Base container styles */\n";
        $css .= ".asr-ad-slot {\n";
        $css .= "    display: block;\n";
        $css .= "    width: 100%;\n";
        $css .= "    box-sizing: border-box;\n";
        $css .= "    overflow: hidden;\n";
        $css .= "}\n\n";

        // Sticky slot base styles.
        $css .= "/* Sticky slot base styles */\n";
        $css .= ".asr-sticky {\n";
        $css .= "    position: -webkit-sticky; /* Safari support */\n";
        $css .= "    position: sticky;\n";
        $css .= "}\n\n";

        // Fallback image base styles.
        $css .= "/* Fallback image styles */\n";
        $css .= ".asr-fallback-container {\n";
        $css .= "    position: relative;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-img {\n";
        $css .= "    display: none;\n";
        $css .= "    width: 100%;\n";
        $css .= "    height: 100%;\n";
        $css .= "    object-fit: contain;\n";
        $css .= "    position: absolute;\n";
        $css .= "    top: 0;\n";
        $css .= "    left: 0;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-visible .asr-fallback-img {\n";
        $css .= "    display: block;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-link {\n";
        $css .= "    display: block;\n";
        $css .= "    width: 100%;\n";
        $css .= "    height: 100%;\n";
        $css .= "    position: absolute;\n";
        $css .= "    top: 0;\n";
        $css .= "    left: 0;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-visible > *:not(.asr-fallback-img):not(.asr-fallback-link) {\n";
        $css .= "    visibility: hidden;\n";
        $css .= "}\n\n";

        // Collect slots for this device.
        $device_slots = [];

        foreach ( $this->slots as $slot ) {
            $slot_device = $slot->get_device();

            // Include 'both' slots in both device files.
            if ( 'both' === $slot_device || $slot_device === $device ) {
                $device_slots[] = $slot;
            }
        }

        if ( empty( $device_slots ) ) {
            $css .= "/* No slots configured for {$device} */\n";
            return $css;
        }

        // Generate styles for device slots (no media query needed).
        $css .= "/* {$device_label} slot styles */\n";
        foreach ( $device_slots as $slot ) {
            $css .= $this->generate_slot_css( $slot, false );
        }

        return $css;
    }

    /**
     * Generate CSS code for CLS prevention.
     *
     * Produces device-specific styles using media queries:
     * - Desktop: @media (min-width: 992px)
     * - Mobile: @media (max-width: 991px)
     * - Both: No media query wrapper
     *
     * @return string Generated CSS code, or empty string if invalid/no slots.
     */
    public function generate_css(): string {
        // Return empty string if validation fails.
        if ( ! $this->is_valid() ) {
            return '';
        }

        if ( empty( $this->slots ) ) {
            return "/* AdShimmer: No slots configured */\n";
        }

        $timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

        $css = "/**\n";
        $css .= " * AdShimmer - CLS Prevention Styles\n";
        $css .= " *\n";
        $css .= " * Auto-generated by AdShimmer plugin.\n";
        $css .= " * Generated: {$timestamp}\n";
        $css .= " *\n";
        $css .= " * DO NOT EDIT DIRECTLY - Changes will be overwritten.\n";
        $css .= " * Configure slots in: Settings > AdShimmer\n";
        $css .= " */\n\n";

        // Base styles for all containers.
        $css .= "/* Base container styles */\n";
        $css .= ".asr-ad-slot {\n";
        $css .= "    display: block;\n";
        $css .= "    width: 100%;\n";
        $css .= "    box-sizing: border-box;\n";
        $css .= "    overflow: hidden;\n";
        $css .= "}\n\n";

        // Sticky slot base styles.
        $css .= "/* Sticky slot base styles */\n";
        $css .= ".asr-sticky {\n";
        $css .= "    position: -webkit-sticky; /* Safari support */\n";
        $css .= "    position: sticky;\n";
        $css .= "}\n\n";

        // Fallback image base styles.
        $css .= "/* Fallback image styles */\n";
        $css .= ".asr-fallback-container {\n";
        $css .= "    position: relative;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-img {\n";
        $css .= "    display: none;\n";
        $css .= "    width: 100%;\n";
        $css .= "    height: 100%;\n";
        $css .= "    object-fit: contain;\n";
        $css .= "    position: absolute;\n";
        $css .= "    top: 0;\n";
        $css .= "    left: 0;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-visible .asr-fallback-img {\n";
        $css .= "    display: block;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-link {\n";
        $css .= "    display: block;\n";
        $css .= "    width: 100%;\n";
        $css .= "    height: 100%;\n";
        $css .= "    position: absolute;\n";
        $css .= "    top: 0;\n";
        $css .= "    left: 0;\n";
        $css .= "}\n\n";

        $css .= ".asr-fallback-visible > *:not(.asr-fallback-img):not(.asr-fallback-link) {\n";
        $css .= "    visibility: hidden;\n";
        $css .= "}\n\n";

        // Device visibility rules — hide wrong-device slots via media queries.
        // Works with full-page caching (Cloudflare, WP Super Cache, etc.).
        $mobile_breakpoint = self::DESKTOP_BREAKPOINT - 1;
        $css .= "/* Device visibility — cache-safe via media queries */\n";
        $css .= "@media (min-width: " . self::DESKTOP_BREAKPOINT . "px) {\n";
        $css .= "    .asr-device-mobile { display: none !important; }\n";
        $css .= "}\n";
        $css .= "@media (max-width: {$mobile_breakpoint}px) {\n";
        $css .= "    .asr-device-desktop { display: none !important; }\n";
        $css .= "}\n\n";

        // Group slots by device.
        $desktop_slots = [];
        $mobile_slots  = [];
        $both_slots    = [];

        foreach ( $this->slots as $slot ) {
            $device = $slot->get_device();

            switch ( $device ) {
                case 'desktop':
                    $desktop_slots[] = $slot;
                    break;
                case 'mobile':
                    $mobile_slots[] = $slot;
                    break;
                case 'both':
                    $both_slots[] = $slot;
                    break;
            }
        }

        // Generate styles for 'both' device (no media query).
        if ( ! empty( $both_slots ) ) {
            $css .= "/* Styles for all devices */\n";
            foreach ( $both_slots as $slot ) {
                $css .= $this->generate_slot_css( $slot );
            }
            $css .= "\n";
        }

        // Generate desktop styles.
        if ( ! empty( $desktop_slots ) ) {
            $css .= "/* Desktop styles (min-width: " . self::DESKTOP_BREAKPOINT . "px) */\n";
            $css .= "@media (min-width: " . self::DESKTOP_BREAKPOINT . "px) {\n";

            foreach ( $desktop_slots as $slot ) {
                $css .= $this->generate_slot_css( $slot, true );
            }

            $css .= "}\n\n";
        }

        // Generate mobile styles.
        if ( ! empty( $mobile_slots ) ) {
            $mobile_breakpoint = self::DESKTOP_BREAKPOINT - 1;
            $css .= "/* Mobile styles (max-width: {$mobile_breakpoint}px) */\n";
            $css .= "@media (max-width: {$mobile_breakpoint}px) {\n";

            foreach ( $mobile_slots as $slot ) {
                $css .= $this->generate_slot_css( $slot, true );
            }

            $css .= "}\n";
        }

        return $css;
    }

    /**
     * Generate CSS rules for a single slot.
     *
     * @param Slot $slot   The slot to generate CSS for.
     * @param bool $indent Whether to indent rules (for media query blocks).
     * @return string CSS rules for the slot.
     */
    private function generate_slot_css( Slot $slot, bool $indent = false ): string {
        $id            = $this->sanitize_css_identifier( $slot->get_id() );
        $min_height    = $slot->get_min_height();
        $margin_top    = $slot->get_margin_top();
        $margin_bottom = $slot->get_margin_bottom();
        $is_sticky     = $slot->get_is_sticky();
        $sticky_offset = $slot->get_sticky_offset();

        $prefix = $indent ? '    ' : '';

        $css  = "{$prefix}.asr-slot-{$id} {\n";
        $css .= "{$prefix}    min-height: {$min_height}px;\n";

        if ( $margin_top > 0 ) {
            $css .= "{$prefix}    margin-top: {$margin_top}px;\n";
        }

        if ( $margin_bottom > 0 ) {
            $css .= "{$prefix}    margin-bottom: {$margin_bottom}px;\n";
        }

        // Add sticky positioning if enabled.
        if ( $is_sticky ) {
            $css .= "{$prefix}    position: sticky;\n";
            $css .= "{$prefix}    top: {$sticky_offset}px;\n";
            $css .= "{$prefix}    z-index: 100;\n";
        }

        // Add custom CSS if defined.
        $custom_css = trim( $slot->get_custom_css() );
        if ( ! empty( $custom_css ) ) {
            // Indent each line of custom CSS.
            $lines = explode( "\n", $custom_css );
            foreach ( $lines as $line ) {
                $trimmed = trim( $line );
                if ( ! empty( $trimmed ) ) {
                    $css .= "{$prefix}    {$trimmed}\n";
                }
            }
        }

        $css .= "{$prefix}}\n";

        return $css;
    }

    /**
     * Sanitize a string for use as a CSS class identifier.
     *
     * @param string $id The ID to sanitize.
     * @return string Sanitized CSS-safe identifier.
     */
    private function sanitize_css_identifier( string $id ): string {
        // Replace non-alphanumeric characters with hyphens.
        $sanitized = preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $id );

        // Remove consecutive hyphens.
        $sanitized = preg_replace( '/-+/', '-', $sanitized );

        // Remove leading/trailing hyphens.
        $sanitized = trim( $sanitized, '-' );

        // Ensure it doesn't start with a number.
        if ( preg_match( '/^[0-9]/', $sanitized ) ) {
            $sanitized = 'slot-' . $sanitized;
        }

        return $sanitized ?: 'slot';
    }
}
