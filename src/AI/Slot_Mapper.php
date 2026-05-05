<?php
/**
 * Slot Mapper class for converting AI recommendations to Slot objects.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

use AdSpaceReserve\Core\Slot;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps AI recommendation data to Slot-compatible array format.
 *
 * Converts AI-generated ad placement recommendations into the format
 * expected by the Slot class for storage and rendering.
 */
class Slot_Mapper {

    /**
     * Map a single AI recommendation to a Slot-compatible array.
     *
     * @param array  $recommendation AI recommendation data.
     * @param string $background_color User's background color preference.
     * @return array Slot data array ready for Slot::from_array().
     */
    public function map( array $recommendation, string $background_color = '' ): array {
        $now = gmdate( 'c' );

        return [
            'id'            => Slot::generate_id( $recommendation['name'] ?? 'AI Slot' ),
            'name'          => '[AI] ' . sanitize_text_field( $recommendation['name'] ?? 'Ad Slot' ),
            'type'          => 'custom',
            'device'        => $this->map_device( $recommendation['device'] ?? 'both' ),
            'selector'      => sanitize_text_field( $recommendation['selector'] ?? '' ),
            'placement'     => $this->map_placement( $recommendation['position'] ?? 'after' ),
            'min_height'    => absint( $recommendation['height'] ?? 250 ),
            'margin_top'    => 10,
            'margin_bottom' => 10,
            'created_at'    => $now,
            'updated_at'    => $now,
            'position'      => 0,
            'is_sticky'     => ! empty( $recommendation['sticky'] ),
            'sticky_offset' => 0,
            'has_fallback'  => false,
            'fallback_image_url' => '',
            'fallback_timeout'   => 2000,
            'fallback_link_url'  => '',
            'custom_css'    => $this->generate_custom_css( $recommendation, $background_color ),
            'selector_mode' => 'first',
        ];
    }

    /**
     * Map device string to valid Slot device value.
     *
     * @param string $device Device from AI recommendation.
     * @return string Valid device value (desktop|mobile|both).
     */
    private function map_device( string $device ): string {
        $device = strtolower( trim( $device ) );

        // Handle various AI response formats.
        if ( in_array( $device, [ 'desktop', 'mobile', 'both' ], true ) ) {
            return $device;
        }

        // Map 'all' to 'both'.
        if ( 'all' === $device ) {
            return 'both';
        }

        // Default to both.
        return 'both';
    }

    /**
     * Map position string to valid Slot placement value.
     *
     * @param string $position Position from AI recommendation.
     * @return string Valid placement value (before|after|inside).
     */
    private function map_placement( string $position ): string {
        $position = strtolower( trim( $position ) );

        // Direct mappings.
        $map = [
            'before'  => 'before',
            'after'   => 'after',
            'inside'  => 'inside',
            'prepend' => 'inside', // Map prepend to inside.
            'append'  => 'inside', // Map append to inside.
        ];

        return $map[ $position ] ?? 'after';
    }

    /**
     * Generate custom CSS based on recommendation and user preferences.
     *
     * @param array  $rec AI recommendation data.
     * @param string $bg_pref User's background color preference.
     * @return string Custom CSS rules.
     */
    private function generate_custom_css( array $rec, string $bg_pref ): string {
        $css = '';

        // Add max-width and centering if width is specified.
        if ( ! empty( $rec['width'] ) ) {
            $width = absint( $rec['width'] );
            if ( $width > 0 && $width < 2000 ) {
                $css .= "max-width: {$width}px;\n";
                $css .= "margin-left: auto;\n";
                $css .= "margin-right: auto;\n";
            }
        }

        // Apply background color preference.
        if ( 'light_gray' === $bg_pref ) {
            $css .= "background-color: #f5f5f5;\n";
        }
        // Note: 'match_theme' and 'none' don't need explicit CSS.

        return trim( $css );
    }
}
