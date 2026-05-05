<?php
/**
 * Slot Expander class for generating placeholder slots for infinite ad positions.
 *
 * @package AdSpaceReserve\Generator
 */

namespace AdSpaceReserve\Generator;

use AdSpaceReserve\Core\Slot;
use AdSpaceReserve\Core\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates placeholder slots for infinite/in-content ad positions.
 *
 * Takes existing slots and expands them to fill available positions
 * based on spacing patterns and max slot limits.
 */
class Slot_Expander {

    /**
     * Default position for first infinite slot (after paragraph N).
     */
    public const DEFAULT_BASE_POSITION = 3;

    /**
     * Default spacing between infinite slots (paragraphs).
     */
    public const DEFAULT_SPACING = 5;

    /**
     * Minimum allowed max_infinite_slots value.
     */
    public const MIN_MAX_SLOTS = 1;

    /**
     * Maximum allowed max_infinite_slots value.
     */
    public const MAX_MAX_SLOTS = 50;

    /**
     * Options instance for settings access.
     *
     * @var Options
     */
    private Options $options;

    /**
     * Construct the expander with options.
     *
     * @param Options $options Plugin options instance.
     */
    public function __construct( Options $options ) {
        $this->options = $options;
    }

    /**
     * Expand slots by generating placeholders for infinite positions.
     *
     * Takes an array of Slot objects and returns a new array containing
     * the original slots plus generated placeholder slots for infinite
     * positions that don't yet have defined slots.
     *
     * @param Slot[] $slots Array of Slot objects.
     * @return Slot[] Array of Slot objects (original + generated placeholders).
     */
    public function expand( array $slots ): array {
        try {
            // Get max infinite slots from options, clamped to valid range.
            $max_infinite = $this->options->get( 'max_infinite_slots', 10 );
            $max_infinite = max( self::MIN_MAX_SLOTS, min( self::MAX_MAX_SLOTS, (int) $max_infinite ) );

            // Group slots by device.
            $slots_by_device = [
                'desktop' => [],
                'mobile'  => [],
            ];

            foreach ( $slots as $slot ) {
                if ( ! ( $slot instanceof Slot ) ) {
                    continue;
                }

                $device = $slot->get_device();

                if ( 'desktop' === $device || 'both' === $device ) {
                    $slots_by_device['desktop'][] = $slot;
                }

                if ( 'mobile' === $device || 'both' === $device ) {
                    $slots_by_device['mobile'][] = $slot;
                }
            }

            // Generate placeholders for each device.
            $generated = [];

            foreach ( $slots_by_device as $device => $device_slots ) {
                // Filter for infinite/incontent type slots.
                $infinite_slots = array_filter(
                    $device_slots,
                    function ( Slot $slot ) {
                        return $this->is_infinite_slot( $slot );
                    }
                );

                $infinite_count = count( $infinite_slots );

                // Generate placeholders if we have at least one base slot and haven't reached max.
                // Only expand from existing slots - don't generate placeholders from nothing.
				if ( $infinite_count > 0 && $infinite_count < $max_infinite ) {
					$spacing       = $this->calculate_spacing( $infinite_slots );
					$needed        = $max_infinite - $infinite_count;
					$next_position = $this->get_next_position( $infinite_slots, $spacing );
					$template_slot = array_values( $infinite_slots )[0] ?? null;

					for ( $i = 0; $i < $needed; $i++ ) {
						$index      = $infinite_count + $i + 1;
						$position   = $next_position + ( $i * $spacing['spacing'] );
						$generated[] = $this->create_placeholder_slot( $device, $index, $position, $template_slot );
					}
				}
			}

            // Return original slots + generated placeholders (without modifying original).
            return array_merge( $slots, $generated );
        } catch ( \Exception $e ) {
            // Log error if WP_DEBUG is enabled.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'Slot_Expander::expand() error: ' . $e->getMessage() );
            }

            // Return original slots unchanged on error.
            return $slots;
        }
    }

    /**
     * Calculate spacing pattern from existing infinite slots.
     *
     * @param Slot[] $slots Array of infinite-type Slot objects.
     * @return array{base: int, spacing: int} Spacing configuration.
     */
    private function calculate_spacing( array $slots ): array {
        // Reindex to ensure sequential keys (array_filter preserves keys).
        $slots = array_values( $slots );

        $defaults = [
            'base'    => self::DEFAULT_BASE_POSITION,
            'spacing' => self::DEFAULT_SPACING,
        ];

        if ( count( $slots ) < 2 ) {
            // Not enough slots to derive pattern, use defaults.
            if ( count( $slots ) === 1 ) {
                // Use single slot position as base.
                $position = $slots[0]->get_position();
                if ( $position > 0 ) {
                    $defaults['base'] = $position;
                }
            }
            return $defaults;
        }

        // Extract positions from slots.
        $positions = [];
        foreach ( $slots as $slot ) {
            $pos = $slot->get_position();
            if ( $pos > 0 ) {
                $positions[] = $pos;
            }
        }

        if ( count( $positions ) < 2 ) {
            return $defaults;
        }

        // Sort positions to find pattern.
        sort( $positions );

        // Base is the first position.
        $base = $positions[0];

        // Calculate average spacing between consecutive positions.
        $spacings = [];
        for ( $i = 1; $i < count( $positions ); $i++ ) {
            $spacings[] = $positions[ $i ] - $positions[ $i - 1 ];
        }

        $spacing = count( $spacings ) > 0
            ? (int) round( array_sum( $spacings ) / count( $spacings ) )
            : self::DEFAULT_SPACING;

        // Ensure spacing is at least 1.
        $spacing = max( 1, $spacing );

        return [
            'base'    => $base,
            'spacing' => $spacing,
        ];
    }

    /**
     * Get the next position for a placeholder slot.
     *
     * @param Slot[] $slots   Existing infinite slots.
     * @param array  $spacing Spacing configuration.
     * @return int Next position value.
     */
    private function get_next_position( array $slots, array $spacing ): int {
        if ( empty( $slots ) ) {
            return $spacing['base'];
        }

        // Find the highest existing position.
        $max_position = 0;
        foreach ( $slots as $slot ) {
            $pos = $slot->get_position();
            if ( $pos > $max_position ) {
                $max_position = $pos;
            }
        }

        // Next position is max + spacing.
        return $max_position + $spacing['spacing'];
    }

    /**
     * Create a placeholder slot for an infinite position.
     *
	 * @param string $device   Device type (desktop|mobile).
	 * @param int    $index    Slot index number.
	 * @param int    $position Paragraph position.
	 * @param Slot|null $template Slot to inherit targeting/layout settings from.
	 * @return Slot Generated placeholder Slot object.
	 */
	private function create_placeholder_slot( string $device, int $index, int $position, ?Slot $template = null ): Slot {
		$device_label = ucfirst( $device );

		return Slot::from_array(
			[
				'id'            => "{$device}-infinite-{$index}-placeholder",
				'name'          => "{$device_label} Infinite {$index}",
				'type'          => 'rectangle-infinite',
				'device'        => $device,
				'selector'      => $template ? $template->get_selector() : '.entry-content',
				'placement'     => $template ? $template->get_placement() : 'after',
				'position'      => $position,
				'min_height'    => $template ? $template->get_min_height() : 250,
				'margin_top'    => $template ? $template->get_margin_top() : 20,
				'margin_bottom' => $template ? $template->get_margin_bottom() : 20,
				'target_paths'  => $template ? $template->get_target_paths() : '',
			]
		);
	}

    /**
     * Check if a slot is an infinite/in-content type.
     *
     * @param Slot $slot The slot to check.
     * @return bool True if slot type contains 'infinite' or 'incontent'.
     */
    private function is_infinite_slot( Slot $slot ): bool {
        $type = strtolower( $slot->get_type() );

        return false !== strpos( $type, 'infinite' ) || false !== strpos( $type, 'incontent' );
    }
}
