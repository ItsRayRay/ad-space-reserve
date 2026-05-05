<?php
/**
 * Slot class for ad slot data structure.
 *
 * @package AdSpaceReserve\Core
 */

namespace AdSpaceReserve\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single ad slot configuration.
 *
 * Provides validation, serialization, and hydration for slot data.
 */
class Slot {

    /**
     * Valid device options.
     */
    private const VALID_DEVICES = [ 'desktop', 'mobile', 'both' ];

    /**
     * Valid placement options.
     */
    private const VALID_PLACEMENTS = [ 'before', 'after', 'inside' ];

    /**
     * Valid selector mode options.
     */
    private const VALID_SELECTOR_MODES = [ 'first', 'all' ];

    /**
     * Unique identifier for the slot.
     *
     * @var string
     */
    private string $id;

    /**
     * User-defined display name.
     *
     * @var string
     */
    private string $name;

    /**
     * Slot type key or 'custom'.
     *
     * @var string
     */
    private string $type;

    /**
     * Device targeting (desktop|mobile|both).
     *
     * @var string
     */
    private string $device;

    /**
     * CSS selector for target element.
     *
     * @var string
     */
    private string $selector;

    /**
     * Placement relative to target (before|after|inside).
     *
     * @var string
     */
    private string $placement;

    /**
     * Minimum height in pixels.
     *
     * @var int
     */
    private int $min_height;

    /**
     * Top margin in pixels.
     *
     * @var int
     */
    private int $margin_top;

    /**
     * Bottom margin in pixels.
     *
     * @var int
     */
    private int $margin_bottom;

    /**
     * ISO 8601 creation timestamp.
     *
     * @var string
     */
    private string $created_at;

    /**
     * ISO 8601 last update timestamp.
     *
     * @var string
     */
    private string $updated_at;

    /**
     * Paragraph position for in-content placement.
     *
     * 0 = use placement logic (before/after/inside)
     * N > 0 = inject after paragraph N
     *
     * @var int
     */
    private int $position;

    /**
     * Whether the slot becomes sticky on scroll.
     *
     * @var bool
     */
    private bool $is_sticky;

    /**
     * Pixels from viewport top when sticky.
     *
     * @var int
     */
    private int $sticky_offset;

    /**
     * Whether fallback image is enabled for this slot.
     *
     * @var bool
     */
    private bool $has_fallback;

    /**
     * Full URL to fallback image.
     *
     * @var string
     */
    private string $fallback_image_url;

    /**
     * Milliseconds to wait before showing fallback.
     *
     * @var int
     */
    private int $fallback_timeout;

    /**
     * URL to link to when fallback image is clicked.
     *
     * @var string
     */
    private string $fallback_link_url;

    /**
     * Custom CSS rules for this slot.
     *
     * @var string
     */
    private string $custom_css;

    /**
     * Selector targeting mode (first|all).
     *
     * 'first' = target only first matching element
     * 'all' = target all matching elements (for infinite scroll/repeating content)
     *
     * @var string
     */
    private string $selector_mode;

    /**
     * Optional URL/path allow-list for this slot.
     *
     * Empty means the slot is eligible on every frontend page.
     *
     * @var string
     */
    private string $target_paths;

    /**
     * Construct a Slot from array data.
     *
     * @param array $data Slot data array.
     */
    public function __construct( array $data ) {
        $defaults = self::get_defaults();
        $data     = array_merge( $defaults, $data );

        $this->id            = (string) $data['id'];
        $this->name          = (string) $data['name'];
        $this->type          = (string) $data['type'];
        $this->device        = (string) $data['device'];
        $this->selector      = (string) $data['selector'];
        $this->placement     = (string) $data['placement'];
        $this->min_height    = (int) $data['min_height'];
        $this->margin_top    = (int) $data['margin_top'];
        $this->margin_bottom = (int) $data['margin_bottom'];
        $this->created_at    = (string) $data['created_at'];
        $this->updated_at    = (string) $data['updated_at'];
        $this->position           = (int) $data['position'];
        $this->is_sticky          = (bool) $data['is_sticky'];
        $this->sticky_offset      = (int) $data['sticky_offset'];
        $this->has_fallback       = (bool) $data['has_fallback'];
        $this->fallback_image_url = (string) $data['fallback_image_url'];
        $this->fallback_timeout   = (int) $data['fallback_timeout'];
        $this->fallback_link_url  = (string) $data['fallback_link_url'];
        $this->custom_css         = (string) $data['custom_css'];
        $this->selector_mode      = (string) $data['selector_mode'];
        $this->target_paths       = (string) $data['target_paths'];
    }

