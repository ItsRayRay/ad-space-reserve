<?php
/**
 * Lightweight checks for AdShimmer placement behavior outside WordPress.
 */

define( 'ABSPATH', true );

$GLOBALS['wp_options'] = [
	'asr_settings' => [
		'max_infinite_slots' => 3,
	],
];

function __( $text, $domain = null ) {
	return $text;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function apply_filters( $tag, $value ) {
	return $value;
}

function get_body_class() {
	return [ 'single', 'postid-1' ];
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function get_option( $key, $default = false ) {
	return $GLOBALS['wp_options'][ $key ] ?? $default;
}

require_once __DIR__ . '/../src/Core/Options.php';
require_once __DIR__ . '/../src/Core/Slot.php';
require_once __DIR__ . '/../src/Frontend/Frontend_Injector.php';
require_once __DIR__ . '/../src/Generator/Slot_Expander.php';

use AdSpaceReserve\Core\Options;
use AdSpaceReserve\Core\Slot;
use AdSpaceReserve\Frontend\Frontend_Injector;
use AdSpaceReserve\Generator\Slot_Expander;

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function make_slot( array $overrides = [] ): Slot {
	return Slot::from_array(
		array_merge(
			[
				'id'            => 'test-slot',
				'name'          => 'Test Slot',
				'type'          => 'rectangle-infinite',
				'device'        => 'both',
				'selector'      => '.entry-content',
				'placement'     => 'after',
				'position'      => 2,
				'min_height'    => 250,
				'margin_top'    => 10,
				'margin_bottom' => 10,
			],
			$overrides
		)
	);
}

function make_injector( array $slots ): Frontend_Injector {
	$reflection = new ReflectionClass( Frontend_Injector::class );
	$injector   = $reflection->newInstanceWithoutConstructor();

	$slots_property = $reflection->getProperty( 'slots' );
	$slots_property->setAccessible( true );
	$slots_property->setValue( $injector, $slots );

	$buffer_property = $reflection->getProperty( 'buffer_started' );
	$buffer_property->setAccessible( true );
	$buffer_property->setValue( $injector, false );

	$cache_property = $reflection->getProperty( 'cache_plugin_detected' );
	$cache_property->setAccessible( true );
	$cache_property->setValue( $injector, false );

	return $injector;
}

function sample_html(): string {
	return '<!DOCTYPE html><html><body><header><p>Header paragraph.</p></header><main class="entry-content"><p>Article one.</p><p>Article two.</p><p>Article three.</p></main><footer><p>Footer paragraph.</p></footer></body></html>';
}

$_SERVER['REQUEST_URI'] = '/articles/alpha/?utm=1';
$html                  = sample_html();
$output                = make_injector( [ make_slot() ] )->process_html( $html );
$slot_pos              = strpos( $output, 'data-asr-slot="test-slot"' );
$article_two_pos       = strpos( $output, '<p>Article two.</p>' );
$article_three_pos     = strpos( $output, '<p>Article three.</p>' );
$header_pos            = strpos( $output, '<header>' );
$main_pos              = strpos( $output, '<main class="entry-content">' );

assert_true( false !== $slot_pos, 'Position slot should be injected.' );
assert_true( $slot_pos > $article_two_pos && $slot_pos < $article_three_pos, 'Position slot should be after paragraph 2 inside .entry-content.' );
assert_true( $slot_pos > $main_pos && $slot_pos > $header_pos, 'Position slot should not use header paragraphs.' );

$before_output = make_injector( [ make_slot( [ 'id' => 'before-slot', 'placement' => 'before' ] ) ] )->process_html( $html );
$before_pos    = strpos( $before_output, 'data-asr-slot="before-slot"' );
$para_two_pos  = strpos( $before_output, '<p>Article two.</p>' );
assert_true( false !== $before_pos && $before_pos < $para_two_pos, 'Before placement should inject before paragraph N.' );

$blocked_output = make_injector(
	[
		make_slot(
			[
				'id'           => 'blocked-slot',
				'target_paths' => '/reviews/*',
			]
		),
	]
)->process_html( $html );
assert_true( false === strpos( $blocked_output, 'data-asr-slot="blocked-slot"' ), 'Non-matching target path should not inject.' );

$allowed_output = make_injector(
	[
		make_slot(
			[
				'id'           => 'allowed-slot',
				'target_paths' => '/articles/*',
			]
		),
	]
)->process_html( $html );
assert_true( false !== strpos( $allowed_output, 'data-asr-slot="allowed-slot"' ), 'Wildcard target path should inject on matching path.' );

$expander       = new Slot_Expander( new Options() );
$ordinary_slots = $expander->expand( [ make_slot( [ 'type' => 'custom', 'position' => 0 ] ) ] );
assert_true( 1 === count( $ordinary_slots ), 'Non-infinite slots should not create placeholders.' );

$expanded_slots = $expander->expand( [ make_slot( [ 'target_paths' => '/articles/*' ] ) ] );
assert_true( 5 === count( $expanded_slots ), 'Both-device infinite slots should expand each device up to max_infinite_slots.' );
assert_true( '/articles/*' === $expanded_slots[1]->get_target_paths(), 'Generated placeholders should inherit path targeting.' );
assert_true( '.entry-content' === $expanded_slots[1]->get_selector(), 'Generated placeholders should inherit selector.' );

echo "All AdShimmer checks passed.\n";
