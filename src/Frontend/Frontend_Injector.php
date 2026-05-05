<?php
/**
 * Frontend Injector class for server-side slot injection.
 *
 * Uses output buffering to inject ad slot divs at CSS selector positions
 * before the page is sent to the browser, eliminating CLS from JS injection.
 *
 * @package AdSpaceReserve\Frontend
 */

namespace AdSpaceReserve\Frontend;

use AdSpaceReserve\Core\Slot;
use AdSpaceReserve\Core\Slot_Collection;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects ad slot containers server-side via output buffering.
 *
 * Captures the full page HTML using ob_start() and injects slot divs
 * at the correct CSS selector positions before output. This ensures
 * slots are present in the initial HTML response, preventing CLS.
 */
class Frontend_Injector {

	/**
	 * Void elements that cannot have closing tags.
	 *
	 * @var array
	 */
	private const VOID_ELEMENTS = [
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	];

	/**
	 * Slots to inject on this page.
	 *
	 * @var Slot[]
	 */
	private array $slots;

	/**
	 * Whether output buffering has been started.
	 *
	 * @var bool
	 */
	private bool $buffer_started = false;

	/**
	 * Whether a cache plugin has been detected.
	 *
	 * @var bool
	 */
	private bool $cache_plugin_detected = false;

	/**
	 * Slot collection for loading slots.
	 *
	 * @var Slot_Collection
	 */
	private Slot_Collection $slot_collection;

	/**
	 * Construct the injector with a slot collection.
	 *
	 * @param Slot_Collection $slot_collection Slot collection instance.
	 */
	public function __construct( Slot_Collection $slot_collection ) {
		$this->slot_collection = $slot_collection;
		$this->slots           = array_values( $slot_collection->get_all() );
	}

