<?php
/**
 * Theme Writer
 *
 * Writes generated PHP and CSS files to the child theme.
 *
 * @package Ad_Space_Reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASR_Theme_Writer {

    /**
     * PHP filename
     */
    const PHP_FILENAME = 'asr-containers.php';

    /**
     * CSS filename
     */
    const CSS_FILENAME = 'asr-styles.css';

    /**
     * Write files to child theme
     *
     * @param string $php_code PHP code to write
     * @param string $css_code CSS code to write
     * @return array Result with success status and file paths
     */
    public function write_files($php_code, $css_code) {
        // Check for child theme
        $theme_dir = $this->get_theme_directory();

        if (!$theme_dir) {
            return [
                'success' => false,
                'error' => __('No child theme detected. Please activate a child theme first.', 'ad-space-reserve'),
            ];
        }

        // Check if directory is writable
        if (!wp_is_writable($theme_dir)) {
            return [
                'success' => false,
                'error' => sprintf(
                    __('Theme directory is not writable: %s', 'ad-space-reserve'),
                    $theme_dir
                ),
            ];
        }

        $files = [];

        // Write PHP file
        $php_path = $theme_dir . '/' . self::PHP_FILENAME;
        $php_result = $this->write_file($php_path, $php_code);

        if (!$php_result) {
            return [
                'success' => false,
                'error' => sprintf(
                    __('Failed to write PHP file: %s', 'ad-space-reserve'),
                    $php_path
                ),
            ];
        }

        $files[] = $php_path;

        // Write CSS file
        $css_path = $theme_dir . '/' . self::CSS_FILENAME;
        $css_result = $this->write_file($css_path, $css_code);

        if (!$css_result) {
            return [
                'success' => false,
                'error' => sprintf(
                    __('Failed to write CSS file: %s', 'ad-space-reserve'),
                    $css_path
                ),
            ];
        }

        $files[] = $css_path;

        // Include PHP file in functions.php
        $include_result = $this->add_include_to_functions($theme_dir);

        if (!$include_result['success']) {
            return [
                'success' => false,
                'error' => $include_result['error'],
            ];
        }

        // Enqueue CSS file
        $enqueue_result = $this->add_css_enqueue($theme_dir);

        return [
            'success' => true,
            'files' => $files,
            'message' => __('Files written successfully to child theme.', 'ad-space-reserve'),
        ];
    }

    /**
     * Get the appropriate theme directory
     *
     * Prefers child theme, falls back to parent theme.
     *
     * @return string|false Theme directory path or false
     */
    private function get_theme_directory() {
        // Check for child theme first
        if (is_child_theme()) {
            return get_stylesheet_directory();
        }

        // Allow parent theme if explicitly enabled
        if (apply_filters('asr_allow_parent_theme', false)) {
            return get_template_directory();
        }

        return false;
    }

    /**
     * Write content to a file with backup
     *
     * @param string $path File path
     * @param string $content Content to write
     * @return bool Success status
     */
    private function write_file($path, $content) {
        // Create backup if file exists
        if (file_exists($path)) {
            $backup_path = $path . '.backup.' . date('Y-m-d-His');
            @copy($path, $backup_path);
        }

        // Use WP_Filesystem
        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem->put_contents($path, $content, FS_CHMOD_FILE);
    }

    /**
     * Add require_once to functions.php if not present
     *
     * @param string $theme_dir Theme directory
     * @return array Result
     */
    private function add_include_to_functions($theme_dir) {
        $functions_path = $theme_dir . '/functions.php';

        // Read existing functions.php
        if (!file_exists($functions_path)) {
            // Create functions.php with include
            $content = "<?php\n";
            $content .= "// Child theme functions\n\n";
            $content .= $this->get_include_code();

            return [
                'success' => $this->write_file($functions_path, $content),
                'error' => __('Failed to create functions.php', 'ad-space-reserve'),
            ];
        }

        $content = file_get_contents($functions_path);

        // Check if already included
        if (strpos($content, self::PHP_FILENAME) !== false) {
            return ['success' => true];
        }

        // Find the best place to add include
        $include_code = "\n" . $this->get_include_code();

        // Try to add after opening <?php tag
        if (preg_match('/^<\?php\s*/i', $content, $matches)) {
            $content = preg_replace(
                '/^(<\?php\s*)/i',
                '$1' . $include_code,
                $content
            );
        } else {
            // Append to end
            $content .= $include_code;
        }

        $result = $this->write_file($functions_path, $content);

        return [
            'success' => $result,
            'error' => $result ? '' : __('Failed to update functions.php', 'ad-space-reserve'),
        ];
    }

    /**
     * Get the include code snippet
     *
     * @return string
     */
    private function get_include_code() {
        $code = "\n// Ad Space Reserve - CLS Prevention\n";
        $code .= "if (file_exists(get_stylesheet_directory() . '/" . self::PHP_FILENAME . "')) {\n";
        $code .= "    require_once get_stylesheet_directory() . '/" . self::PHP_FILENAME . "';\n";
        $code .= "}\n";

        return $code;
    }

    /**
     * Add CSS enqueue to functions.php if not present
     *
     * @param string $theme_dir Theme directory
     * @return array Result
     */
    private function add_css_enqueue($theme_dir) {
        $functions_path = $theme_dir . '/functions.php';
        $content = file_get_contents($functions_path);

        // Check if already enqueuing our CSS
        if (strpos($content, self::CSS_FILENAME) !== false) {
            return ['success' => true];
        }

        $enqueue_code = "\n// Ad Space Reserve - Enqueue CLS prevention styles\n";
        $enqueue_code .= "add_action('wp_enqueue_scripts', function() {\n";
        $enqueue_code .= "    if (file_exists(get_stylesheet_directory() . '/" . self::CSS_FILENAME . "')) {\n";
        $enqueue_code .= "        wp_enqueue_style(\n";
        $enqueue_code .= "            'asr-cls-prevention',\n";
        $enqueue_code .= "            get_stylesheet_directory_uri() . '/" . self::CSS_FILENAME . "',\n";
        $enqueue_code .= "            [],\n";
        $enqueue_code .= "            filemtime(get_stylesheet_directory() . '/" . self::CSS_FILENAME . "')\n";
        $enqueue_code .= "        );\n";
        $enqueue_code .= "    }\n";
        $enqueue_code .= "}, 5); // Load early for CLS prevention\n";

        $content .= $enqueue_code;

        return [
            'success' => $this->write_file($functions_path, $content),
            'error' => __('Failed to add CSS enqueue', 'ad-space-reserve'),
        ];
    }

    /**
     * Remove generated files from child theme
     *
     * @return array Result
     */
    public function remove_files() {
        $theme_dir = $this->get_theme_directory();

        if (!$theme_dir) {
            return ['success' => false, 'error' => 'No child theme'];
        }

        $removed = [];

        // Remove PHP file
        $php_path = $theme_dir . '/' . self::PHP_FILENAME;
        if (file_exists($php_path)) {
            @unlink($php_path);
            $removed[] = $php_path;
        }

        // Remove CSS file
        $css_path = $theme_dir . '/' . self::CSS_FILENAME;
        if (file_exists($css_path)) {
            @unlink($css_path);
            $removed[] = $css_path;
        }

        return [
            'success' => true,
            'removed' => $removed,
        ];
    }

    /**
     * Check if files exist in child theme
     *
     * @return array File existence status
     */
    public function check_files() {
        $theme_dir = $this->get_theme_directory();

        if (!$theme_dir) {
            return [
                'has_theme' => false,
                'php_exists' => false,
                'css_exists' => false,
            ];
        }

        return [
            'has_theme' => true,
            'theme_dir' => $theme_dir,
            'php_exists' => file_exists($theme_dir . '/' . self::PHP_FILENAME),
            'css_exists' => file_exists($theme_dir . '/' . self::CSS_FILENAME),
            'php_path' => $theme_dir . '/' . self::PHP_FILENAME,
            'css_path' => $theme_dir . '/' . self::CSS_FILENAME,
        ];
    }
}