    /**
     * Get the slot ID.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Get the slot name.
     *
     * @return string
     */
    public function get_name(): string {
        return $this->name;
    }

    /**
     * Get the slot type.
     *
     * @return string
     */
    public function get_type(): string {
        return $this->type;
    }

    /**
     * Get the device targeting.
     *
     * @return string
     */
    public function get_device(): string {
        return $this->device;
    }

    /**
     * Get the CSS selector.
     *
     * @return string
     */
    public function get_selector(): string {
        return $this->selector;
    }

    /**
     * Get the placement.
     *
     * @return string
     */
    public function get_placement(): string {
        return $this->placement;
    }

    /**
     * Get the minimum height.
     *
     * @return int
     */
    public function get_min_height(): int {
        return $this->min_height;
    }

    /**
     * Get the top margin.
     *
     * @return int
     */
    public function get_margin_top(): int {
        return $this->margin_top;
    }

    /**
     * Get the bottom margin.
     *
     * @return int
     */
    public function get_margin_bottom(): int {
        return $this->margin_bottom;
    }

    /**
     * Get the creation timestamp.
     *
     * @return string
     */
    public function get_created_at(): string {
        return $this->created_at;
    }

    /**
     * Get the last update timestamp.
     *
     * @return string
     */
    public function get_updated_at(): string {
        return $this->updated_at;
    }

    /**
     * Get the paragraph position for in-content placement.
     *
     * @return int Position (0 = use placement logic, N > 0 = after paragraph N).
     */
    public function get_position(): int {
        return $this->position;
    }

    /**
     * Get whether the slot is sticky.
     *
     * @return bool True if slot becomes sticky on scroll.
     */
    public function get_is_sticky(): bool {
        return $this->is_sticky;
    }

    /**
     * Get the sticky offset from viewport top.
     *
     * @return int Pixels from viewport top when sticky.
     */
    public function get_sticky_offset(): int {
        return $this->sticky_offset;
    }

    /**
     * Get whether fallback is enabled.
     *
     * @return bool True if fallback image is enabled.
     */
    public function get_has_fallback(): bool {
        return $this->has_fallback;
    }

    /**
     * Get the fallback image URL.
     *
     * @return string Full URL to fallback image.
     */
    public function get_fallback_image_url(): string {
        return $this->fallback_image_url;
    }

    /**
     * Get the fallback timeout in milliseconds.
     *
     * @return int Milliseconds to wait before showing fallback.
     */
    public function get_fallback_timeout(): int {
        return $this->fallback_timeout;
    }

    /**
     * Get the fallback link URL.
     *
     * @return string URL to link to when fallback is clicked.
     */
    public function get_fallback_link_url(): string {
        return $this->fallback_link_url;
    }

    /**
     * Get the custom CSS rules.
     *
     * @return string Custom CSS for this slot.
     */
    public function get_custom_css(): string {
        return $this->custom_css;
    }

    /**
     * Get the selector targeting mode.
     *
     * @return string Selector mode (first|all).
     */
    public function get_selector_mode(): string {
        return $this->selector_mode;
    }

    /**
     * Get optional URL/path targeting rules.
     *
     * @return string Newline or comma separated path patterns.
     */
    public function get_target_paths(): string {
        return $this->target_paths;
    }