	/**
	 * Register WordPress hooks for output buffering.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'start_buffer' ], 1 );
		add_action( 'shutdown', [ $this, 'end_buffer' ], PHP_INT_MAX );
		add_action( 'admin_notices', [ $this, 'cache_plugin_compatibility_notice' ] );
		$this->check_cache_plugin_compatibility();
	}

	/**
	 * Check for cache plugin compatibility issues.
	 *
	 * @return void
	 */
	private function check_cache_plugin_compatibility(): void {
		$cache_plugins = [
			'wp-rocket/wp-rocket.php'            => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		];

		foreach ( $cache_plugins as $plugin => $name ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) ) {
				$this->cache_plugin_detected = true;
				break;
			}
		}
	}

	/**
	 * Display cache plugin compatibility notice in admin.
	 *
	 * @return void
	 */
	public function cache_plugin_compatibility_notice(): void {
		if ( ! $this->cache_plugin_detected || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo '<strong>AdShimmer:</strong> ';
		esc_html_e( 'A caching plugin has been detected. Please ensure page caching is configured to work with output buffering.', 'ad-space-reserve' );
		echo '</p></div>';
	}

	/**
	 * Start output buffering if conditions are met.
	 *
	 * Only buffers on frontend page requests with configured slots.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		// Skip if already started.
		if ( $this->buffer_started ) {
			return;
		}

		// Skip in admin context.
		if ( is_admin() ) {
			return;
		}

		// Skip AJAX requests.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Skip REST API requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Skip WP-CLI context.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Skip if no slots configured.
		if ( empty( $this->slots ) ) {
			return;
		}

		// Skip feed requests.
		if ( is_feed() ) {
			return;
		}

		// Skip robots.txt requests.
		if ( is_robots() ) {
			return;
		}

		// Start output buffering with our processing callback.
		ob_start( [ $this, 'process_html' ] );
		$this->buffer_started = true;
	}

	/**
	 * End output buffering and flush content.
	 *
	 * @return void
	 */
	public function end_buffer(): void {
		if ( $this->buffer_started && ob_get_level() > 0 ) {
			ob_end_flush();
			$this->buffer_started = false;
		}
	}

	/**
	 * Process the captured HTML and inject slots.
	 *
	 * This is the main injection method called by ob_start.
	 *
	 * @param string $html The captured page HTML.
	 * @return string Modified HTML with slots injected.
	 */
	public function process_html( string $html ): string {
		try {
			$max_html_size = apply_filters( 'asr_max_html_size', 5 * 1024 * 1024 );
			if ( strlen( $html ) > $max_html_size ) {
				return $html;
			}

			if ( strlen( $html ) < 100 ) {
				return $html;
			}

			if ( strpos( $html, '<html' ) === false && strpos( $html, '<!DOCTYPE' ) === false ) {
				return $html;
			}

				// Inject all device variants for cache compatibility, but respect per-slot path rules.
				$slots_to_inject = array_values(
					array_filter(
						$this->slots,
						fn( Slot $slot ) => $this->slot_matches_current_path( $slot )
					)
				);

			if ( empty( $slots_to_inject ) ) {
				return $html;
			}

			$position_slots = array_filter( $slots_to_inject, fn( Slot $s ) => $s->get_position() > 0 );
			$selector_slots = array_filter( $slots_to_inject, fn( Slot $s ) => $s->get_position() === 0 );

				usort( $position_slots, fn( Slot $a, Slot $b ) => $b->get_position() - $a->get_position() );
				foreach ( $position_slots as $slot ) {
					try {
						$html = $this->inject_at_paragraph_position( $html, $slot );
					} catch ( \Exception $e ) {
						error_log( 'AdShimmer: Failed to inject slot at paragraph position ' . $slot->get_position() . ': ' . $e->getMessage() );
					}
				}

			$slots_by_selector = [];
			foreach ( $selector_slots as $slot ) {
				$selector = $slot->get_selector();
				if ( ! isset( $slots_by_selector[ $selector ] ) ) {
					$slots_by_selector[ $selector ] = [];
				}
				$slots_by_selector[ $selector ][] = $slot;
			}

			foreach ( $slots_by_selector as $selector => $grouped_slots ) {
				foreach ( $grouped_slots as $slot ) {
					try {
						$html = $this->inject_slot( $html, $slot );
					} catch ( \Exception $e ) {
						error_log( 'AdShimmer: Failed to inject slot with selector "' . $selector . '": ' . $e->getMessage() );
					}
				}
			}

			return $html;

		} catch ( \Exception $e ) {
			error_log( 'AdShimmer: Critical error in process_html(): ' . $e->getMessage() );
			return $html;
		}
	}

	/**
	 * Check whether a slot is allowed on the current request path.
	 *
	 * Empty target paths mean site-wide. Rules can be newline or comma separated,
	 * may be full URLs or paths, and support * as a wildcard.
	 *
	 * @param Slot $slot Slot to check.
	 * @return bool True if the slot may be injected.
	 */
	private function slot_matches_current_path( Slot $slot ): bool {
		$rules_raw = trim( $slot->get_target_paths() );
		if ( '' === $rules_raw ) {
			return true;
		}

		$request_path = $this->get_current_request_path();
		$rules        = preg_split( '/[\r\n,]+/', $rules_raw );

		foreach ( $rules as $rule ) {
			if ( $this->path_rule_matches( $request_path, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the current request path without query string.
	 *
	 * @return string Normalized request path.
	 */
	private function get_current_request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		if ( ! is_string( $uri ) || '' === $uri ) {
			return '/';
		}

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return $this->normalize_path( $path );
	}

	/**
	 * Match one path rule against a request path.
	 *
	 * @param string $request_path Normalized request path.
	 * @param string $rule         Raw path rule.
	 * @return bool True on match.
	 */
	private function path_rule_matches( string $request_path, string $rule ): bool {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			return false;
		}

		$path_from_url = wp_parse_url( $rule, PHP_URL_PATH );
		if ( is_string( $path_from_url ) && '' !== $path_from_url ) {
			$rule = $path_from_url;
		}

		if ( false === strpos( $rule, '*' ) ) {
			return $request_path === $this->normalize_path( $rule );
		}

		$rule    = $this->ensure_leading_slash( $rule );
		$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#';

		return (bool) preg_match( $pattern, $request_path );
	}

	/**
	 * Normalize a path for exact matching.
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path.
	 */
	private function normalize_path( string $path ): string {
		$path = $this->ensure_leading_slash( $path );
		$path = preg_replace( '#/+#', '/', $path );

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Ensure a path starts with a slash.
	 *
	 * @param string $path Raw path.
	 * @return string Path with a leading slash.
	 */
	private function ensure_leading_slash( string $path ): string {
		$path = trim( $path );
		return '/' === substr( $path, 0, 1 ) ? $path : '/' . $path;
	}

	/**
	 * Inject a slot before/after the Nth paragraph inside the configured selector.
	 *
	 * Position-based slots must never fall back to the whole document, because that can
	 * place ad containers in headers, sidebars, or footers when those areas contain <p> tags.
	 *
	 * @param string $html The HTML to modify.
	 * @param Slot   $slot The slot with position > 0.
	 * @return string Modified HTML with slot injected within the target selector.
	 */
	private function inject_at_paragraph_position( string $html, Slot $slot ): string {
		$target_matches = $this->find_selector_matches( $html, $slot );
		if ( empty( $target_matches ) ) {
			return $html;
		}

		$position         = max( 1, $slot->get_position() );
		$injection_points = [];

		foreach ( $target_matches as $index => $match ) {
			$matched_tag     = $match[0];
			$offset          = $match[1];
			$content_start   = $offset + strlen( $matched_tag );
			$content_end     = $this->find_closing_tag_position( $html, $matched_tag, $offset );
			$content_length  = $content_end - $content_start;

			if ( $content_length <= 0 ) {
				continue;
			}

			$segment          = substr( $html, $content_start, $content_length );
			$paragraph_points = $this->find_paragraph_injection_offsets( $segment, $content_start, $slot->get_placement() );

			if ( empty( $paragraph_points ) ) {
				continue;
			}

			$target_index  = min( $position, count( $paragraph_points ) ) - 1;
			$target_offset = $paragraph_points[ $target_index ];

			if ( $this->is_inside_protected_element( $html, $target_offset ) ) {
				continue;
			}

			$injection_points[] = [
				'offset' => $target_offset,
				'html'   => $this->build_slot_html( $slot, $index ),
			];
		}

		usort( $injection_points, fn( $a, $b ) => $b['offset'] - $a['offset'] );

		foreach ( $injection_points as $point ) {
			$html = substr_replace( $html, $point['html'], $point['offset'], 0 );
		}

		return $html;
	}

	/**
	 * Find paragraph-relative insertion offsets within a target container.
	 *
	 * @param string $segment     HTML inside the matched target element.
	 * @param int    $base_offset Offset of the segment within full HTML.
	 * @param string $placement   Slot placement; "before" means before paragraph N, otherwise after.
	 * @return int[] Absolute offsets in the full HTML string.
	 */
	private function find_paragraph_injection_offsets( string $segment, int $base_offset, string $placement ): array {
		$pattern = 'before' === $placement ? '/<p\b[^>]*>/i' : '/<\/p\s*>/i';

		if ( ! preg_match_all( $pattern, $segment, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$offsets = [];
		foreach ( $matches[0] as $match ) {
			$offsets[] = $base_offset + $match[1] + ( 'before' === $placement ? 0 : strlen( $match[0] ) );
		}

		return $offsets;
	}

	/**
	 * Find selector matches for a slot, including nth filters, ancestor checks, and protected elements.
	 *
	 * @param string $html The HTML to search.
	 * @param Slot   $slot The slot to match.
	 * @return array Matched opening tags from preg_match_all with offsets.
	 */
	private function find_selector_matches( string $html, Slot $slot ): array {
		$selector = $slot->get_selector();

		$nth_pattern  = $this->extract_nth_pattern( $selector );
		$base_selector = $this->strip_nth_pattern( $selector );
		$pattern       = $this->selector_to_pattern( $base_selector );

		if ( empty( $pattern ) ) {
			return [];
		}

		if ( preg_match( '/^body[.:]/', $selector ) && ! $this->check_body_class_condition( $selector ) ) {
			return [];
		}

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$target_matches = $matches[0];
		$ancestor_chain = $this->extract_ancestor_chain( $base_selector );

		if ( ! empty( $ancestor_chain ) ) {
			$target_matches = array_values(
				array_filter(
					$target_matches,
					fn( $match ) => $this->validate_ancestor_chain( $html, $match[1], $ancestor_chain )
				)
			);
		}

		if ( ! empty( $nth_pattern ) ) {
			$target_matches = $this->filter_by_nth_pattern( $target_matches, $nth_pattern );
		}

		$target_matches = array_values(
			array_filter(
				$target_matches,
				fn( $match ) => ! $this->is_inside_protected_element( $html, $match[1] )
			)
		);

		if ( 'first' === $slot->get_selector_mode() && count( $target_matches ) > 1 ) {
			$target_matches = [ $target_matches[0] ];
		}

		return $target_matches;
	}

	/**
	 * Inject a single slot into the HTML.
	 *
	 * @param string $html The HTML to modify.
	 * @param Slot   $slot The slot to inject.
	 * @return string Modified HTML with slot injected.
	 */
	private function inject_slot( string $html, Slot $slot ): string {
		$target_matches = $this->find_selector_matches( $html, $slot );
		if ( empty( $target_matches ) ) {
			return $html;
		}

		// Collect injection points for single-pass injection.
		$injection_points = [];
		foreach ( $target_matches as $index => $match ) {
			$matched_tag = $match[0];
			$offset      = $match[1];

			$slot_html      = $this->build_slot_html( $slot, $index );
			$inject_offset  = $this->calculate_injection_offset( $html, $matched_tag, $offset, $slot->get_placement() );

			$injection_points[] = [
				'offset' => $inject_offset,
				'html'   => $slot_html,
			];
		}

		// Sort by offset descending to inject from end to start.
		usort( $injection_points, fn( $a, $b ) => $b['offset'] - $a['offset'] );

		foreach ( $injection_points as $point ) {
			$html = substr_replace( $html, $point['html'], $point['offset'], 0 );
		}

		return $html;
	}

	/**
	 * Calculate the injection offset for a slot.
	 *
	 * @param string $html        The full page HTML.
	 * @param string $matched_tag The matched opening tag.
	 * @param int    $offset      Position of the matched tag in HTML.
	 * @param string $placement   Placement mode (before|after|inside).
	 * @return int Injection offset.
	 */
	private function calculate_injection_offset( string $html, string $matched_tag, int $offset, string $placement ): int {
		$tag_name = '';
		if ( preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)/', $matched_tag, $tag_match ) ) {
			$tag_name = strtolower( $tag_match[1] );
		}

		$is_void = in_array( $tag_name, self::VOID_ELEMENTS, true )
			|| substr( trim( $matched_tag ), -2 ) === '/>';

		switch ( $placement ) {
			case 'before':
				return $offset;

			case 'inside':
				return $offset + strlen( $matched_tag );

			case 'after':
			default:
				if ( $is_void ) {
					return $offset + strlen( $matched_tag );
				}
				return $this->find_closing_tag_position( $html, $matched_tag, $offset );
		}
	}

	/**
	 * Extract nth pattern from a CSS selector.
	 *
	 * Supports :nth-of-type(), :nth-child(), :first-of-type, :last-of-type.
	 * Normalizes by removing whitespace: ":nth-of-type( 2n + 2 )" -> "2n+2".
	 *
	 * @param string $selector CSS selector.
	 * @return string Normalized nth pattern (e.g., "2n+2", "odd", "3") or empty string.
	 */
	private function extract_nth_pattern( string $selector ): string {
		// Match :nth-of-type(...) or :nth-child(...) with flexible whitespace.
		if ( preg_match( '/:nth-(?:of-type|child)\s*\(\s*([^)]+)\s*\)/i', $selector, $match ) ) {
			// Normalize: remove all whitespace from the formula.
			return preg_replace( '/\s+/', '', $match[1] );
		}

		// Match :first-of-type or :first-child.
		if ( preg_match( '/:first-(?:of-type|child)\b/i', $selector ) ) {
			return '1';
		}

		// Match :last-of-type or :last-child.
		if ( preg_match( '/:last-(?:of-type|child)\b/i', $selector ) ) {
			return 'last';
		}

		return '';
	}

	/**
	 * Strip nth pattern from a CSS selector.
	 *
	 * @param string $selector CSS selector.
	 * @return string Selector without nth pseudo-selector.
	 */
	private function strip_nth_pattern( string $selector ): string {
		// Remove :nth-of-type(...) or :nth-child(...).
		$selector = preg_replace( '/:nth-(?:of-type|child)\s*\([^)]+\)/i', '', $selector );

		// Remove :first-of-type, :first-child, :last-of-type, :last-child.
		$selector = preg_replace( '/:(?:first|last)-(?:of-type|child)\b/i', '', $selector );

		return trim( $selector );
	}

	/**
	 * Filter matches by nth pattern.
	 *
	 * @param array  $matches     Array of matches from preg_match_all.
	 * @param string $nth_pattern Normalized nth pattern (e.g., "2n+2", "odd", "3", "last").
	 * @return array Filtered matches.
	 */
	private function filter_by_nth_pattern( array $matches, string $nth_pattern ): array {
		$total   = count( $matches );
		$filtered = [];

		foreach ( $matches as $index => $match ) {
			// CSS uses 1-based indexing.
			$position = $index + 1;

			if ( $this->matches_nth_formula( $position, $nth_pattern, $total ) ) {
				$filtered[] = $match;
			}
		}

		return $filtered;
	}

	/**
	 * Check if a position matches an nth formula.
	 *
	 * Supports:
	 * - Single number: "3" -> only 3rd element
	 * - Keywords: "odd" (1,3,5...), "even" (2,4,6...), "last"
	 * - Formula: "2n" (2,4,6...), "2n+1" (1,3,5...), "3n+2" (2,5,8...)
	 * - Negative offset: "2n-1" treated as "2n+1" (CSS behavior)
	 *
	 * @param int    $position    1-based position of the element.
	 * @param string $formula     The nth formula (normalized, no whitespace).
	 * @param int    $total       Total number of matched elements.
	 * @return bool True if position matches the formula.
	 */
	private function matches_nth_formula( int $position, string $formula, int $total ): bool {
		// Handle keywords.
		$formula_lower = strtolower( $formula );

		if ( 'odd' === $formula_lower ) {
			return 1 === $position % 2;
		}

		if ( 'even' === $formula_lower ) {
			return 0 === $position % 2;
		}

		if ( 'last' === $formula_lower ) {
			return $position === $total;
		}

		// Handle single number: "3" -> only position 3.
		if ( preg_match( '/^(\d+)$/', $formula, $match ) ) {
			return $position === (int) $match[1];
		}

		// Handle "An+B" or "An-B" formula.
		// Examples: 2n, 2n+1, 2n-1, 3n+2, n+3.
		if ( preg_match( '/^(-?\d*)n([+-]?\d+)?$/', $formula, $match ) ) {
			// Parse A (multiplier). Empty or just "n" means A=1, "-n" means A=-1.
			$a = $match[1];
			if ( '' === $a || '+' === $a ) {
				$a = 1;
			} elseif ( '-' === $a ) {
				$a = -1;
			} else {
				$a = (int) $a;
			}

			// Parse B (offset). Empty means B=0.
			$b = isset( $match[2] ) && '' !== $match[2] ? (int) $match[2] : 0;

			// For A=0, position must equal B exactly.
			if ( 0 === $a ) {
				return $position === $b;
			}

			// Check if position = A*k + B for some non-negative integer k.
			// Rearranged: k = (position - B) / A, must be >= 0 and integer.
			$diff = $position - $b;

			// For positive A: diff must be non-negative and divisible by A.
			if ( $a > 0 ) {
				return $diff >= 0 && 0 === $diff % $a;
			}

			// For negative A: diff must be non-positive and divisible by A.
			return $diff <= 0 && 0 === $diff % $a;
		}

		// Handle "-n+B" (matches positions 1 to B).
		if ( preg_match( '/^-n\+(\d+)$/', $formula, $match ) ) {
			$b = (int) $match[1];
			return $position <= $b;
		}

		// Fallback: no match (invalid formula).
		return false;
	}

	/**
	 * Convert a CSS selector to a regex pattern.
	 *
	 * @param string $selector CSS selector.
	 * @return string Regex pattern or empty string if invalid.
	 */
	private function selector_to_pattern( string $selector ): string {
		$selector = trim( $selector );

		if ( empty( $selector ) ) {
			return '';
		}

		// Handle body class conditions: extract final element.
		// e.g., "body.wp-singular h2" -> match h2, but check body class separately.
		if ( preg_match( '/^body(?:\.[a-zA-Z0-9_-]+|:not\(\.[a-zA-Z0-9_-]+\))\s+(.+)$/', $selector, $body_match ) ) {
			$selector = $body_match[1];
		}

		// Handle compound selectors: extract the final element.
		// e.g., ".page-content > h2" -> h2, ".sidebar .widget" -> .widget.
		$parts = preg_split( '/\s+/', $selector );
		if ( count( $parts ) > 1 ) {
			// Get last non-combinator part.
			$selector = end( $parts );
			// Skip combinators.
			if ( in_array( $selector, [ '>', '+', '~' ], true ) ) {
				$selector = prev( $parts ) ?: $selector;
			}
		}

		// Handle child combinator in last part: ".foo > h2" -> "h2".
		if ( strpos( $selector, '>' ) !== false ) {
			$combinator_parts = explode( '>', $selector );
			$selector = trim( end( $combinator_parts ) );
		}

		$selector = trim( $selector );

		// Extract target element components.
		$target = $this->extract_target_element( $selector );

		if ( empty( $target ) ) {
			return '';
		}

		return $this->build_target_pattern( $target );
	}

	/**
	 * Extract target element components from a CSS selector.
	 *
	 * @param string $selector Simple selector (no combinators).
	 * @return array Target element info: ['tag', 'id', 'classes', 'attributes'].
	 */
	private function extract_target_element( string $selector ): array {
		$target = [
			'tag'              => null,
			'id'               => null,
			'classes'          => [],
			'excluded_classes' => [],
			'attributes'       => [],
		];

		// Extract :not(.class) pseudo-selectors before normal class parsing.
		if ( preg_match_all( '/:not\(\.([a-zA-Z0-9_-]+)\)/', $selector, $not_matches ) ) {
			$target['excluded_classes'] = $not_matches[1];
			$selector = preg_replace( '/:not\([^)]+\)/', '', $selector );
		}

		// Extract attribute selectors first: [attr="value"] or [attr].
		if ( preg_match_all( '/\[([a-zA-Z_-]+)(?:=(["\']?)([^"\'\]]*)\2)?\]/', $selector, $attr_matches, PREG_SET_ORDER ) ) {
			foreach ( $attr_matches as $attr_match ) {
				$target['attributes'][] = [
					'name'  => $attr_match[1],
					'value' => $attr_match[3] ?? null,
				];
			}
			// Remove attribute parts from selector for further parsing.
			$selector = preg_replace( '/\[[^\]]+\]/', '', $selector );
		}

		// ID selector: #foo or tag#foo.
		if ( preg_match( '/#([a-zA-Z0-9_-]+)/', $selector, $id_match ) ) {
			$target['id'] = $id_match[1];
			$selector = str_replace( '#' . $id_match[1], '', $selector );
		}

		// Class selectors: .foo.bar.
		if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $selector, $class_matches ) ) {
			$target['classes'] = $class_matches[1];
			$selector = preg_replace( '/\.[a-zA-Z0-9_-]+/', '', $selector );
		}

		// Tag name: what remains should be a tag.
		$selector = trim( $selector );
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)/', $selector, $tag_match ) ) {
			$target['tag'] = strtolower( $tag_match[1] );
		}

		// Must have at least one identifier.
		if ( empty( $target['tag'] ) && empty( $target['id'] ) && empty( $target['classes'] ) && empty( $target['attributes'] ) ) {
			return [];
		}

		return $target;
	}

	/**
	 * Build regex pattern from target element components.
	 *
	 * @param array $target Target element info.
	 * @return string Regex pattern.
	 */
	private function build_target_pattern( array $target ): string {
		$pattern_parts = [];

		// Start with tag or any tag.
		if ( ! empty( $target['tag'] ) ) {
			$tag = preg_quote( $target['tag'], '/' );
			$pattern_parts[] = '<' . $tag . '\b';
		} else {
			$pattern_parts[] = '<[a-zA-Z][a-zA-Z0-9]*\b';
		}

		// Build attribute conditions.
		$attr_conditions = [];

		// ID condition.
		if ( ! empty( $target['id'] ) ) {
			$id = preg_quote( $target['id'], '/' );
			$attr_conditions[] = '(?=[^>]*\bid=["\']?' . $id . '["\']?)';
		}

		// Class conditions - all classes must be present.
		foreach ( $target['classes'] as $class ) {
			$class = preg_quote( $class, '/' );
			$attr_conditions[] = '(?=[^>]*\bclass=["\'][^"\']*\b' . $class . '\b[^"\']*["\'])';
		}

		// Excluded class conditions - :not(.class) must NOT be present.
		if ( ! empty( $target['excluded_classes'] ) ) {
			foreach ( $target['excluded_classes'] as $class ) {
				$class = preg_quote( $class, '/' );
				$attr_conditions[] = '(?![^>]*\bclass=["\'][^"\']*\b' . $class . '\b)';
			}
		}

		// Attribute conditions.
		foreach ( $target['attributes'] as $attr ) {
			$attr_name = preg_quote( $attr['name'], '/' );
			if ( null !== $attr['value'] ) {
				$attr_value = preg_quote( $attr['value'], '/' );
				$attr_conditions[] = '(?=[^>]*\b' . $attr_name . '=["\']?' . $attr_value . '["\']?)';
			} else {
				$attr_conditions[] = '(?=[^>]*\b' . $attr_name . '\b)';
			}
		}

		// Combine conditions with lookaheads.
		if ( ! empty( $attr_conditions ) ) {
			$pattern_parts[] = implode( '', $attr_conditions );
		}

		// Match rest of opening tag.
		$pattern_parts[] = '[^>]*>';

		return '/' . implode( '', $pattern_parts ) . '/i';
	}

	/**
	 * Extract the full ancestor chain from a compound CSS selector.
	 *
	 * Returns an array of ancestors with their combinators, ordered
	 * innermost-first (nearest ancestor to target first).
	 *
	 * Example: "article > div > div > h3" returns:
	 *   [
	 *     ['selector' => 'div',     'combinator' => '>'],
	 *     ['selector' => 'div',     'combinator' => '>'],
	 *     ['selector' => 'article', 'combinator' => '>'],
	 *   ]
	 *
	 * Example: ".entry-content h2" returns:
	 *   [
	 *     ['selector' => '.entry-content', 'combinator' => ' '],
	 *   ]
	 *
	 * @param string $selector Base selector (may include body prefix).
	 * @return array Array of ['selector' => string, 'combinator' => string], innermost-first.
	 */
	private function extract_ancestor_chain( string $selector ): array {
		// Strip body class prefix if present (same logic as selector_to_pattern).
		if ( preg_match( '/^body(?:\.[a-zA-Z0-9_-]+|:not\(\.[a-zA-Z0-9_-]+\))\s+(.+)$/', $selector, $body_match ) ) {
			$selector = $body_match[1];
		}

		$selector = trim( $selector );

		// Tokenize the selector, preserving combinators.
		// Walk character by character to split into selector tokens and combinators.
		$tokens = []; // Array of ['type' => 'selector'|'combinator', 'value' => string].
		$current = '';
		$len     = strlen( $selector );
		$i       = 0;

		while ( $i < $len ) {
			$char = $selector[ $i ];

			if ( in_array( $char, [ '>', '+', '~' ], true ) ) {
				// Flush current selector token.
				$current = trim( $current );
				if ( '' !== $current ) {
					$tokens[] = [ 'type' => 'selector', 'value' => $current ];
				}
				$current = '';
				// Add combinator token.
				$tokens[] = [ 'type' => 'combinator', 'value' => $char ];
				$i++;
			} elseif ( ' ' === $char || "\t" === $char ) {
				// Whitespace: could be a descendant combinator or just spacing around a combinator.
				// Skip all consecutive whitespace.
				$current_trimmed = trim( $current );
				while ( $i < $len && ( ' ' === $selector[ $i ] || "\t" === $selector[ $i ] ) ) {
					$i++;
				}
				// Check if next non-space char is a combinator.
				if ( $i < $len && in_array( $selector[ $i ], [ '>', '+', '~' ], true ) ) {
					// Whitespace before a combinator — flush selector, let combinator handle itself.
					if ( '' !== $current_trimmed ) {
						$tokens[] = [ 'type' => 'selector', 'value' => $current_trimmed ];
					}
					$current = '';
				} else {
					// Whitespace IS the descendant combinator.
					if ( '' !== $current_trimmed ) {
						$tokens[] = [ 'type' => 'selector', 'value' => $current_trimmed ];
						$tokens[] = [ 'type' => 'combinator', 'value' => ' ' ];
					}
					$current = '';
				}
			} else {
				$current .= $char;
				$i++;
			}
		}

		// Flush remaining token.
		$current = trim( $current );
		if ( '' !== $current ) {
			$tokens[] = [ 'type' => 'selector', 'value' => $current ];
		}

		// Extract only selector tokens.
		$selectors   = [];
		$combinators = [];
		foreach ( $tokens as $token ) {
			if ( 'selector' === $token['type'] ) {
				$selectors[] = $token['value'];
			} elseif ( 'combinator' === $token['type'] ) {
				$combinators[] = $token['value'];
			}
		}

		// Need at least 2 selectors for an ancestor relationship.
		if ( count( $selectors ) < 2 ) {
			return [];
		}

		// Remove the last selector (the target element).
		array_pop( $selectors );

		// Build the ancestor chain innermost-first.
		// The combinator at index N sits between selector N and selector N+1.
		// For ancestor at index K, its combinator is combinators[K] (how it connects to its child).
		$chain = [];
		$last_idx = count( $selectors ) - 1;
		for ( $k = $last_idx; $k >= 0; $k-- ) {
			$chain[] = [
				'selector'   => $selectors[ $k ],
				'combinator' => isset( $combinators[ $k ] ) ? $combinators[ $k ] : ' ',
			];
		}

		return $chain;
	}

	/**
	 * Find the direct parent element of a tag at a given offset.
	 *
	 * Walks backward through the HTML tracking open/close tag depth
	 * to locate the immediately enclosing parent element.
	 *
	 * @param string $html          The full page HTML.
	 * @param int    $target_offset Offset of the target element.
	 * @return array|null Array with 'tag', 'offset', 'tag_name' keys, or null.
	 */
	private function find_direct_parent( string $html, int $target_offset ): ?array {
		// Get the HTML before the target offset.
		$html_before = substr( $html, 0, $target_offset );

		// Find all tags (opening and closing) in the HTML before target.
		if ( ! preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $html_before, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return null;
		}

		// Walk BACKWARD through found tags.
		$depth = 0;
		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$full_tag  = $matches[ $i ][0][0];
			$tag_offset = $matches[ $i ][0][1];
			$tag_name  = strtolower( $matches[ $i ][1][0] );

			// Skip void elements.
			if ( in_array( $tag_name, self::VOID_ELEMENTS, true ) ) {
				continue;
			}

			// Skip self-closing tags.
			if ( substr( rtrim( $full_tag, '>' ), -1 ) === '/' ) {
				continue;
			}

			// Check if closing tag.
			if ( strpos( $full_tag, '</' ) === 0 ) {
				$depth++;
			} else {
				// Opening tag.
				if ( 0 === $depth ) {
					// This is the direct parent.
					return [
						'tag'      => $full_tag,
						'offset'   => $tag_offset,
						'tag_name' => $tag_name,
					];
				}
				$depth--;
			}
		}

		return null;
	}

	/**
	 * Check if an HTML opening tag matches a CSS selector.
	 *
	 * @param string $tag_html The full opening tag string (e.g., '<div class="foo">').
	 * @param string $selector Simple CSS selector (no combinators).
	 * @return bool True if the tag matches the selector.
	 */
	private function element_matches_selector( string $tag_html, string $selector ): bool {
		$target = $this->extract_target_element( $selector );
		if ( empty( $target ) ) {
			return false;
		}

		$pattern = $this->build_target_pattern( $target );

		return (bool) preg_match( $pattern, $tag_html );
	}

	/**
	 * Validate a full ancestor chain for a target element.
	 *
	 * Walks the ancestor chain innermost-first, checking each ancestor
	 * using the appropriate combinator (direct parent for '>', any
	 * ancestor for ' ').
	 *
	 * @param string $html           The full page HTML.
	 * @param int    $target_offset  Offset of the target element.
	 * @param array  $ancestor_chain Ancestor chain from extract_ancestor_chain().
	 * @return bool True if the full ancestor chain is satisfied.
	 */
	private function validate_ancestor_chain( string $html, int $target_offset, array $ancestor_chain ): bool {
		$current_offset = $target_offset;

		foreach ( $ancestor_chain as $entry ) {
			$combinator = $entry['combinator'];
			$selector   = $entry['selector'];

			if ( '>' === $combinator ) {
				// Direct parent combinator: find immediate parent.
				$parent = $this->find_direct_parent( $html, $current_offset );
				if ( null === $parent ) {
					return false;
				}

				if ( ! $this->element_matches_selector( $parent['tag'], $selector ) ) {
					return false;
				}

				$current_offset = $parent['offset'];
			} else {
				// Descendant combinator: any ancestor.
				$ancestor_target = $this->extract_target_element( $selector );
				if ( empty( $ancestor_target ) ) {
					return true; // Can't parse ancestor — don't filter.
				}

				$ancestor_pattern = $this->build_target_pattern( $ancestor_target );
				if ( empty( $ancestor_pattern ) ) {
					return true;
				}

				// Search for all matching ancestors in HTML before current offset.
				$html_before = substr( $html, 0, $current_offset );
				if ( ! preg_match_all( $ancestor_pattern, $html_before, $ancestor_matches, PREG_OFFSET_CAPTURE ) ) {
					return false;
				}

				// Check from nearest backward: find one whose closing tag is after current offset.
				$found = false;
				$candidates = array_reverse( $ancestor_matches[0] );
				foreach ( $candidates as $ancestor_match ) {
					$ancestor_tag    = $ancestor_match[0];
					$ancestor_offset = $ancestor_match[1];

					$ancestor_close = $this->find_closing_tag_position( $html, $ancestor_tag, $ancestor_offset );

					if ( $ancestor_close > $current_offset ) {
						$current_offset = $ancestor_offset;
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check if the target offset is inside a protected semantic element.
	 *
	 * Prevents ad injection inside elements like blockquote, figure, table,
	 * nav, or aside where ads would break content structure.
	 *
	 * @param string $html          The full HTML string.
	 * @param int    $target_offset The byte offset of the target element.
	 * @return bool True if inside a protected element.
	 */
	private function is_inside_protected_element( string $html, int $target_offset ): bool {
		$protected_tags = [ 'blockquote', 'figure', 'table', 'script', 'style', 'noscript', 'template', 'textarea', 'select', 'svg' ];

		foreach ( $protected_tags as $tag ) {
			$pattern    = '/<' . $tag . '\b[^>]*>/i';
			$html_before = substr( $html, 0, $target_offset );

			if ( preg_match_all( $pattern, $html_before, $matches, PREG_OFFSET_CAPTURE ) ) {
				// Check nearest match (last one before target).
				$candidates = array_reverse( $matches[0] );
				foreach ( $candidates as $match ) {
					$close_pos = $this->find_closing_tag_position( $html, $match[0], $match[1] );
					if ( $close_pos > $target_offset ) {
						return true;
					}
					break; // Only check the nearest one.
				}
			}
		}

		return false;
	}

	/**
	 * Check if body class condition is met.
	 *
	 * @param string $selector Selector containing body.class condition.
	 * @return bool True if condition is met.
	 */
	private function check_body_class_condition( string $selector ): bool {
		$body_classes = get_body_class();

		// Negated: body:not(.classname)
		if ( preg_match( '/^body:not\(\.([a-zA-Z0-9_-]+)\)/', $selector, $match ) ) {
			return ! in_array( $match[1], $body_classes, true );
		}

		// Positive: body.classname (existing behavior)
		if ( ! preg_match( '/^body\.([a-zA-Z0-9_-]+)/', $selector, $match ) ) {
			return true; // No body class condition.
		}

		return in_array( $match[1], $body_classes, true );
	}

	/**
	 * Inject slot HTML at the specified position.
	 *
	 * @param string $html        The full page HTML.
	 * @param string $slot_html   The slot HTML to inject.
	 * @param string $matched_tag The matched opening tag.
	 * @param int    $offset      Position of the matched tag in HTML.
	 * @param string $placement   Placement mode (before|after|inside).
	 * @return string Modified HTML.
	 */
	private function inject_at_position( string $html, string $slot_html, string $matched_tag, int $offset, string $placement ): string {
		// Extract tag name for void element check.
		$tag_name = '';
		if ( preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)/', $matched_tag, $tag_match ) ) {
			$tag_name = strtolower( $tag_match[1] );
		}

		$is_void = in_array( $tag_name, self::VOID_ELEMENTS, true )
			|| substr( trim( $matched_tag ), -2 ) === '/>';

		switch ( $placement ) {
			case 'before':
				// Insert before the matched tag.
				return substr( $html, 0, $offset ) . $slot_html . substr( $html, $offset );

			case 'inside':
				// Void elements can't have children - fall back to 'after'.
				if ( $is_void ) {
					$insert_pos = $offset + strlen( $matched_tag );
					return substr( $html, 0, $insert_pos ) . $slot_html . substr( $html, $insert_pos );
				}
				// Insert after the opening tag (as first child).
				$insert_pos = $offset + strlen( $matched_tag );
				return substr( $html, 0, $insert_pos ) . $slot_html . substr( $html, $insert_pos );

			case 'after':
			default:
				// For void elements, insert directly after the tag.
				if ( $is_void ) {
					$insert_pos = $offset + strlen( $matched_tag );
					return substr( $html, 0, $insert_pos ) . $slot_html . substr( $html, $insert_pos );
				}
				// Find the closing tag and insert after it.
				$tag_end = $this->find_closing_tag_position( $html, $matched_tag, $offset );
				return substr( $html, 0, $tag_end ) . $slot_html . substr( $html, $tag_end );
		}
	}

	/**
	 * Find the position after the closing tag of an element.
	 *
	 * @param string $html        The full page HTML.
	 * @param string $matched_tag The opening tag that was matched.
	 * @param int    $offset      Position of the opening tag.
	 * @return int Position after the closing tag.
	 */
	private function find_closing_tag_position( string $html, string $matched_tag, int $offset ): int {
		// Extract tag name from opening tag.
		if ( ! preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)/', $matched_tag, $tag_match ) ) {
			// Fallback: insert after opening tag.
			return $offset + strlen( $matched_tag );
		}

		$tag_name = strtolower( $tag_match[1] );

		// Void elements: insert after the tag itself (no closing tag).
		if ( in_array( $tag_name, self::VOID_ELEMENTS, true ) || substr( trim( $matched_tag ), -2 ) === '/>' ) {
			return $offset + strlen( $matched_tag );
		}

		// Find the matching closing tag, handling nesting.
		$search_start = $offset + strlen( $matched_tag );
		$nesting_level = 1;
		$current_pos   = $search_start;
		$html_length   = strlen( $html );

		// Pattern to find opening or closing tags of the same type.
		$tag_pattern = '/<\/?(' . preg_quote( $tag_name, '/' ) . ')(?:\s[^>]*)?>/i';

		while ( $nesting_level > 0 && $current_pos < $html_length ) {
			// Find next tag of the same type.
			if ( preg_match( $tag_pattern, $html, $next_match, PREG_OFFSET_CAPTURE, $current_pos ) ) {
				$found_tag = $next_match[0][0];
				$found_pos = $next_match[0][1];

				// Check if opening or closing.
				if ( strpos( $found_tag, '</' ) === 0 ) {
					$nesting_level--;
					if ( 0 === $nesting_level ) {
						// Found the closing tag - return position after it.
						return $found_pos + strlen( $found_tag );
					}
				} else {
					// Opening tag, increase nesting.
					$nesting_level++;
				}

				$current_pos = $found_pos + strlen( $found_tag );
			} else {
				// No more tags found, break.
				break;
			}
		}

		// Fallback: insert after opening tag if no closing tag found.
		return $offset + strlen( $matched_tag );
	}

	/**
	 * Build the HTML for a slot container.
	 *
	 * @param Slot $slot  The slot to build HTML for.
	 * @param int  $index Instance index for 'all' selector mode.
	 * @return string Slot container HTML.
	 */
	private function build_slot_html( Slot $slot, int $index = 0 ): string {
		$id        = esc_attr( $slot->get_id() );
		$sanitized_id = preg_replace( '/[^a-zA-Z0-9_-]/', '-', $id );

		// Build classes.
		$device  = $slot->get_device();
		$classes = [ 'asr-ad-slot', 'asr-slot-' . $sanitized_id, 'asr-device-' . $device ];

		if ( $slot->get_is_sticky() ) {
			$classes[] = 'asr-sticky';
		}

		if ( $slot->get_has_fallback() ) {
			$classes[] = 'asr-fallback-container';
		}

		$class_attr = esc_attr( implode( ' ', $classes ) );

		// Build data attributes.
		$data_attrs = sprintf( 'data-asr-slot="%s"', $id );

		if ( $slot->get_is_sticky() ) {
			$data_attrs .= sprintf( ' data-asr-sticky-offset="%d"', $slot->get_sticky_offset() );
		}

		if ( $slot->get_has_fallback() ) {
			$data_attrs .= sprintf( ' data-asr-fallback="%s"', esc_attr( $slot->get_fallback_image_url() ) );
			$data_attrs .= sprintf( ' data-asr-fallback-timeout="%d"', $slot->get_fallback_timeout() );
			if ( ! empty( $slot->get_fallback_link_url() ) ) {
				$data_attrs .= sprintf( ' data-asr-fallback-link="%s"', esc_attr( $slot->get_fallback_link_url() ) );
			}
		}

		// Add instance index for 'all' mode.
		$data_attrs .= sprintf( ' data-asr-instance="%d"', $index );

		// Build inline style for min-height.
		$min_height = $slot->get_min_height();
		$style_attr = $min_height > 0 ? sprintf( ' style="min-height:%dpx;"', $min_height ) : '';

		// Build fallback image if enabled.
		$fallback_html = '';
		if ( $slot->get_has_fallback() && ! empty( $slot->get_fallback_image_url() ) ) {
			$fallback_url = esc_url( $slot->get_fallback_image_url() );
			$fallback_img = sprintf(
				'<img class="asr-fallback-img" src="%s" alt="" loading="lazy" style="display:none;width:100%%;height:auto;">',
				$fallback_url
			);

			$link_url = $slot->get_fallback_link_url();
			if ( ! empty( $link_url ) ) {
				$fallback_html = sprintf(
					'<a href="%s" target="_blank" rel="noopener" class="asr-fallback-link">%s</a>',
					esc_url( $link_url ),
					$fallback_img
				);
			} else {
				$fallback_html = $fallback_img;
			}
		}

		return sprintf(
			'<div class="%s" %s%s>%s</div>',
			$class_attr,
			$data_attrs,
			$style_attr,
			$fallback_html
		);
	}
}
