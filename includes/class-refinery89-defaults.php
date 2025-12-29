<?php
/**
 * Refinery89 default slot definitions
 *
 * Based on Refinery89 implementation documentation.
 * Contains default heights for all standard R89 ad slots.
 *
 * @package Ad_Space_Reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASR_Refinery89_Defaults {

    /**
     * Desktop breakpoint (min-width for desktop)
     */
    const DESKTOP_BREAKPOINT = 992;

    /**
     * Default max number of infinite slots to generate
     */
    const DEFAULT_MAX_INFINITE_SLOTS = 10;

    /**
     * Default base paragraph position for first infinite slot
     */
    const DEFAULT_INFINITE_BASE_POSITION = 3;

    /**
     * Default spacing between infinite slots (in paragraphs)
     */
    const DEFAULT_INFINITE_SPACING = 5;

    /**
     * Get all slot definitions
     *
     * @return array
     */
    public static function get_slots() {
        return [
            'desktop' => self::get_desktop_slots(),
            'mobile' => self::get_mobile_slots(),
        ];
    }

    /**
     * Get desktop slot definitions (992px+)
     *
     * @return array
     */
    public static function get_desktop_slots() {
        return [
            'billboard-btf' => [
                'name' => 'Billboard BTF',
                'css_class' => 'r89-desktop-billboard-btf',
                'height' => 250,
                'description' => 'Billboard Below The Fold',
            ],
            'billboard-atf' => [
                'name' => 'Billboard ATF',
                'css_class' => 'r89-desktop-billboard-atf',
                'height' => 250,
                'description' => 'Billboard Above The Fold',
            ],
            'hpa-atf' => [
                'name' => 'HPA ATF',
                'css_class' => 'r89-desktop-hpa-atf',
                'height' => 600,
                'description' => 'High Profile Ad Above The Fold',
            ],
            'hpa-btf' => [
                'name' => 'HPA BTF',
                'css_class' => 'r89-desktop-hpa-btf',
                'height' => 600,
                'description' => 'High Profile Ad Below The Fold',
            ],
            'video-outstream' => [
                'name' => 'Video Outstream',
                'css_class' => 'r89-desktop-video-outstream',
                'height' => 250,
                'description' => 'Outstream Video Ad',
            ],
            'incontent' => [
                'name' => 'In-Content',
                'css_class' => 'r89-desktop-InContent',
                'height' => 250,
                'description' => 'In-Content Ad Unit',
            ],
            'leaderboard-atf' => [
                'name' => 'Leaderboard ATF',
                'css_class' => 'r89-desktop-leaderboard-atf',
                'height' => 90,
                'description' => 'Leaderboard Above The Fold',
            ],
            'leaderboard-btf' => [
                'name' => 'Leaderboard BTF',
                'css_class' => 'r89-desktop-leaderboard-btf',
                'height' => 90,
                'description' => 'Leaderboard Below The Fold',
            ],
            'rectangle-atf' => [
                'name' => 'Rectangle ATF',
                'css_class' => 'r89-desktop-rectangle-atf',
                'height' => 250,
                'description' => 'Rectangle Above The Fold',
            ],
            'rectangle-btf' => [
                'name' => 'Rectangle BTF',
                'css_class' => 'r89-desktop-rectangle-btf',
                'height' => 250,
                'description' => 'Rectangle Below The Fold',
            ],
            'header-pushup' => [
                'name' => 'Header Pushup',
                'css_class' => 'r89-desktop-header-pushup',
                'height' => 90,
                'description' => 'Header Pushup (Sticky)',
                'sticky' => true,
            ],
            'takeover' => [
                'name' => 'Takeover',
                'css_class' => 'r89-desktop-takeover',
                'height' => 250,
                'description' => 'Page Takeover Ad',
            ],
        ];
    }

    /**
     * Get mobile slot definitions (<992px)
     *
     * @return array
     */
    public static function get_mobile_slots() {
        return [
            'billboard-top' => [
                'name' => 'Billboard Top',
                'css_class' => 'r89-mobile-billboard-top',
                'height' => 250,
                'description' => 'Mobile Billboard Top',
            ],
            'rectangle-infinite' => [
                'name' => 'Rectangle Infinite',
                'css_class' => 'r89-mobile-rectangle-infinite',
                'height' => 250,
                'description' => 'Infinite Scroll Rectangle',
            ],
            'rectangle-low' => [
                'name' => 'Rectangle Low',
                'css_class' => 'r89-mobile-rectangle-low',
                'height' => 250,
                'description' => 'Low Position Rectangle',
            ],
            'rectangle-mid' => [
                'name' => 'Rectangle Mid',
                'css_class' => 'r89-mobile-rectangle-mid',
                'height' => 250,
                'description' => 'Mid Position Rectangle',
            ],
            'rectangle-mid-300x600' => [
                'name' => 'Rectangle Mid 300x600',
                'css_class' => 'r89-Mobile-Rectangle-Mid-300x600',
                'height' => 600,
                'description' => 'Large Mid Position Rectangle',
            ],
            'video-outstream' => [
                'name' => 'Video Outstream',
                'css_class' => 'r89-mobile-video-outstream',
                'height' => 250,
                'description' => 'Mobile Outstream Video',
            ],
            'header-pushup' => [
                'name' => 'Header Pushup',
                'css_class' => 'r89-mobile-header-pushup',
                'height' => 100,
                'description' => 'Mobile Header Pushup (Fixed)',
                'fixed' => true,
            ],
        ];
    }

    /**
     * Get slot heights as simple key-value pairs
     *
     * @return array
     */
    public static function get_slot_heights() {
        $heights = [];

        foreach (self::get_desktop_slots() as $key => $slot) {
            $heights['desktop-' . $key] = $slot['height'];
        }

        foreach (self::get_mobile_slots() as $key => $slot) {
            $heights['mobile-' . $key] = $slot['height'];
        }

        return $heights;
    }

    /**
     * Get slot by wrapper ID pattern
     *
     * @param string $wrapperId e.g., "r89-desktop-billboard-btf-0-wrapper"
     * @return array|null
     */
    public static function get_slot_by_wrapper_id($wrapperId) {
        // Parse wrapper ID
        $pattern = '/r89-(desktop|mobile)-(.+?)-\d+-wrapper/i';
        if (!preg_match($pattern, $wrapperId, $matches)) {
            return null;
        }

        $device = strtolower($matches[1]);
        $slotType = strtolower($matches[2]);

        $slots = $device === 'desktop' ? self::get_desktop_slots() : self::get_mobile_slots();

        // Try exact match
        if (isset($slots[$slotType])) {
            return array_merge($slots[$slotType], ['device' => $device, 'slot_key' => $slotType]);
        }

        // Try partial match
        foreach ($slots as $key => $slot) {
            if (strpos($slotType, $key) !== false || strpos($key, $slotType) !== false) {
                return array_merge($slot, ['device' => $device, 'slot_key' => $key]);
            }
        }

        return null;
    }

    /**
     * Get CSS class suggestions for targeting
     *
     * @param string $device 'desktop' or 'mobile'
     * @param string $slotType Slot type key
     * @return string
     */
    public static function get_suggested_css_class($device, $slotType) {
        return sprintf('asr-%s-%s', sanitize_title($device), sanitize_title($slotType));
    }
}
