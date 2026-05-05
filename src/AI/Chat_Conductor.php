<?php
/**
 * Chat Conductor for managing AI conversation flow.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the conversation flow for the AI chat assistant.
 *
 * Uses OpenRouter for fully AI-driven conversations when API key is configured,
 * with fallback to rule-based flow when not configured.
 */
class Chat_Conductor {

    /**
     * Maximum tokens for conversation history to prevent context overflow.
     * Reserve ~2000 for system prompt and ~2000 for response.
     */
    private const MAX_HISTORY_TOKENS = 6000;

    /**
     * Approximate characters per token for estimation.
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Conversation states for AI mode.
     */
    private const CONV_STATE_GATHERING    = 'gathering_info';
    private const CONV_STATE_READY        = 'ready_for_analysis';
    private const CONV_STATE_RECOMMENDING = 'showing_recommendations';
    private const CONV_STATE_COMPLETE     = 'complete';

    /**
     * Conversation stages for fallback mode.
     */
    private const STAGES = [
        'greeting',
        'priority',
        'formats',
        'networks',
        'traffic',
        'background',
        'analyzing',
        'recommendations',
        'complete',
    ];

    /**
     * Site type options.
     */
    private const SITE_TYPES = [
        'blog'      => 'Blog',
        'news'      => 'News',
        'ecommerce' => 'E-commerce',
        'portfolio' => 'Portfolio',
        'other'     => 'Other',
    ];

