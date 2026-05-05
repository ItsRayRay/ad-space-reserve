<?php
/**
 * Slot Collection class for CRUD operations.
 *
 * @package AdSpaceReserve\Core
 */

namespace AdSpaceReserve\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages a collection of Slot objects with persistence.
 *
 * Provides CRUD operations for slots stored via the Options class.
 */
class Slot_Collection {

    /**
     * Options instance for persistence.
     *
     * @var Options
     */
    private Options $options;

    /**
     * Cache of loaded slots.
     *
     * @var array<string, Slot>|null
     */
    private ?array $cache = null;

    /**
     * Construct the collection with an Options instance.
     *
     * @param Options $options Options instance for persistence.
     */
    public function __construct( Options $options ) {
        $this->options = $options;
    }

    /**
     * Get all configured slots.
     *
     * @return array<string, Slot> Array of Slot objects keyed by ID.
     */
    public function get_all(): array {
        return $this->load();
    }

    /**
     * Get a single slot by ID.
     *
     * @param string $id Slot ID.
     * @return Slot|null Slot object or null if not found.
     */
    public function get( string $id ): ?Slot {
        $slots = $this->load();

        return $slots[ $id ] ?? null;
    }

    /**
     * Add a new slot to the collection.
     *
     * Sets created_at and updated_at timestamps.
     * Fails if a slot with the same ID already exists.
     *
     * @param Slot $slot Slot to add.
     * @return bool True on success, false if ID already exists.
     */
    public function add( Slot $slot ): bool {
        $slots = $this->load();
        $id    = $slot->get_id();

        if ( isset( $slots[ $id ] ) ) {
            return false;
        }

        // Set timestamps for new slot.
        $data               = $slot->to_array();
        $now                = gmdate( 'c' );
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $slots[ $id ] = Slot::from_array( $data );

        return $this->save( $slots );
    }

    /**
     * Update an existing slot.
     *
     * Updates the updated_at timestamp.
     * Fails if the slot ID doesn't exist.
     *
     * @param Slot $slot Slot to update.
     * @return bool True on success, false if ID not found.
     */
    public function update( Slot $slot ): bool {
        $slots = $this->load();
        $id    = $slot->get_id();

        if ( ! isset( $slots[ $id ] ) ) {
            return false;
        }

        // Preserve created_at, update updated_at.
        $data               = $slot->to_array();
        $data['created_at'] = $slots[ $id ]->get_created_at();
        $data['updated_at'] = gmdate( 'c' );

        $slots[ $id ] = Slot::from_array( $data );

        return $this->save( $slots );
    }

    /**
     * Delete a slot by ID.
     *
     * @param string $id Slot ID to delete.
     * @return bool True on success, false if ID not found.
     */
    public function delete( string $id ): bool {
        $slots = $this->load();

        if ( ! isset( $slots[ $id ] ) ) {
            return false;
        }

        unset( $slots[ $id ] );

        return $this->save( $slots );
    }

    /**
     * Check if a slot ID exists.
     *
     * @param string $id Slot ID to check.
     * @return bool True if exists.
     */
    public function exists( string $id ): bool {
        $slots = $this->load();

        return isset( $slots[ $id ] );
    }

    /**
     * Get the number of configured slots.
     *
     * @return int Number of slots.
     */
    public function count(): int {
        return count( $this->load() );
    }

    /**
     * Load slots from Options and hydrate to Slot objects.
     *
     * @return array<string, Slot> Array of Slot objects keyed by ID.
     */
    private function load(): array {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $stored = $this->options->get( 'configured_slots', [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $slots = [];
        foreach ( $stored as $id => $data ) {
            if ( is_array( $data ) ) {
                $data['id']    = $id;
                $slots[ $id ]  = Slot::from_array( $data );
            }
        }

        $this->cache = $slots;

        return $slots;
    }

    /**
     * Persist Slot objects to Options.
     *
     * @param array<string, Slot> $slots Array of Slot objects keyed by ID.
     * @return bool True on success.
     */
    private function save( array $slots ): bool {
        $data = [];
        foreach ( $slots as $id => $slot ) {
            $data[ $id ] = $slot->to_array();
        }

        $result = $this->options->set( 'configured_slots', $data );

        // Update cache on successful save.
        if ( $result ) {
            $this->cache = $slots;
        } else {
            // Clear cache to force reload on next access.
            $this->cache = null;
        }

        return $result;
    }

    /**
     * Clear the internal cache.
     *
     * Useful when options are modified externally.
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->cache = null;
    }
}
