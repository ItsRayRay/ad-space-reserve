<?php
/**
 * Content Analyzer for AI-powered ad placement recommendations.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Analyzes site content to provide context for AI recommendations.
 *
 * Fetches recent posts and extracts patterns like paragraph counts,
 * heading usage, writing style, and theme selectors.
 */
class Content_Analyzer {

    /**
     * Number of posts to analyze.
     */
    private const POSTS_TO_ANALYZE = 5;

    /**
     * Number of sample excerpts to include.
     */
    private const MAX_EXCERPTS = 3;

    /**
     * Excerpt length in words.
     */
    private const EXCERPT_WORDS = 100;

    /**
     * Analyze site content and return structured data.
     *
     * @return array{
     *     post_count: int,
     *     avg_paragraphs: int,
     *     avg_words: int,
     *     heading_pattern: array<string, int>,
     *     paragraph_style: string,
     *     has_images: bool,
     *     sample_excerpts: array<array{title: string, excerpt: string}>,
     *     detected_selectors: array<string, string>
     * } Analysis results.
     */
    public function analyze(): array {
        $posts = get_posts(
            [
                'numberposts' => self::POSTS_TO_ANALYZE,
                'post_type'   => 'post',
                'post_status' => 'publish',
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]
        );

        if ( empty( $posts ) ) {
            return $this->get_empty_analysis();
        }

        $total_paragraphs = 0;
        $total_words      = 0;
        $total_headings   = [ 'h2' => 0, 'h3' => 0 ];
        $has_images       = false;

        foreach ( $posts as $post ) {
            $content = apply_filters( 'the_content', $post->post_content );

            $total_paragraphs      += $this->count_paragraphs( $content );
            $total_words           += $this->count_words( $content );
            $headings               = $this->count_headings( $content );
            $total_headings['h2'] += $headings['h2'];
            $total_headings['h3'] += $headings['h3'];

            if ( ! $has_images && $this->has_images( $content ) ) {
                $has_images = true;
            }
        }

        $post_count = count( $posts );

        return [
            'post_count'          => $post_count,
            'avg_paragraphs'      => (int) round( $total_paragraphs / $post_count ),
            'avg_words'           => (int) round( $total_words / $post_count ),
            'heading_pattern'     => [
                'h2' => (int) round( $total_headings['h2'] / $post_count ),
                'h3' => (int) round( $total_headings['h3'] / $post_count ),
            ],
            'paragraph_style'     => $this->calculate_paragraph_style( $posts ),
            'has_images'          => $has_images,
            'sample_excerpts'     => $this->get_sample_excerpts( $posts ),
            'detected_selectors'  => $this->detect_theme_selectors(),
        ];
    }

    /**
     * Count paragraphs in HTML content.
     *
     * @param string $content HTML content.
     * @return int Paragraph count.
     */
    private function count_paragraphs( string $content ): int {
        // Count <p> tags.
        preg_match_all( '/<p[^>]*>/i', $content, $matches );
        return count( $matches[0] );
    }

    /**
     * Count words in HTML content.
     *
     * @param string $content HTML content.
     * @return int Word count.
     */
    private function count_words( string $content ): int {
        $text = wp_strip_all_tags( $content );
        return str_word_count( $text );
    }

    /**
     * Count h2 and h3 headings in HTML content.
     *
     * @param string $content HTML content.
     * @return array{h2: int, h3: int} Heading counts.
     */
    private function count_headings( string $content ): array {
        preg_match_all( '/<h2[^>]*>/i', $content, $h2_matches );
        preg_match_all( '/<h3[^>]*>/i', $content, $h3_matches );

        return [
            'h2' => count( $h2_matches[0] ),
            'h3' => count( $h3_matches[0] ),
        ];
    }

    /**
     * Check if content contains images.
     *
     * @param string $content HTML content.
     * @return bool True if images found.
     */
    private function has_images( string $content ): bool {
        return (bool) preg_match( '/<img[^>]*>/i', $content );
    }

