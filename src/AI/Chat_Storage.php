<?php
/**
 * Chat Storage class for conversation persistence.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages conversation storage and retrieval using WordPress options.
 *
 * Stores chat conversations with messages, wizard data, and metadata.
 * Supports creating, updating, and deleting conversations with a
 * maximum limit of 10 conversations per user.
 */
class Chat_Storage {

    /**
     * WordPress option key for storing conversations.
     */
    private const OPTION_KEY = 'asr_chat_conversations';

    /**
     * Maximum number of conversations to store.
     */
    private const MAX_CONVERSATIONS = 10;

    /**
     * Maximum messages retained per conversation.
     *
     * Without this, a single conversation grows without bound and the whole
     * option is read back and rewritten on every message.
     *
     * @var int
     */
    private const MAX_MESSAGES_PER_CONVERSATION = 200;

    /**
     * Singleton instance.
     *
     * @var Chat_Storage|null
     */
    private static ?Chat_Storage $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Chat_Storage Storage instance.
     */
    public static function get_instance(): Chat_Storage {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {}

    /**
     * Get all conversations (without messages for performance).
     *
     * Returns a list of conversations with id, title, created_at, updated_at, status.
     * Messages are excluded to keep the response lightweight.
     *
     * @return array List of conversation summaries.
     */
    public function get_all_conversations(): array {
        $data          = $this->get_storage_data();
        $conversations = $data['conversations'] ?? [];
        $result        = [];

        foreach ( $conversations as $conv ) {
            $result[] = [
                'id'         => $conv['id'],
                'title'      => $conv['title'] ?? '',
                'created_at' => $conv['created_at'] ?? '',
                'updated_at' => $conv['updated_at'] ?? '',
                'status'     => $conv['status'] ?? 'active',
            ];
        }

        // Sort by updated_at descending (most recent first).
        usort( $result, function ( $a, $b ) {
            return strcmp( $b['updated_at'], $a['updated_at'] );
        } );

        return $result;
    }

    /**
     * Get a single conversation with all messages.
     *
     * @param string $id Conversation ID.
     * @return array|null Full conversation data or null if not found.
     */
    public function get_conversation( string $id ): ?array {
        $data          = $this->get_storage_data();
        $conversations = $data['conversations'] ?? [];

        foreach ( $conversations as $conv ) {
            if ( $conv['id'] === $id ) {
                return $conv;
            }
        }

        return null;
    }

    /**
     * Create a new conversation.
     *
     * Generates a unique ID and initializes with empty messages.
     * Enforces the maximum conversation limit by removing oldest.
     *
     * @return string The new conversation ID.
     */
    public function create_conversation(): string {
        $data = $this->get_storage_data();
        $id   = 'conv_' . time() . '_' . wp_rand( 1000, 9999 );
        $now  = gmdate( 'c' );

        $new_conversation = [
            'id'          => $id,
            'created_at'  => $now,
            'updated_at'  => $now,
            'title'       => '',
            'messages'    => [],
            'wizard_data' => [],
            'status'      => 'active',
        ];

        // Add to beginning of array (newest first).
        array_unshift( $data['conversations'], $new_conversation );

        // Enforce max conversations limit.
        $this->enforce_max_conversations( $data );

        // Set as active conversation.
        $data['active_conversation_id'] = $id;

        $this->save_storage_data( $data );

        return $id;
    }

    /**
     * Add a message to a conversation.
     *
     * Also updates the updated_at timestamp and generates title from first user message.
     *
     * @param string $conv_id Conversation ID.
     * @param string $role    Message role ('user' or 'assistant').
     * @param string $content Message content.
     * @return bool True on success, false on failure.
     */
    public function add_message( string $conv_id, string $role, string $content ): bool {
        $data  = $this->get_storage_data();
        $now   = gmdate( 'c' );
        $found = false;

        foreach ( $data['conversations'] as &$conv ) {
            if ( $conv['id'] === $conv_id ) {
                $message = [
                    'role'      => $role,
                    'content'   => $content,
                    'timestamp' => $now,
                ];

                $conv['messages'][] = $message;

                // Keep only the most recent messages so the stored option
                // cannot grow without bound.
                if ( count( $conv['messages'] ) > self::MAX_MESSAGES_PER_CONVERSATION ) {
                    $conv['messages'] = array_slice(
                        $conv['messages'],
                        -self::MAX_MESSAGES_PER_CONVERSATION
                    );
                }

                $conv['updated_at'] = $now;

                // Generate title from first user message if not set.
                if ( empty( $conv['title'] ) && 'user' === $role ) {
                    $conv['title'] = $this->generate_title( $content );
                }

                $found = true;
                break;
            }
        }

        if ( $found ) {
            $this->save_storage_data( $data );
        }

        return $found;
    }

    /**
     * Update wizard data for a conversation.
     *
     * Merges new data with existing wizard_data.
     *
     * @param string $conv_id Conversation ID.
     * @param array  $data    Wizard data to merge.
     * @return bool True on success, false on failure.
     */
    public function update_wizard_data( string $conv_id, array $new_data ): bool {
        $data  = $this->get_storage_data();
        $found = false;

        foreach ( $data['conversations'] as &$conv ) {
            if ( $conv['id'] === $conv_id ) {
                $conv['wizard_data'] = array_merge(
                    $conv['wizard_data'] ?? [],
                    $new_data
                );
                $conv['updated_at'] = gmdate( 'c' );
                $found              = true;
                break;
            }
        }

        if ( $found ) {
            $this->save_storage_data( $data );
        }

        return $found;
    }

    /**
     * Get the active conversation ID.
     *
     * @return string|null Active conversation ID or null if none.
     */
    public function get_active_conversation_id(): ?string {
        $data = $this->get_storage_data();
        $id   = $data['active_conversation_id'] ?? null;

        // Verify the conversation still exists.
        if ( $id && ! $this->get_conversation( $id ) ) {
            return null;
        }

        return $id;
    }

    /**
     * Set the active conversation ID.
     *
     * @param string|null $id Conversation ID or null to clear.
     * @return void
     */
    public function set_active_conversation_id( ?string $id ): void {
        $data                           = $this->get_storage_data();
        $data['active_conversation_id'] = $id;
        $this->save_storage_data( $data );
    }

    /**
     * Delete a conversation.
     *
     * Also clears active_conversation_id if it was the deleted one.
     *
     * @param string $id Conversation ID to delete.
     * @return bool True if deleted, false if not found.
     */
    public function delete_conversation( string $id ): bool {
        $data          = $this->get_storage_data();
        $original_count = count( $data['conversations'] );

        $data['conversations'] = array_filter(
            $data['conversations'],
            function ( $conv ) use ( $id ) {
                return $conv['id'] !== $id;
            }
        );

        // Re-index array.
        $data['conversations'] = array_values( $data['conversations'] );

        // Clear active if this was it.
        if ( isset( $data['active_conversation_id'] ) && $data['active_conversation_id'] === $id ) {
            $data['active_conversation_id'] = null;
        }

        $deleted = count( $data['conversations'] ) < $original_count;

        if ( $deleted ) {
            $this->save_storage_data( $data );
        }

        return $deleted;
    }

    /**
     * Generate a title from the first user message.
     *
     * Takes the first 50 characters of the message, trimmed at word boundary.
     *
     * @param string $first_message The first user message.
     * @return string Generated title.
     */
    public function generate_title( string $first_message ): string {
        // Strip HTML and normalize whitespace.
        $text = wp_strip_all_tags( $first_message );
        $text = preg_replace( '/\s+/', ' ', trim( $text ) );

        if ( strlen( $text ) <= 50 ) {
            return $text;
        }

        // Truncate at word boundary.
        $truncated = substr( $text, 0, 50 );
        $last_space = strrpos( $truncated, ' ' );

        if ( $last_space !== false && $last_space > 30 ) {
            $truncated = substr( $truncated, 0, $last_space );
        }

        return $truncated . '...';
    }

    /**
     * Get storage data from WordPress options.
     *
     * @return array Storage data with conversations array.
     */
    private function get_storage_data(): array {
        $data = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( ! isset( $data['conversations'] ) || ! is_array( $data['conversations'] ) ) {
            $data['conversations'] = [];
        }

        if ( ! isset( $data['active_conversation_id'] ) ) {
            $data['active_conversation_id'] = null;
        }

        return $data;
    }

    /**
     * Save storage data to WordPress options.
     *
     * @param array $data Storage data to save.
     * @return bool True on success, false on failure.
     */
    private function save_storage_data( array $data ): bool {
        // Autoload 'no': this is admin-only data that must not be loaded on
        // every frontend request. WordPress only decides autoload by value size
        // from 6.6 onward, and this plugin supports older releases.
        return update_option( self::OPTION_KEY, $data, 'no' );
    }

    /**
     * Enforce the maximum number of conversations.
     *
     * Removes oldest conversations when limit is exceeded.
     *
     * @param array $data Storage data (passed by reference).
     * @return void
     */
    private function enforce_max_conversations( array &$data ): void {
        // Sort by updated_at descending.
        usort( $data['conversations'], function ( $a, $b ) {
            return strcmp( $b['updated_at'], $a['updated_at'] );
        } );

        // Keep only the most recent MAX_CONVERSATIONS.
        if ( count( $data['conversations'] ) > self::MAX_CONVERSATIONS ) {
            $data['conversations'] = array_slice(
                $data['conversations'],
                0,
                self::MAX_CONVERSATIONS
            );
        }
    }
}