    /**
     * Serialize slot to array for storage.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'type'          => $this->type,
            'device'        => $this->device,
            'selector'      => $this->selector,
            'placement'     => $this->placement,
            'min_height'    => $this->min_height,
            'margin_top'    => $this->margin_top,
            'margin_bottom' => $this->margin_bottom,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'position'           => $this->position,
            'is_sticky'          => $this->is_sticky,
            'sticky_offset'      => $this->sticky_offset,
            'has_fallback'       => $this->has_fallback,
            'fallback_image_url' => $this->fallback_image_url,
            'fallback_timeout'   => $this->fallback_timeout,
            'fallback_link_url'  => $this->fallback_link_url,
            'custom_css'         => $this->custom_css,
            'selector_mode'      => $this->selector_mode,
            'target_paths'       => $this->target_paths,
        ];
    }

    /**
     * Validate the slot data.
     *
     * @return array Array of error messages (empty if valid).
     */
    public function validate(): array {
        $errors = [];

        // Name validation: required, 1-100 chars.
        $sanitized_name = sanitize_text_field( $this->name );
        if ( empty( $sanitized_name ) ) {
            $errors[] = __( 'Slot name is required.', 'adshimmer' );
        } elseif ( strlen( $sanitized_name ) > 100 ) {
            $errors[] = __( 'Slot name must be 100 characters or less.', 'adshimmer' );
        }

        // Type validation: must be valid slot type or 'custom'.
        if ( empty( $this->type ) ) {
            $errors[] = __( 'Slot type is required.', 'adshimmer' );
        } elseif ( ! $this->is_valid_type( $this->type ) ) {
            $errors[] = __( 'Invalid slot type.', 'adshimmer' );
        }

        // Device validation: must be desktop|mobile|both.
        if ( empty( $this->device ) ) {
            $errors[] = __( 'Device is required.', 'adshimmer' );
        } elseif ( ! in_array( $this->device, self::VALID_DEVICES, true ) ) {
            $errors[] = __( 'Device must be desktop, mobile, or both.', 'adshimmer' );
        }

        // Selector validation: required, 1-500 chars, basic CSS validation.
        if ( empty( $this->selector ) ) {
            $errors[] = __( 'Selector is required.', 'adshimmer' );
        } elseif ( strlen( $this->selector ) > 500 ) {
            $errors[] = __( 'Selector must be 500 characters or less.', 'adshimmer' );
        } elseif ( ! $this->is_valid_selector( $this->selector ) ) {
            $errors[] = __( 'Selector must be a valid CSS selector (start with ., #, or tag name).', 'adshimmer' );
        }

        // Placement validation: must be before|after|inside.
        if ( empty( $this->placement ) ) {
            $errors[] = __( 'Placement is required.', 'adshimmer' );
        } elseif ( ! in_array( $this->placement, self::VALID_PLACEMENTS, true ) ) {
            $errors[] = __( 'Placement must be before, after, or inside.', 'adshimmer' );
        }

        // Min height validation: 0-2000.
        if ( $this->min_height < 0 || $this->min_height > 2000 ) {
            $errors[] = __( 'Minimum height must be between 0 and 2000 pixels.', 'adshimmer' );
        }

        // Margin validations: 0-500.
        if ( $this->margin_top < 0 || $this->margin_top > 500 ) {
            $errors[] = __( 'Top margin must be between 0 and 500 pixels.', 'adshimmer' );
        }

        if ( $this->margin_bottom < 0 || $this->margin_bottom > 500 ) {
            $errors[] = __( 'Bottom margin must be between 0 and 500 pixels.', 'adshimmer' );
        }

        if ( $this->position < 0 || $this->position > 1000 ) {
            $errors[] = __( 'Paragraph position must be between 0 and 1000.', 'adshimmer' );
        }

        // Sticky offset validation: 0-500 when sticky is enabled.
        if ( $this->is_sticky && ( $this->sticky_offset < 0 || $this->sticky_offset > 500 ) ) {
            $errors[] = __( 'Sticky offset must be between 0 and 500 pixels.', 'adshimmer' );
        }

        // Fallback validation: when enabled, URL required and timeout in range.
        if ( $this->has_fallback ) {
            $sanitized_url = esc_url_raw( $this->fallback_image_url );
            if ( empty( $sanitized_url ) ) {
                $errors[] = __( 'Fallback image URL is required when fallback is enabled.', 'adshimmer' );
            }

            if ( $this->fallback_timeout < 1000 || $this->fallback_timeout > 30000 ) {
                $errors[] = __( 'Fallback timeout must be between 1000 and 30000 milliseconds.', 'adshimmer' );
            }
        }

        // Selector mode validation: must be first|all.
        if ( ! in_array( $this->selector_mode, self::VALID_SELECTOR_MODES, true ) ) {
            $errors[] = __( 'Selector mode must be first or all.', 'adshimmer' );
        }

        if ( strlen( $this->target_paths ) > 2000 ) {
            $errors[] = __( 'URL/path targeting rules must be 2000 characters or less.', 'adshimmer' );
        }

        return $errors;
    }