    /**
     * Chat storage instance.
     *
     * @var Chat_Storage
     */
    private Chat_Storage $storage;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->storage = Chat_Storage::get_instance();
    }

    /**
     * Check if OpenRouter API is configured and valid.
     *
     * @return bool True if API key is configured.
     */
    public function is_ai_enabled(): bool {
        $options       = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $encrypted_key = $options->get( 'openrouter_api_key', '' );
        return ! empty( $encrypted_key );
    }

    /**
     * Get the greeting message for a new conversation.
     *
     * When AI is enabled, this triggers the AI to generate a greeting.
     * Otherwise, returns a rule-based greeting.
     *
     * @return array{response: string, quick_replies: array, stage: string}
     */
    public function get_greeting_message(): array {
        if ( ! $this->is_ai_enabled() ) {
            return $this->get_fallback_greeting();
        }

        // For AI mode, generate greeting from AI.
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return $this->get_fallback_greeting();
        }

        $prompt_builder = new Prompt_Builder();
        $system_prompt  = $prompt_builder->build_conversational_system_prompt();

        $client = new OpenRouter_Client( $api_key );
        $result = $client->chat( [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => 'Start the conversation by greeting me and asking about my website.' ],
        ] );

        if ( ! $result['success'] ) {
            return $this->get_fallback_greeting();
        }

        // Parse AI response for quick replies and other markers.
        $parsed = $this->parse_ai_response( $result['content'] );

        return [
            'response'       => $parsed['text'],
            'quick_replies'  => $parsed['quick_replies'],
            'stage'          => 'ai_conversation',
                    ];
    }

    /**
     * Get fallback greeting for rule-based mode.
     *
     * @return array Response data.
     */
    private function get_fallback_greeting(): array {
        $quick_replies = [];
        foreach ( self::SITE_TYPES as $value => $label ) {
            $quick_replies[] = [
                'label' => $label,
                'value' => $label,
            ];
        }

        return [
            'response'       => "Hi! I'm here to help you find the best ad placements for your website. I'll ask a few questions to understand your needs, then analyze your content and suggest optimal placements.\n\nNote: For a more natural conversation experience, configure your OpenRouter API key in the AI settings.\n\nLet's start — what type of site is this?",
            'quick_replies'  => $quick_replies,
            'stage'          => 'greeting',
                    ];
    }

    /**
     * Process a user message and return the AI response.
     *
     * @param string $conv_id      Conversation ID.
     * @param string $user_message User's message.
     * @return array{response: string, quick_replies: array|null, stage: string, recommendations?: array, error?: string}
     */
    public function process_message( string $conv_id, string $user_message ): array {
        // Get conversation data.
        $conversation = $this->storage->get_conversation( $conv_id );

        if ( ! $conversation ) {
            return [
                'response'       => 'Conversation not found. Please start a new conversation.',
                'quick_replies'  => null,
                'stage'          => 'error',
                                'error'          => 'conversation_not_found',
            ];
        }

        // Check if AI is enabled.
        if ( ! $this->is_ai_enabled() ) {
            return $this->process_fallback_message( $conv_id, $user_message, $conversation );
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return $this->process_fallback_message( $conv_id, $user_message, $conversation );
        }

        // AI-driven conversation.
        return $this->process_ai_message( $conv_id, $user_message, $conversation, $api_key );
    }

    /**
     * Process message using AI-driven conversation.
     *
     * @param string $conv_id      Conversation ID.
     * @param string $user_message User's message.
     * @param array  $conversation Conversation data.
     * @param string $api_key      Decrypted API key.
     * @return array Response data.
     */
    private function process_ai_message( string $conv_id, string $user_message, array $conversation, string $api_key ): array {
        $wizard_data = $conversation['wizard_data'] ?? [];
        $messages    = $conversation['messages'] ?? [];

        // Build messages array for API.
        $prompt_builder = new Prompt_Builder();
        $system_prompt  = $prompt_builder->build_conversational_system_prompt();

        // Start with system prompt.
        $api_messages = [
            [ 'role' => 'system', 'content' => $system_prompt ],
        ];

        // Add conversation history (truncated if needed).
        $history_messages = $this->build_history_messages( $messages );
        $api_messages     = array_merge( $api_messages, $history_messages );

        // Check if we need to inject analysis context.
        if ( ! empty( $wizard_data['pending_analysis'] ) ) {
            // Content analysis was triggered, inject the results.
            $analysis_context = $prompt_builder->build_analysis_context(
                $wizard_data['content_analysis'] ?? [],
                $wizard_data
            );

            $api_messages[] = [
                'role'    => 'user',
                'content' => '[System: Site analysis complete. Here are the results:]',
            ];
            $api_messages[] = [
                'role'    => 'assistant',
                'content' => 'I\'ve received the analysis results. Let me process them.',
            ];
            $api_messages[] = [
                'role'    => 'user',
                'content' => $analysis_context,
            ];

            // Clear the pending flag.
            $wizard_data['pending_analysis'] = false;
            $this->storage->update_wizard_data( $conv_id, $wizard_data );
        }

        // Add the new user message.
        $api_messages[] = [
            'role'    => 'user',
            'content' => $user_message,
        ];

        // Call OpenRouter.
        $client = new OpenRouter_Client( $api_key );
        $result = $client->chat( $api_messages );

        if ( ! $result['success'] ) {
            // Store user message anyway.
            $this->storage->add_message( $conv_id, 'user', $user_message );

            $error_response = "I'm having trouble connecting to the AI service right now. " . esc_html( $result['error'] ?? 'Please try again.' );
            $this->storage->add_message( $conv_id, 'assistant', $error_response );

            return [
                'response'       => $error_response,
                'quick_replies'  => null,
                'stage'          => 'ai_conversation',
                                'error'          => $result['error'] ?? 'api_error',
            ];
        }

        // Parse AI response.
        $parsed = $this->parse_ai_response( $result['content'] );

        // Store messages.
        $this->storage->add_message( $conv_id, 'user', $user_message );
        $this->storage->add_message( $conv_id, 'assistant', $parsed['text'] );

        // Get updated messages for extraction.
        $updated_conv = $this->storage->get_conversation( $conv_id );
        $all_messages = $updated_conv['messages'] ?? [];

        // Extract wizard data from conversation using AI.
        $extracted_data = $this->extract_wizard_data_from_conversation( $all_messages, $api_key );

        // Merge extracted data with existing wizard_data (extracted takes precedence).
        $wizard_data = array_merge( $wizard_data, $extracted_data );

        // Assess conversation readiness.
        $readiness = $this->assess_conversation_readiness( $wizard_data );

        // Determine conversation state.
        if ( ! empty( $parsed['recommendations'] ) ) {
            $conv_state = self::CONV_STATE_RECOMMENDING;
        } elseif ( $parsed['ready_for_analysis'] ) {
            $conv_state = self::CONV_STATE_READY;
        } else {
            $conv_state = self::CONV_STATE_GATHERING;
        }

        $wizard_data['conversation_state'] = $conv_state;
        $wizard_data['readiness']          = $readiness;

        // Store updated wizard_data.
        $this->storage->update_wizard_data( $conv_id, $wizard_data );

        // Check for READY_FOR_ANALYSIS marker.
        if ( $parsed['ready_for_analysis'] ) {
            // Verify we have minimum required data before proceeding.
            if ( ! $readiness['ready'] ) {
                // AI signaled ready but we're missing required data.
                // Let the AI continue gathering info - it will be guided by the system prompt.
                $missing_fields = $this->get_missing_field_names( $readiness['missing'] );

                return [
                    'response'           => $parsed['text'],
                    'quick_replies'      => $parsed['quick_replies'],
                    'stage'              => 'ai_conversation',
                    'conversation_state' => self::CONV_STATE_GATHERING,
                    'readiness'          => $readiness,
                    'missing_fields'     => $missing_fields,
                ];
            }

            // Ready for analysis - proceed.
            return $this->trigger_content_analysis( $conv_id, $wizard_data, $parsed, $api_key );
        }

        // Build response.
        $has_recommendations = ! empty( $parsed['recommendations'] );
        $response = [
            'response'           => $parsed['text'],
            'quick_replies'      => $parsed['quick_replies'],
            'stage'              => 'ai_conversation',
            'conversation_state' => $conv_state,
            'readiness'          => $readiness,
        ];

        if ( $has_recommendations ) {
            $response['recommendations'] = $parsed['recommendations'];

            // Update state to complete after recommendations shown.
            $wizard_data['conversation_state'] = self::CONV_STATE_RECOMMENDING;
            $this->storage->update_wizard_data( $conv_id, $wizard_data );
        }

        return $response;
    }

    /**
     * Trigger content analysis when AI indicates readiness.
     *
     * @param string $conv_id     Conversation ID.
     * @param array  $wizard_data Wizard data.
     * @param array  $parsed      Parsed AI response.
     * @param string $api_key     API key.
     * @return array Response data.
     */
    private function trigger_content_analysis( string $conv_id, array $wizard_data, array $parsed, string $api_key ): array {
        // Run content analysis.
        $analyzer         = new Content_Analyzer();
        $content_analysis = $analyzer->analyze();

        // Store analysis in wizard_data.
        $wizard_data['content_analysis']  = $content_analysis;
        $wizard_data['pending_analysis']  = true;
        $this->storage->update_wizard_data( $conv_id, $wizard_data );

        // Build analysis context message.
        $prompt_builder   = new Prompt_Builder();
        $analysis_context = $prompt_builder->build_analysis_context( $content_analysis, $wizard_data );

        // Get conversation history for context.
        $conversation = $this->storage->get_conversation( $conv_id );
        $messages     = $conversation['messages'] ?? [];

        // Build API messages with analysis context.
        $system_prompt = $prompt_builder->build_conversational_system_prompt();
        $api_messages  = [
            [ 'role' => 'system', 'content' => $system_prompt ],
        ];

        // Add truncated history.
        $history_messages = $this->build_history_messages( $messages );
        $api_messages     = array_merge( $api_messages, $history_messages );

        // Add analysis context.
        $api_messages[] = [
            'role'    => 'user',
            'content' => $analysis_context,
        ];

        // Call OpenRouter for recommendations.
        $client = new OpenRouter_Client( $api_key );
        $result = $client->chat( $api_messages );

        if ( ! $result['success'] ) {
            // Fallback to default recommendations.
            $recommendations = $this->generate_default_recommendations( $wizard_data, $content_analysis );

            $wizard_data['recommendations']  = $recommendations;
            $wizard_data['pending_analysis'] = false;
            $this->storage->update_wizard_data( $conv_id, $wizard_data );

            $fallback_response = "I've analyzed your site! Here are my recommendations based on best practices:";
            $this->storage->add_message( $conv_id, 'assistant', $fallback_response );

            $wizard_data['conversation_state'] = self::CONV_STATE_RECOMMENDING;
            $this->storage->update_wizard_data( $conv_id, $wizard_data );

            return [
                'response'           => $fallback_response,
                'quick_replies'      => [
                    [ 'label' => 'Apply All', 'value' => 'Apply all recommendations' ],
                    [ 'label' => 'Skip', 'value' => 'Skip for now' ],
                ],
                'stage'              => 'recommendations',
                'recommendations'    => $recommendations,
                'conversation_state' => self::CONV_STATE_RECOMMENDING,
            ];
        }

        // Parse AI recommendations response.
        $recommendations_parsed = $this->parse_ai_response( $result['content'] );

        // Store the response.
        $this->storage->add_message( $conv_id, 'assistant', $recommendations_parsed['text'] );

        // Update wizard_data.
        $wizard_data['pending_analysis']   = false;
        $wizard_data['conversation_state'] = self::CONV_STATE_RECOMMENDING;
        if ( ! empty( $recommendations_parsed['recommendations'] ) ) {
            $wizard_data['recommendations'] = $recommendations_parsed['recommendations'];
        }
        $this->storage->update_wizard_data( $conv_id, $wizard_data );

        return [
            'response'           => $recommendations_parsed['text'],
            'quick_replies'      => $recommendations_parsed['quick_replies'],
            'stage'              => 'recommendations',
            'recommendations'    => $recommendations_parsed['recommendations'] ?? [],
            'conversation_state' => self::CONV_STATE_RECOMMENDING,
        ];
    }

    /**
     * Assess if conversation has gathered enough information for analysis.
     *
     * @param array $wizard_data Extracted wizard data.
     * @return array{ready: bool, missing: array, confidence: string}
     */
    private function assess_conversation_readiness( array $wizard_data ): array {
        $required = [ 'site_type', 'priority' ];
        $optional = [ 'excluded_formats', 'ad_networks', 'traffic_pages', 'background_color' ];

        $missing  = [];
        $filled   = 0;
        $optional_filled = 0;

        // Check required fields.
        foreach ( $required as $field ) {
            if ( empty( $wizard_data[ $field ] ) ) {
                $missing[] = $field;
            } else {
                $filled++;
            }
        }

        // Check optional fields.
        foreach ( $optional as $field ) {
            if ( ! empty( $wizard_data[ $field ] ) ) {
                $optional_filled++;
            }
        }

        // Determine readiness.
        $is_ready = empty( $missing );

        // Determine confidence level.
        $total_possible = count( $required ) + count( $optional );
        $total_filled   = $filled + $optional_filled;
        $fill_ratio     = $total_filled / $total_possible;

        if ( $fill_ratio >= 0.8 ) {
            $confidence = 'high';
        } elseif ( $fill_ratio >= 0.5 ) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        return [
            'ready'      => $is_ready,
            'missing'    => $missing,
            'confidence' => $confidence,
            'filled'     => $total_filled,
            'total'      => $total_possible,
        ];
    }

    /**
     * Get human-readable field names for missing fields.
     *
     * @param array $missing Array of missing field keys.
     * @return string Comma-separated list of field names.
     */
    private function get_missing_field_names( array $missing ): string {
        $names = [
            'site_type'        => 'site type',
            'priority'         => 'UX vs revenue priority',
            'excluded_formats' => 'excluded ad formats',
            'ad_networks'      => 'ad networks',
            'traffic_pages'    => 'high-traffic pages',
            'background_color' => 'background color preference',
        ];

        $readable = array_map(
            function ( $field ) use ( $names ) {
                return $names[ $field ] ?? $field;
            },
            $missing
        );

        return implode( ', ', $readable );
    }

    /**
     * Extract wizard data from conversation using AI.
     *
     * Parses the conversation history to extract structured wizard data
     * using OpenRouter for intelligent extraction.
     *
     * @param array  $messages Conversation messages.
     * @param string $api_key  Decrypted API key.
     * @return array Extracted wizard data.
     */
    private function extract_wizard_data_from_conversation( array $messages, string $api_key ): array {
        // Build conversation text for analysis.
        $conversation_text = '';
        foreach ( $messages as $msg ) {
            $role    = ucfirst( $msg['role'] ?? 'user' );
            $content = $msg['content'] ?? '';
            $conversation_text .= "{$role}: {$content}\n\n";
        }

        // Build extraction prompt.
        $extraction_prompt = $this->get_extraction_prompt();

        $api_messages = [
            [
                'role'    => 'system',
                'content' => $extraction_prompt,
            ],
            [
                'role'    => 'user',
                'content' => "Extract wizard data from this conversation:\n\n{$conversation_text}",
            ],
        ];

        // Call OpenRouter for extraction.
        $client = new OpenRouter_Client( $api_key );
        $result = $client->chat( $api_messages );

        if ( ! $result['success'] ) {
            return [];
        }

        // Parse JSON from response.
        $content = $result['content'];

        // Extract JSON from markdown code blocks if present.
        if ( preg_match( '/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $content, $matches ) ) {
            $json_str = $matches[1];
        } else {
            // Try to find raw JSON object.
            if ( preg_match( '/(\{[\s\S]*\})/', $content, $matches ) ) {
                $json_str = $matches[1];
            } else {
                return [];
            }
        }

        $extracted = json_decode( $json_str, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $extracted ) ) {
            return [];
        }

        // Sanitize and validate extracted data.
        return $this->sanitize_extracted_data( $extracted );
    }

    /**
     * Get the prompt for extracting wizard data from conversation.
     *
     * @return string Extraction prompt.
     */
    private function get_extraction_prompt(): string {
        return <<<'PROMPT'
You are a data extraction assistant. Extract structured wizard data from the conversation provided.

Return ONLY a JSON object with the following schema. Include only fields mentioned in the conversation. Use null for fields not discussed.

{
  "site_type": "blog" | "news" | "ecommerce" | "portfolio" | "forum" | "membership" | "magazine" | "other" | null,
  "priority": 1-10 (integer, where 1=UX-focused, 10=revenue-focused) | null,
  "excluded_formats": ["sticky_sidebar", "bottom_sticky", "in_content", "popup"] | [],
  "ad_networks": ["adsense", "ad_manager", "mediavine", "ezoic", "other"] | [],
  "traffic_pages": ["homepage", "blog_posts", "category", "product", "landing"] | [],
  "background_color": "light_gray" | "white" | "transparent" | "match_theme" | null
}

Guidelines:
- Infer priority from context: "maximize revenue" = 8-9, "balance" = 5, "clean UX" = 2-3
- If user mentions a network by name (AdSense, Ezoic, etc.), include it in ad_networks
- If user explicitly says they don't want a format, add to excluded_formats
- For site_type, map common terms: "shop" → "ecommerce", "articles" → "blog", "breaking news" → "news"
- Return valid JSON only, no explanation text
PROMPT;
    }

    /**
     * Sanitize extracted wizard data.
     *
     * @param array $extracted Raw extracted data.
     * @return array Sanitized data.
     */
    private function sanitize_extracted_data( array $extracted ): array {
        $sanitized = [];

        // Site type.
        $valid_site_types = [ 'blog', 'news', 'ecommerce', 'portfolio', 'forum', 'membership', 'magazine', 'other' ];
        if ( ! empty( $extracted['site_type'] ) && in_array( $extracted['site_type'], $valid_site_types, true ) ) {
            $sanitized['site_type'] = $extracted['site_type'];
        }

        // Priority (1-10).
        if ( isset( $extracted['priority'] ) && is_numeric( $extracted['priority'] ) ) {
            $priority = (int) $extracted['priority'];
            if ( $priority >= 1 && $priority <= 10 ) {
                $sanitized['priority'] = $priority;
            }
        }

        // Excluded formats.
        $valid_formats = [ 'sticky_sidebar', 'bottom_sticky', 'in_content', 'popup' ];
        if ( ! empty( $extracted['excluded_formats'] ) && is_array( $extracted['excluded_formats'] ) ) {
            $sanitized['excluded_formats'] = array_values(
                array_intersect( $extracted['excluded_formats'], $valid_formats )
            );
        }

        // Ad networks.
        $valid_networks = [ 'adsense', 'ad_manager', 'mediavine', 'ezoic', 'other' ];
        if ( ! empty( $extracted['ad_networks'] ) && is_array( $extracted['ad_networks'] ) ) {
            $sanitized['ad_networks'] = array_values(
                array_intersect( $extracted['ad_networks'], $valid_networks )
            );
        }

        // Traffic pages.
        $valid_pages = [ 'homepage', 'blog_posts', 'category', 'product', 'landing' ];
        if ( ! empty( $extracted['traffic_pages'] ) && is_array( $extracted['traffic_pages'] ) ) {
            $sanitized['traffic_pages'] = array_values(
                array_intersect( $extracted['traffic_pages'], $valid_pages )
            );
        }

        // Background color.
        $valid_colors = [ 'light_gray', 'white', 'transparent', 'match_theme' ];
        if ( ! empty( $extracted['background_color'] ) && in_array( $extracted['background_color'], $valid_colors, true ) ) {
            $sanitized['background_color'] = $extracted['background_color'];
        }

        return $sanitized;
    }

    /**
     * Build history messages array with token limit.
     *
     * Truncates older messages if the conversation is too long.
     *
     * @param array $messages Full message history.
     * @return array Truncated messages for API.
     */
    private function build_history_messages( array $messages ): array {
        $result       = [];
        $total_tokens = 0;

        // Process messages in reverse (newest first) to prioritize recent context.
        $reversed = array_reverse( $messages );

        foreach ( $reversed as $msg ) {
            $content = $msg['content'] ?? '';
            $tokens  = $this->estimate_tokens( $content );

            if ( $total_tokens + $tokens > self::MAX_HISTORY_TOKENS ) {
                break;
            }

            $total_tokens += $tokens;
            array_unshift( $result, [
                'role'    => $msg['role'],
                'content' => $content,
            ] );
        }

        return $result;
    }

    /**
     * Estimate token count for a string.
     *
     * @param string $text Text to estimate.
     * @return int Estimated token count.
     */
    private function estimate_tokens( string $text ): int {
        return (int) ceil( strlen( $text ) / self::CHARS_PER_TOKEN );
    }

    /**
     * Parse AI response for markers and structured data.
     *
     * @param string $content AI response content.
     * @return array{text: string, quick_replies: array, recommendations: array, ready_for_analysis: bool}
     */
    private function parse_ai_response( string $content ): array {
        $result = [
            'text'               => $content,
            'quick_replies'      => [],
            'recommendations'    => [],
            'ready_for_analysis' => false,
        ];

        // Check for READY_FOR_ANALYSIS marker.
        if ( strpos( $content, '[READY_FOR_ANALYSIS]' ) !== false ) {
            $result['ready_for_analysis'] = true;
            $result['text'] = str_replace( '[READY_FOR_ANALYSIS]', '', $result['text'] );
        }

        // Parse quick replies.
        if ( preg_match( '/\[QUICK_REPLIES\]\s*```(?:json)?\s*(\[[\s\S]*?\])\s*```/i', $content, $matches ) ) {
            $quick_replies = json_decode( $matches[1], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $quick_replies ) ) {
                $result['quick_replies'] = array_map( function ( $qr ) {
                    return [
                        'label' => sanitize_text_field( $qr['label'] ?? '' ),
                        'value' => sanitize_text_field( $qr['value'] ?? $qr['label'] ?? '' ),
                    ];
                }, $quick_replies );
            }
            // Remove the marker from text.
            $result['text'] = preg_replace( '/\[QUICK_REPLIES\]\s*```(?:json)?\s*\[[\s\S]*?\]\s*```/i', '', $result['text'] );
        }

        // Parse slot recommendations.
        if ( preg_match( '/\[SLOTS_RECOMMENDATION\]\s*```(?:json)?\s*(\[[\s\S]*?\])\s*```/i', $content, $matches ) ) {
            $recommendations = json_decode( $matches[1], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $recommendations ) ) {
                $result['recommendations'] = $this->sanitize_recommendations( $recommendations );
            }
            // Remove the marker from text.
            $result['text'] = preg_replace( '/\[SLOTS_RECOMMENDATION\]\s*```(?:json)?\s*\[[\s\S]*?\]\s*```/i', '', $result['text'] );
        }

        // Clean up extra whitespace.
        $result['text'] = trim( preg_replace( '/\n{3,}/', "\n\n", $result['text'] ) );

        return $result;
    }

    /**
     * Sanitize recommendation data from AI.
     *
     * @param array $recommendations Raw recommendations.
     * @return array Sanitized recommendations.
     */
    private function sanitize_recommendations( array $recommendations ): array {
        $valid = [];
        foreach ( $recommendations as $rec ) {
            if ( ! isset( $rec['name'], $rec['selector'], $rec['width'], $rec['height'] ) ) {
                continue;
            }

            $valid[] = [
                'name'      => sanitize_text_field( $rec['name'] ),
                'selector'  => sanitize_text_field( $rec['selector'] ),
                'position'  => sanitize_key( $rec['position'] ?? 'after' ),
                'width'     => absint( $rec['width'] ),
                'height'    => absint( $rec['height'] ),
                'device'    => sanitize_key( $rec['device'] ?? 'both' ),
                'sticky'    => ! empty( $rec['sticky'] ),
                'rationale' => sanitize_text_field( $rec['rationale'] ?? '' ),
            ];
        }

        return $valid;
    }

    /**
     * Get decrypted API key.
     *
     * @return string API key or empty string.
     */
    private function get_api_key(): string {
        $options       = \AdSpaceReserve\Plugin::get_instance()->get_options();
        $encrypted_key = $options->get( 'openrouter_api_key', '' );
        return $this->decrypt_api_key( $encrypted_key );
    }

    /**
     * Decrypt the API key.
     *
     * @param string $encrypted Encrypted API key.
     * @return string Decrypted API key or empty string on failure.
     */
    private function decrypt_api_key( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        // Must match encryption in Settings_Page - use key directly, no hashing.
        $key  = defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : 'asr-fallback-key';
        $data = base64_decode( $encrypted );

        if ( false === $data || strlen( $data ) < 17 ) {
            return '';
        }

        $iv         = substr( $data, 0, 16 );
        $ciphertext = substr( $data, 16 );

        // Use flag 0 (not OPENSSL_RAW_DATA) to match Settings_Page encryption.
        $decrypted = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, 0, $iv );

        return false === $decrypted ? '' : $decrypted;
    }

    // =====================================================================
    // FALLBACK MODE (Rule-Based) - Used when API key is not configured
    // =====================================================================

    /**
     * Process message in fallback (rule-based) mode.
     *
     * @param string $conv_id      Conversation ID.
     * @param string $user_message User's message.
     * @param array  $conversation Conversation data.
     * @return array Response data.
     */
    private function process_fallback_message( string $conv_id, string $user_message, array $conversation ): array {
        $wizard_data   = $conversation['wizard_data'] ?? [];
        $current_stage = $wizard_data['stage'] ?? 'greeting';

        // Parse user input based on current stage.
        $parsed_data = $this->parse_user_input( $current_stage, $user_message );
        $wizard_data = array_merge( $wizard_data, $parsed_data );

        // Determine next stage.
        $next_stage           = $this->get_next_stage( $current_stage );
        $wizard_data['stage'] = $next_stage;

        // Save wizard_data.
        $this->storage->update_wizard_data( $conv_id, $wizard_data );

        // Generate response for next stage.
        return $this->generate_fallback_response( $conv_id, $next_stage, $wizard_data );
    }

    /**
     * Parse user input based on the current stage (fallback mode).
     *
     * @param string $stage   Current conversation stage.
     * @param string $message User's message.
     * @return array Parsed wizard data.
     */
    private function parse_user_input( string $stage, string $message ): array {
        $message_lower = strtolower( trim( $message ) );

        switch ( $stage ) {
            case 'greeting':
                return $this->parse_site_type( $message_lower );
            case 'priority':
                return $this->parse_priority( $message_lower );
            case 'formats':
                return $this->parse_excluded_formats( $message_lower );
            case 'networks':
                return $this->parse_ad_networks( $message_lower );
            case 'traffic':
                return $this->parse_traffic_pages( $message_lower );
            case 'background':
                return $this->parse_background_color( $message_lower );
            default:
                return [];
        }
    }

    /**
     * Parse site type from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{site_type: string}
     */
    private function parse_site_type( string $message ): array {
        $types = [
            'blog'      => [ 'blog' ],
            'news'      => [ 'news' ],
            'ecommerce' => [ 'ecommerce', 'e-commerce', 'shop', 'store' ],
            'portfolio' => [ 'portfolio' ],
        ];

        foreach ( $types as $key => $keywords ) {
            foreach ( $keywords as $keyword ) {
                if ( strpos( $message, $keyword ) !== false ) {
                    return [ 'site_type' => $key ];
                }
            }
        }

        return [ 'site_type' => 'other' ];
    }

    /**
     * Parse priority from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{priority: int}
     */
    private function parse_priority( string $message ): array {
        if ( preg_match( '/revenue|maximum|maximize|money/i', $message ) ) {
            return [ 'priority' => 8 ];
        }
        if ( preg_match( '/clean|minimal|user|ux/i', $message ) ) {
            return [ 'priority' => 3 ];
        }
        if ( preg_match( '/balance|both|mix/i', $message ) ) {
            return [ 'priority' => 5 ];
        }
        if ( preg_match( '/(\d+)/', $message, $matches ) ) {
            $num = (int) $matches[1];
            if ( $num >= 1 && $num <= 10 ) {
                return [ 'priority' => $num ];
            }
        }
        return [ 'priority' => 5 ];
    }

    /**
     * Parse excluded formats from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{excluded_formats: array}
     */
    private function parse_excluded_formats( string $message ): array {
        if ( preg_match( '/^(none|no|nope|nothing)$/i', trim( $message ) ) ) {
            return [ 'excluded_formats' => [] ];
        }

        $excluded = [];
        if ( strpos( $message, 'sticky' ) !== false ) {
            $excluded[] = 'sticky_sidebar';
            $excluded[] = 'bottom_sticky';
        }
        if ( strpos( $message, 'in-content' ) !== false || strpos( $message, 'in content' ) !== false ) {
            $excluded[] = 'in_content';
        }
        if ( strpos( $message, 'pop' ) !== false ) {
            $excluded[] = 'popup';
        }

        return [ 'excluded_formats' => array_unique( $excluded ) ];
    }

    /**
     * Parse ad networks from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{ad_networks: array}
     */
    private function parse_ad_networks( string $message ): array {
        $networks = [];
        if ( strpos( $message, 'adsense' ) !== false ) {
            $networks[] = 'adsense';
        }
        if ( strpos( $message, 'ad manager' ) !== false ) {
            $networks[] = 'ad_manager';
        }
        if ( strpos( $message, 'ezoic' ) !== false ) {
            $networks[] = 'ezoic';
        }
        if ( strpos( $message, 'mediavine' ) !== false ) {
            $networks[] = 'mediavine';
        }
        if ( empty( $networks ) ) {
            $networks[] = 'other';
        }
        return [ 'ad_networks' => $networks ];
    }

    /**
     * Parse traffic pages from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{traffic_pages: array}
     */
    private function parse_traffic_pages( string $message ): array {
        $pages = [];
        if ( strpos( $message, 'home' ) !== false ) {
            $pages[] = 'homepage';
        }
        if ( strpos( $message, 'blog' ) !== false || strpos( $message, 'post' ) !== false ) {
            $pages[] = 'blog_posts';
        }
        if ( strpos( $message, 'category' ) !== false ) {
            $pages[] = 'category';
        }
        if ( strpos( $message, 'product' ) !== false ) {
            $pages[] = 'product';
        }
        if ( strpos( $message, 'all' ) !== false ) {
            $pages = [ 'homepage', 'blog_posts', 'category' ];
        }
        if ( empty( $pages ) ) {
            $pages[] = 'blog_posts';
        }
        return [ 'traffic_pages' => $pages ];
    }

    /**
     * Parse background color from user message.
     *
     * @param string $message Lowercase user message.
     * @return array{background_color: string}
     */
    private function parse_background_color( string $message ): array {
        if ( strpos( $message, 'gray' ) !== false || strpos( $message, 'grey' ) !== false ) {
            return [ 'background_color' => 'light_gray' ];
        }
        if ( strpos( $message, 'white' ) !== false ) {
            return [ 'background_color' => 'white' ];
        }
        if ( strpos( $message, 'transparent' ) !== false ) {
            return [ 'background_color' => 'transparent' ];
        }
        if ( strpos( $message, 'match' ) !== false || strpos( $message, 'theme' ) !== false ) {
            return [ 'background_color' => 'match_theme' ];
        }
        return [ 'background_color' => 'light_gray' ];
    }

    /**
     * Get the next stage in the fallback conversation flow.
     *
     * @param string $current_stage Current stage.
     * @return string Next stage.
     */
    private function get_next_stage( string $current_stage ): string {
        $index = array_search( $current_stage, self::STAGES, true );
        if ( false === $index || $index >= count( self::STAGES ) - 1 ) {
            return 'complete';
        }
        return self::STAGES[ $index + 1 ];
    }

    /**
     * Generate response for fallback mode.
     *
     * @param string $conv_id     Conversation ID.
     * @param string $stage       Current stage.
     * @param array  $wizard_data Collected wizard data.
     * @return array Response data.
     */
    private function generate_fallback_response( string $conv_id, string $stage, array $wizard_data ): array {
        switch ( $stage ) {
            case 'priority':
                return [
                    'response'       => "Got it! What's more important — maximizing revenue or keeping a clean UX?",
                    'quick_replies'  => [
                        [ 'label' => 'Maximize revenue', 'value' => 'Maximize revenue' ],
                        [ 'label' => 'Balance both', 'value' => 'Balance both' ],
                        [ 'label' => 'Clean UX', 'value' => 'Clean UX' ],
                    ],
                    'stage'          => 'priority',
                                    ];

            case 'formats':
                return [
                    'response'       => "Any ad formats you'd like to avoid?",
                    'quick_replies'  => [
                        [ 'label' => 'No sticky ads', 'value' => 'No sticky ads' ],
                        [ 'label' => 'No in-content', 'value' => 'No in-content ads' ],
                        [ 'label' => 'None', 'value' => 'None' ],
                    ],
                    'stage'          => 'formats',
                                    ];

            case 'networks':
                return [
                    'response'       => "Which ad network are you using?",
                    'quick_replies'  => [
                        [ 'label' => 'AdSense', 'value' => 'AdSense' ],
                        [ 'label' => 'Ad Manager', 'value' => 'Ad Manager' ],
                        [ 'label' => 'Other', 'value' => 'Other' ],
                    ],
                    'stage'          => 'networks',
                                    ];

            case 'traffic':
                return [
                    'response'       => "Which pages get the most traffic?",
                    'quick_replies'  => [
                        [ 'label' => 'Blog posts', 'value' => 'Blog posts' ],
                        [ 'label' => 'Homepage', 'value' => 'Homepage' ],
                        [ 'label' => 'All pages', 'value' => 'All pages' ],
                    ],
                    'stage'          => 'traffic',
                                    ];

            case 'background':
                return [
                    'response'       => "What background color for placeholders?",
                    'quick_replies'  => [
                        [ 'label' => 'Light gray', 'value' => 'Light gray' ],
                        [ 'label' => 'White', 'value' => 'White' ],
                        [ 'label' => 'Match theme', 'value' => 'Match theme' ],
                    ],
                    'stage'          => 'background',
                                    ];

            case 'analyzing':
                return $this->run_fallback_analysis( $conv_id, $wizard_data );

            case 'recommendations':
                return [
                    'response'        => "Here are my recommendations. Apply them or configure manually.",
                    'quick_replies'   => [
                        [ 'label' => 'Apply All', 'value' => 'Apply all recommendations' ],
                        [ 'label' => 'Skip', 'value' => 'Skip for now' ],
                    ],
                    'stage'           => 'recommendations',
                    'recommendations' => $wizard_data['recommendations'] ?? [],
                ];

            case 'complete':
                return [
                    'response'       => 'Your ad slots have been configured. Check the Slots tab to review and deploy.',
                    'quick_replies'  => null,
                    'stage'          => 'complete',
                                    ];

            default:
                return $this->get_fallback_greeting();
        }
    }

    /**
     * Run content analysis in fallback mode.
     *
     * @param string $conv_id     Conversation ID.
     * @param array  $wizard_data Collected wizard data.
     * @return array Response data.
     */
    private function run_fallback_analysis( string $conv_id, array $wizard_data ): array {
        $analyzer         = new Content_Analyzer();
        $content_analysis = $analyzer->analyze();
        $recommendations  = $this->generate_default_recommendations( $wizard_data, $content_analysis );

        $wizard_data['recommendations']  = $recommendations;
        $wizard_data['content_analysis'] = $content_analysis;
        $wizard_data['stage']            = 'recommendations';
        $this->storage->update_wizard_data( $conv_id, $wizard_data );

        return [
            'response'        => "I've analyzed your content. Here are my recommendations based on best practices:",
            'quick_replies'   => null,
            'stage'           => 'recommendations',
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Generate default recommendations without AI.
     *
     * @param array $wizard_data      Collected wizard data.
     * @param array $content_analysis Content analysis results.
     * @return array Default recommendations.
     */
    private function generate_default_recommendations( array $wizard_data, array $content_analysis ): array {
        $excluded    = $wizard_data['excluded_formats'] ?? [];
        $selectors   = $content_analysis['detected_selectors'] ?? [];
        $content_sel = $selectors['content'] ?? '.entry-content';
        $sidebar_sel = $selectors['sidebar'] ?? '.sidebar';
        $recs        = [];

        // Sidebar ad.
        if ( ! in_array( 'sticky_sidebar', $excluded, true ) ) {
            $recs[] = [
                'name'      => 'Sidebar Medium Rectangle',
                'selector'  => $sidebar_sel,
                'position'  => 'prepend',
                'width'     => 300,
                'height'    => 250,
                'device'    => 'desktop',
                'sticky'    => false,
                'rationale' => 'Standard sidebar ad with high viewability.',
            ];
        }

        // In-content ad.
        if ( ! in_array( 'in_content', $excluded, true ) ) {
            $recs[] = [
                'name'      => 'In-Content Rectangle',
                'selector'  => $content_sel . ' > p:nth-of-type(3)',
                'position'  => 'after',
                'width'     => 300,
                'height'    => 250,
                'device'    => 'both',
                'sticky'    => false,
                'rationale' => 'After 3rd paragraph for optimal viewability.',
            ];
        }

        // Mobile banner.
        $recs[] = [
            'name'      => 'Mobile Top Banner',
            'selector'  => $content_sel,
            'position'  => 'before',
            'width'     => 320,
            'height'    => 100,
            'device'    => 'mobile',
            'sticky'    => false,
            'rationale' => 'Large mobile banner at content start.',
        ];

        return $recs;
    }
}
