<?php
/**
 * Scanner class for handling detected ad slots
 *
 * @package Ad_Space_Reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASR_Scanner {

    /**
     * Save scan data received from frontend
     *
     * @param array|string $slots Slot data from frontend scanner
     * @return bool Success status
     */
    public function save_scan_data($slots) {
        // Parse JSON if string
        if (is_string($slots)) {
            $slots = json_decode(stripslashes($slots), true);
        }

        if (!is_array($slots) || empty($slots)) {
            return false;
        }

        $settings = get_option('asr_settings', []);
        $existing_slots = $settings['detected_slots'] ?? [];

        // Merge new slots, avoiding duplicates
        foreach ($slots as $slot) {
            $slot = $this->sanitize_slot($slot);
            if (!$slot) continue;

            // Check for duplicate by wrapper ID
            $exists = false;
            foreach ($existing_slots as $key => $existing) {
                if ($existing['wrapperId'] === $slot['wrapperId']) {
                    // Update existing with newer data
                    $existing_slots[$key] = array_merge($existing, $slot);
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $existing_slots[] = $slot;
            }
        }

        $settings['detected_slots'] = $existing_slots;
        $settings['last_scan'] = current_time('mysql');

        return update_option('asr_settings', $settings);
    }

    /**
     * Sanitize slot data
     *
     * @param array $slot Raw slot data
     * @return array|false Sanitized slot or false if invalid
     */
    private function sanitize_slot($slot) {
        if (!isset($slot['wrapperId']) || empty($slot['wrapperId'])) {
            return false;
        }

        return [
            'wrapperId' => sanitize_text_field($slot['wrapperId']),
            'device' => sanitize_text_field($slot['device'] ?? 'unknown'),
            'slotType' => sanitize_text_field($slot['slotType'] ?? 'unknown'),
            'parentSelector' => sanitize_text_field($slot['parentSelector'] ?? ''),
            'position' => absint($slot['position'] ?? 0),
            'renderedHeight' => absint($slot['renderedHeight'] ?? 0),
            'renderedWidth' => absint($slot['renderedWidth'] ?? 0),
            'pageUrl' => esc_url_raw($slot['pageUrl'] ?? ''),
            'pageType' => sanitize_text_field($slot['pageType'] ?? 'unknown'),
            'timestamp' => sanitize_text_field($slot['timestamp'] ?? ''),
        ];
    }

    /**
     * Get all detected slots
     *
     * @return array
     */
    public function get_detected_slots() {
        $settings = get_option('asr_settings', []);
        return $settings['detected_slots'] ?? [];
    }

    /**
     * Get detected slots grouped by device
     *
     * @return array
     */
    public function get_slots_by_device() {
        $slots = $this->get_detected_slots();
        $grouped = [
            'desktop' => [],
            'mobile' => [],
            'unknown' => [],
        ];

        foreach ($slots as $slot) {
            $device = $slot['device'] ?? 'unknown';
            if (!isset($grouped[$device])) {
                $grouped[$device] = [];
            }
            $grouped[$device][] = $slot;
        }

        return $grouped;
    }

    /**
     * Clear all detected slots
     *
     * @return bool
     */
    public function clear_slots() {
        $settings = get_option('asr_settings', []);
        $settings['detected_slots'] = [];
        return update_option('asr_settings', $settings);
    }

    /**
     * Get suggested min-height for a slot based on R89 defaults
     *
     * @param array $slot Slot data
     * @return int Suggested height in pixels
     */
    public function get_suggested_height($slot) {
        $defaults = ASR_Refinery89_Defaults::get_slot_heights();
        $device = $slot['device'] ?? 'desktop';
        $slotType = strtolower($slot['slotType'] ?? '');

        // Try exact match first
        $key = $device . '-' . $slotType;
        if (isset($defaults[$key])) {
            return $defaults[$key];
        }

        // Try partial match
        foreach ($defaults as $default_key => $height) {
            if (strpos($default_key, $slotType) !== false ||
                strpos($slotType, str_replace([$device . '-', 'desktop-', 'mobile-'], '', $default_key)) !== false) {
                return $height;
            }
        }

        // Use rendered height if available
        if (!empty($slot['renderedHeight']) && $slot['renderedHeight'] > 50) {
            return $slot['renderedHeight'];
        }

        // Default fallback
        return 250;
    }

    /**
     * Move slot from detected to configured
     *
     * @param string $wrapperId Wrapper ID to configure
     * @param array $config Configuration overrides
     * @return bool
     */
    public function configure_slot($wrapperId, $config = []) {
        $settings = get_option('asr_settings', []);
        $detected = $settings['detected_slots'] ?? [];
        $configured = $settings['configured_slots'] ?? [];

        // Find the slot
        $slot = null;
        foreach ($detected as $s) {
            if ($s['wrapperId'] === $wrapperId) {
                $slot = $s;
                break;
            }
        }

        if (!$slot) {
            return false;
        }

        // Merge with config
        $slot = array_merge($slot, [
            'minHeight' => absint($config['minHeight'] ?? $this->get_suggested_height($slot)),
            'marginTop' => absint($config['marginTop'] ?? 20),
            'marginBottom' => absint($config['marginBottom'] ?? 20),
            'enabled' => !empty($config['enabled']),
            'injectionLocation' => sanitize_text_field($config['injectionLocation'] ?? 'after_paragraph'),
            'injectionPosition' => absint($config['injectionPosition'] ?? 3),
            'cssClass' => $this->generate_css_class($slot),
        ]);

        // Add to configured slots
        $configured[$wrapperId] = $slot;
        $settings['configured_slots'] = $configured;

        return update_option('asr_settings', $settings);
    }

    /**
     * Generate CSS class name for a slot
     *
     * @param array $slot Slot data
     * @return string
     */
    private function generate_css_class($slot) {
        $device = sanitize_title($slot['device'] ?? 'unknown');
        $type = sanitize_title($slot['slotType'] ?? 'ad');
        return sprintf('asr-%s-%s', $device, $type);
    }

    /**
     * Get configured slots
     *
     * @return array
     */
    public function get_configured_slots() {
        $settings = get_option('asr_settings', []);
        return $settings['configured_slots'] ?? [];
    }

    /**
     * Remove a configured slot
     *
     * @param string $wrapperId
     * @return bool
     */
    public function unconfigure_slot($wrapperId) {
        $settings = get_option('asr_settings', []);
        $configured = $settings['configured_slots'] ?? [];

        if (isset($configured[$wrapperId])) {
            unset($configured[$wrapperId]);
            $settings['configured_slots'] = $configured;
            return update_option('asr_settings', $settings);
        }

        return false;
    }
}