    /**
     * Factory method to create a Slot from array data.
     *
     * @param array $data Slot data array.
     * @return Slot
     */
    public static function from_array( array $data ): Slot {
        return new self( $data );
    }

    /**
     * Get default values for a new slot.
     *
     * @return array
     */
    public static function get_defaults(): array {
        $now = gmdate( 'c' );
        return [
            'id'            => '',
            'name'          => '',
            'type'          => 'custom',
            'device'        => 'both',
            'selector'      => '',
            'placement'     => 'after',
            'min_height'    => 250,
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
            'position'           => 0,
            'is_sticky'          => false,
            'sticky_offset'      => 0,
            'has_fallback'       => false,
            'fallback_image_url' => '',
            'fallback_timeout'   => 2000,
            'fallback_link_url'  => '',
            'custom_css'         => '',
            'selector_mode'      => 'first',
            'target_paths'       => '',
        ];
    }

    /**
     * Generate a slug-based ID from a name.
     *
     * @param string $name The slot name.
     * @return string Generated ID.
     */
    public static function generate_id( string $name ): string {
        // Create slug from name.
        $slug = sanitize_title( $name );

        // If empty, generate random string.
        if ( empty( $slug ) ) {
            $slug = 'slot';
        }

        // Add timestamp for uniqueness.
        return $slug . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    }

    /**
     * Get the default height for a slot type from slot defaults.
     *
     * @param string $type   The slot type key.
     * @param string $device The device type (desktop|mobile).
     * @return int Default height in pixels.
     */
    public static function get_default_height_for_type( string $type, string $device = 'desktop' ): int {
        if ( 'custom' === $type || ! class_exists( 'ASR_Slot_Defaults' ) ) {
            return 250;
        }

        // Strip device prefix (e.g. "desktop-billboard-btf" -> "billboard-btf").
        $stripped = preg_replace( '/^(desktop|mobile)-/', '', $type );

        $slots = 'mobile' === $device
            ? \ASR_Slot_Defaults::get_mobile_slots()
            : \ASR_Slot_Defaults::get_desktop_slots();

        if ( isset( $slots[ $stripped ]['height'] ) ) {
            return (int) $slots[ $stripped ]['height'];
        }

        return 250;
    }

    /**
     * Check if a type is valid (predefined slot type or 'custom').
     *
     * @param string $type The type to validate.
     * @return bool
     */
    private function is_valid_type( string $type ): bool {
        if ( 'custom' === $type ) {
            return true;
        }

        if ( ! class_exists( 'ASR_Slot_Defaults' ) ) {
            // If slot defaults not available, allow any type.
            return true;
        }

        // Strip device prefix (e.g. "desktop-billboard-btf" -> "billboard-btf").
        $stripped = preg_replace( '/^(desktop|mobile)-/', '', $type );

        $desktop_slots = \ASR_Slot_Defaults::get_desktop_slots();
        $mobile_slots  = \ASR_Slot_Defaults::get_mobile_slots();

        return isset( $desktop_slots[ $stripped ] ) || isset( $mobile_slots[ $stripped ] );
    }

    /**
     * Basic CSS selector validation.
     *
     * Checks if selector starts with ., #, or a valid tag name.
     *
     * @param string $selector The selector to validate.
     * @return bool
     */
    private function is_valid_selector( string $selector ): bool {
        $selector = trim( $selector );

        if ( empty( $selector ) ) {
            return false;
        }

        // Must start with ., #, or alphabetic character (tag name).
        $first_char = substr( $selector, 0, 1 );

        if ( '.' === $first_char || '#' === $first_char ) {
            return true;
        }

        // Check if starts with alphabetic (tag name).
        if ( preg_match( '/^[a-zA-Z]/', $selector ) ) {
            return true;
        }

        return false;
    }
}