    /**
     * Calculate paragraph style based on average paragraph length.
     *
     * @param \WP_Post[] $posts Array of post objects.
     * @return string 'short' (<50 words avg), 'medium', or 'long' (>100).
     */
    private function calculate_paragraph_style( array $posts ): string {
        $total_paragraph_words = 0;
        $total_paragraphs      = 0;

        foreach ( $posts as $post ) {
            $content = apply_filters( 'the_content', $post->post_content );

            // Extract paragraph contents.
            preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $content, $matches );

            if ( ! empty( $matches[1] ) ) {
                foreach ( $matches[1] as $paragraph_content ) {
                    $text                   = wp_strip_all_tags( $paragraph_content );
                    $words                  = str_word_count( $text );
                    $total_paragraph_words += $words;
                    $total_paragraphs++;
                }
            }
        }

        if ( 0 === $total_paragraphs ) {
            return 'medium';
        }

        $avg_words_per_paragraph = $total_paragraph_words / $total_paragraphs;

        if ( $avg_words_per_paragraph < 50 ) {
            return 'short';
        } elseif ( $avg_words_per_paragraph > 100 ) {
            return 'long';
        }

        return 'medium';
    }

    /**
     * Get sample excerpts from posts.
     *
     * @param \WP_Post[] $posts Array of post objects.
     * @return array<array{title: string, excerpt: string}> Sample excerpts.
     */
    private function get_sample_excerpts( array $posts ): array {
        $excerpts = [];

        foreach ( array_slice( $posts, 0, self::MAX_EXCERPTS ) as $post ) {
            $content    = apply_filters( 'the_content', $post->post_content );
            $text       = wp_strip_all_tags( $content );
            $words      = explode( ' ', $text );
            $excerpt    = implode( ' ', array_slice( $words, 0, self::EXCERPT_WORDS ) );

            if ( count( $words ) > self::EXCERPT_WORDS ) {
                $excerpt .= '...';
            }

            $excerpts[] = [
                'title'   => $post->post_title,
                'excerpt' => trim( $excerpt ),
            ];
        }

        return $excerpts;
    }

    /**
     * Detect theme selectors from rendered HTML.
     *
     * @return array{content: string, sidebar: string, header: string, footer: string, confidence: string} Detected selectors.
     */
    private function detect_theme_selectors(): array {
        $post = get_posts(
            [
                'numberposts' => 1,
                'post_type'   => 'post',
                'post_status' => 'publish',
            ]
        );

        if ( empty( $post ) ) {
            return $this->get_default_selectors();
        }

        $url      = get_permalink( $post[0]->ID );
        $response = wp_remote_get(
            $url,
            [
                'timeout'   => 10,
                'sslverify' => false,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $this->get_default_selectors();
        }

        $html = wp_remote_retrieve_body( $response );

        if ( empty( $html ) ) {
            return $this->get_default_selectors();
        }

        return $this->parse_selectors_from_html( $html );
    }

    /**
     * Parse selectors from HTML content.
     *
     * @param string $html HTML content.
     * @return array{content: string, sidebar: string, header: string, footer: string, confidence: string} Detected selectors.
     */
    private function parse_selectors_from_html( string $html ): array {
        // Suppress warnings from malformed HTML.
        libxml_use_internal_errors( true );

        $doc = new \DOMDocument();
        $doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );

        libxml_clear_errors();

        $xpath = new \DOMXPath( $doc );

        $selectors = [
            'content'    => $this->find_content_selector( $xpath ),
            'sidebar'    => $this->find_sidebar_selector( $xpath ),
            'header'     => $this->find_header_selector( $xpath ),
            'footer'     => $this->find_footer_selector( $xpath ),
            'confidence' => 'detected',
        ];

        // If none detected, use defaults.
        if ( '.entry-content' === $selectors['content'] &&
             '.sidebar' === $selectors['sidebar'] &&
             'header' === $selectors['header'] &&
             'footer' === $selectors['footer']
        ) {
            $selectors['confidence'] = 'guessed';
        }

        return $selectors;
    }

    /**
     * Find content selector from DOM.
     *
     * @param \DOMXPath $xpath XPath object.
     * @return string CSS selector.
     */
    private function find_content_selector( \DOMXPath $xpath ): string {
        $content_patterns = [
            '//*[contains(@class, "entry-content")]'  => '.entry-content',
            '//*[contains(@class, "post-content")]'   => '.post-content',
            '//article[contains(@class, "content")]'  => 'article.content',
            '//*[contains(@class, "content-area")]'   => '.content-area',
            '//main'                                  => 'main',
            '//article'                               => 'article',
        ];

        foreach ( $content_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                return $selector;
            }
        }

        return '.entry-content';
    }

    /**
     * Find sidebar selector from DOM.
     *
     * @param \DOMXPath $xpath XPath object.
     * @return string CSS selector.
     */
    private function find_sidebar_selector( \DOMXPath $xpath ): string {
        $sidebar_patterns = [
            '//*[contains(@class, "sidebar")]'       => '.sidebar',
            '//*[@id="sidebar"]'                     => '#sidebar',
            '//aside[contains(@class, "widget-area")]' => 'aside.widget-area',
            '//*[contains(@class, "widget-area")]'   => '.widget-area',
            '//aside'                                => 'aside',
        ];

        foreach ( $sidebar_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                return $selector;
            }
        }

        return '.sidebar';
    }

    /**
     * Find header selector from DOM.
     *
     * @param \DOMXPath $xpath XPath object.
     * @return string CSS selector.
     */
    private function find_header_selector( \DOMXPath $xpath ): string {
        $header_patterns = [
            '//header[contains(@class, "site-header")]' => 'header.site-header',
            '//*[@id="masthead"]'                      => '#masthead',
            '//*[contains(@class, "site-header")]'     => '.site-header',
            '//header'                                 => 'header',
        ];

        foreach ( $header_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                return $selector;
            }
        }

        return 'header';
    }

    /**
     * Find footer selector from DOM.
     *
     * @param \DOMXPath $xpath XPath object.
     * @return string CSS selector.
     */
    private function find_footer_selector( \DOMXPath $xpath ): string {
        $footer_patterns = [
            '//footer[contains(@class, "site-footer")]' => 'footer.site-footer',
            '//*[@id="colophon"]'                      => '#colophon',
            '//*[contains(@class, "site-footer")]'     => '.site-footer',
            '//footer'                                 => 'footer',
        ];

        foreach ( $footer_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                return $selector;
            }
        }

        return 'footer';
    }

    /**
     * Get default selectors as fallback.
     *
     * @return array{content: string, sidebar: string, header: string, footer: string, confidence: string} Default selectors.
     */
    private function get_default_selectors(): array {
        return [
            'content'    => '.entry-content',
            'sidebar'    => '.sidebar',
            'header'     => 'header',
            'footer'     => 'footer',
            'confidence' => 'guessed',
        ];
    }

    /**
     * Get empty analysis when no posts exist.
     *
     * @return array{
     *     post_count: int,
     *     avg_paragraphs: int,
     *     avg_words: int,
     *     heading_pattern: array<string, int>,
     *     paragraph_style: string,
     *     has_images: bool,
     *     sample_excerpts: array<mixed>,
     *     detected_selectors: array<string, string>
     * } Empty analysis with defaults.
     */
    private function get_empty_analysis(): array {
        return [
            'post_count'         => 0,
            'avg_paragraphs'     => 0,
            'avg_words'          => 0,
            'heading_pattern'    => [ 'h2' => 0, 'h3' => 0 ],
            'paragraph_style'    => 'medium',
            'has_images'         => false,
            'sample_excerpts'    => [],
            'detected_selectors' => $this->get_default_selectors(),
        ];
    }

    /**
     * Discover available page types on the site with sample URLs.
     *
     * Returns an array of page types that exist on the site,
     * each with a sample URL that can be analyzed for layout zones.
     *
     * @return array<array{type: string, url: string, label: string}> Available page types with URLs.
     */
    public function discover_page_types(): array {
        $page_types = [];

        // Homepage is always available.
        $page_types[] = [
            'type'  => 'homepage',
            'url'   => home_url( '/' ),
            'label' => __( 'Homepage', 'adshimmer' ),
        ];

        // Single post.
        $posts = get_posts(
            [
                'numberposts' => 1,
                'post_type'   => 'post',
                'post_status' => 'publish',
            ]
        );
        if ( ! empty( $posts ) ) {
            $page_types[] = [
                'type'  => 'single_post',
                'url'   => get_permalink( $posts[0]->ID ),
                'label' => __( 'Blog Post', 'adshimmer' ),
            ];
        }

        // Category archive.
        $categories = get_categories( [ 'number' => 1 ] );
        if ( ! empty( $categories ) ) {
            $page_types[] = [
                'type'  => 'category',
                'url'   => get_category_link( $categories[0]->term_id ),
                'label' => __( 'Category Archive', 'adshimmer' ),
            ];
        }

        // Static page.
        $pages = get_pages( [ 'number' => 1 ] );
        if ( ! empty( $pages ) ) {
            $page_types[] = [
                'type'  => 'page',
                'url'   => get_permalink( $pages[0]->ID ),
                'label' => __( 'Static Page', 'adshimmer' ),
            ];
        }

        // WooCommerce product (if WooCommerce is active).
        if ( class_exists( 'WooCommerce' ) ) {
            $products = get_posts(
                [
                    'numberposts' => 1,
                    'post_type'   => 'product',
                    'post_status' => 'publish',
                ]
            );
            if ( ! empty( $products ) ) {
                $page_types[] = [
                    'type'  => 'product',
                    'url'   => get_permalink( $products[0]->ID ),
                    'label' => __( 'Product Page', 'adshimmer' ),
                ];
            }

            // Shop/product archive.
            $shop_page_id = wc_get_page_id( 'shop' );
            if ( $shop_page_id > 0 ) {
                $page_types[] = [
                    'type'  => 'shop',
                    'url'   => get_permalink( $shop_page_id ),
                    'label' => __( 'Shop Page', 'adshimmer' ),
                ];
            }
        }

        // Search results (always available).
        $page_types[] = [
            'type'  => 'search',
            'url'   => add_query_arg( 's', 'test', home_url( '/' ) ),
            'label' => __( 'Search Results', 'adshimmer' ),
        ];

        return $page_types;
    }

    /**
     * Analyze layout zones for a specific page URL.
     *
     * Fetches the URL and extracts layout zone information using DOM analysis.
     *
     * @param string      $url       The URL to analyze.
     * @param string|null $page_type Optional page type hint (e.g., 'homepage', 'single_post').
     * @return array{
     *     url: string,
     *     type: string,
     *     zones: array<string, array{selector: string, height?: int, width?: int, position?: string}>,
     *     layout_type: string,
     *     has_hero: bool,
     *     has_sidebar: bool,
     *     content_blocks: int
     * } Layout analysis results.
     */
    public function analyze_page_layout( string $url, ?string $page_type = null ): array {
        $default_result = [
            'url'            => $url,
            'type'           => $page_type ?? 'unknown',
            'zones'          => [],
            'layout_type'    => 'unknown',
            'has_hero'       => false,
            'has_sidebar'    => false,
            'content_blocks' => 0,
        ];

        // Fetch the page HTML.
        $response = wp_remote_get(
            $url,
            [
                'timeout'   => 15,
                'sslverify' => false,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $default_result;
        }

        $html = wp_remote_retrieve_body( $response );

        if ( empty( $html ) ) {
            return $default_result;
        }

        return $this->parse_layout_from_html( $html, $url, $page_type );
    }

    /**
     * Parse layout zones from HTML content.
     *
     * @param string      $html      HTML content.
     * @param string      $url       Original URL.
     * @param string|null $page_type Page type hint.
     * @return array Layout analysis results.
     */
    private function parse_layout_from_html( string $html, string $url, ?string $page_type ): array {
        // Suppress warnings from malformed HTML.
        libxml_use_internal_errors( true );

        $doc = new \DOMDocument();
        $doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR );

        libxml_clear_errors();

        $xpath = new \DOMXPath( $doc );

        $zones = [];

        // Detect header.
        $header_selector = $this->find_header_selector( $xpath );
        $zones['header'] = [ 'selector' => $header_selector ];

        // Detect hero/banner section.
        $hero_selector = $this->find_hero_selector( $xpath );
        $has_hero      = ! empty( $hero_selector );
        if ( $has_hero ) {
            $zones['hero'] = [ 'selector' => $hero_selector ];
        }

        // Detect content area.
        $content_selector      = $this->find_content_selector( $xpath );
        $zones['content_area'] = [ 'selector' => $content_selector ];

        // Detect sidebar and its position.
        $sidebar_info = $this->find_sidebar_with_position( $xpath );
        $has_sidebar  = ! empty( $sidebar_info['selector'] );
        if ( $has_sidebar ) {
            $zones['sidebar'] = $sidebar_info;
        }

        // Detect footer.
        $footer_selector = $this->find_footer_selector( $xpath );
        $zones['footer'] = [ 'selector' => $footer_selector ];

        // Count content blocks (sections, articles).
        $content_blocks = $this->count_content_blocks( $xpath );

        // Determine layout type.
        $layout_type = $this->determine_layout_type( $has_sidebar, $sidebar_info['position'] ?? null );

        return [
            'url'            => $url,
            'type'           => $page_type ?? 'unknown',
            'zones'          => $zones,
            'layout_type'    => $layout_type,
            'has_hero'       => $has_hero,
            'has_sidebar'    => $has_sidebar,
            'content_blocks' => $content_blocks,
        ];
    }

    /**
     * Find hero/banner section selector.
     *
     * @param \DOMXPath $xpath XPath object.
     * @return string|null CSS selector or null if not found.
     */
    private function find_hero_selector( \DOMXPath $xpath ): ?string {
        $hero_patterns = [
            '//*[contains(@class, "hero")]'         => '.hero',
            '//*[contains(@class, "banner")]'       => '.banner',
            '//*[contains(@class, "jumbotron")]'    => '.jumbotron',
            '//*[contains(@class, "masthead")]'     => '.masthead',
            '//*[contains(@class, "intro-section")]' => '.intro-section',
            '//*[contains(@class, "featured")]'     => '.featured',
            '//*[contains(@class, "slider")]'       => '.slider',
            '//*[contains(@class, "carousel")]'     => '.carousel',
        ];

        foreach ( $hero_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                return $selector;
            }
        }

        return null;
    }

    /**
     * Find sidebar selector with position (left/right).
     *
     * @param \DOMXPath $xpath XPath object.
     * @return array{selector: string, position: string}|array{} Sidebar info or empty.
     */
    private function find_sidebar_with_position( \DOMXPath $xpath ): array {
        $sidebar_patterns = [
            '//*[contains(@class, "sidebar")]'       => '.sidebar',
            '//*[@id="sidebar"]'                     => '#sidebar',
            '//aside[contains(@class, "widget-area")]' => 'aside.widget-area',
            '//*[contains(@class, "widget-area")]'   => '.widget-area',
            '//aside'                                => 'aside',
        ];

        foreach ( $sidebar_patterns as $xpath_query => $selector ) {
            $nodes = $xpath->query( $xpath_query );
            if ( $nodes && $nodes->length > 0 ) {
                // Try to determine position from class names.
                $node     = $nodes->item( 0 );
                $class    = $node->getAttribute( 'class' );
                $position = 'right'; // Default.

                if ( strpos( $class, 'left' ) !== false ) {
                    $position = 'left';
                } elseif ( strpos( $class, 'right' ) !== false ) {
                    $position = 'right';
                }

                return [
                    'selector' => $selector,
                    'position' => $position,
                ];
            }
        }

        return [];
    }

    /**
     * Count major content blocks (sections, articles).
     *
     * @param \DOMXPath $xpath XPath object.
     * @return int Number of content blocks.
     */
    private function count_content_blocks( \DOMXPath $xpath ): int {
        $count = 0;

        // Count sections.
        $sections = $xpath->query( '//main//section | //article//section | //*[contains(@class, "content")]//section' );
        if ( $sections ) {
            $count += $sections->length;
        }

        // Count articles within main (for archive pages).
        $articles = $xpath->query( '//main//article' );
        if ( $articles ) {
            $count += $articles->length;
        }

        // If no sections/articles found, count major divs with content.
        if ( 0 === $count ) {
            $divs = $xpath->query( '//main/div | //*[contains(@class, "content-area")]/div' );
            if ( $divs ) {
                $count = min( $divs->length, 10 ); // Cap at 10.
            }
        }

        return max( 1, $count ); // At least 1 content block.
    }

    /**
     * Determine layout type from sidebar presence and position.
     *
     * @param bool        $has_sidebar Whether sidebar exists.
     * @param string|null $position    Sidebar position ('left' or 'right').
     * @return string Layout type.
     */
    private function determine_layout_type( bool $has_sidebar, ?string $position ): string {
        if ( ! $has_sidebar ) {
            return 'full-width';
        }

        if ( 'left' === $position ) {
            return 'sidebar-left';
        }

        return 'sidebar-right';
    }

    /**
     * Transient key for site layout analysis cache.
     */
    private const LAYOUT_CACHE_KEY = 'asr_site_layout_analysis';

    /**
     * Cache duration in seconds (1 hour).
     */
    private const LAYOUT_CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * Analyze all available page types on the site.
     *
     * Orchestrates full site layout analysis by:
     * 1. Discovering available page types
     * 2. Analyzing layout zones for each type
     * 3. Identifying common elements across pages
     *
     * Results are cached for 1 hour to avoid redundant HTTP requests.
     *
     * @param bool $force_refresh Whether to bypass cache and re-analyze.
     * @return array{
     *     site_info: array{name: string, theme: string, has_woocommerce: bool},
     *     page_layouts: array<string, array>,
     *     common_elements: array<string, string>,
     *     recommendations_per_type: array
     * } Complete site layout analysis.
     */
    public function analyze_all_page_types( bool $force_refresh = false ): array {
        // Check cache first unless forced refresh.
        if ( ! $force_refresh ) {
            $cached = get_transient( self::LAYOUT_CACHE_KEY );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Get site info.
        $current_theme = wp_get_theme();
        $site_info     = [
            'name'            => get_bloginfo( 'name' ),
            'theme'           => $current_theme->get( 'Name' ),
            'has_woocommerce' => class_exists( 'WooCommerce' ),
        ];

        // Discover available page types.
        $page_types = $this->discover_page_types();

        // Analyze each page type.
        $page_layouts = [];
        foreach ( $page_types as $page_type ) {
            $layout                            = $this->analyze_page_layout( $page_type['url'], $page_type['type'] );
            $layout['label']                   = $page_type['label'];
            $page_layouts[ $page_type['type'] ] = $layout;
        }

        // Identify common elements across all pages.
        $common_elements = $this->find_common_elements( $page_layouts );

        // Build result.
        $result = [
            'site_info'                => $site_info,
            'page_layouts'             => $page_layouts,
            'common_elements'          => $common_elements,
            'recommendations_per_type' => [], // Placeholder for AI to fill.
        ];

        // Cache result for 1 hour.
        set_transient( self::LAYOUT_CACHE_KEY, $result, self::LAYOUT_CACHE_DURATION );

        return $result;
    }

    /**
     * Find common elements that appear across all page layouts.
     *
     * @param array<string, array> $page_layouts Analyzed page layouts.
     * @return array<string, string> Common selectors.
     */
    private function find_common_elements( array $page_layouts ): array {
        if ( empty( $page_layouts ) ) {
            return [];
        }

        $common = [];

        // Check header selector consistency.
        $headers = [];
        foreach ( $page_layouts as $layout ) {
            if ( isset( $layout['zones']['header']['selector'] ) ) {
                $headers[] = $layout['zones']['header']['selector'];
            }
        }
        if ( ! empty( $headers ) && count( array_unique( $headers ) ) === 1 ) {
            $common['header'] = $headers[0];
        }

        // Check footer selector consistency.
        $footers = [];
        foreach ( $page_layouts as $layout ) {
            if ( isset( $layout['zones']['footer']['selector'] ) ) {
                $footers[] = $layout['zones']['footer']['selector'];
            }
        }
        if ( ! empty( $footers ) && count( array_unique( $footers ) ) === 1 ) {
            $common['footer'] = $footers[0];
        }

        // Check sidebar selector consistency.
        $sidebars = [];
        foreach ( $page_layouts as $layout ) {
            if ( isset( $layout['zones']['sidebar']['selector'] ) ) {
                $sidebars[] = $layout['zones']['sidebar']['selector'];
            }
        }
        if ( ! empty( $sidebars ) && count( array_unique( $sidebars ) ) === 1 ) {
            $common['sidebar'] = $sidebars[0];
        }

        return $common;
    }

    /**
     * Invalidate the site layout analysis cache.
     *
     * Call this when the theme is changed or site structure is modified.
     *
     * @return bool True if cache was deleted, false otherwise.
     */
    public function invalidate_layout_cache(): bool {
        return delete_transient( self::LAYOUT_CACHE_KEY );
    }
}
