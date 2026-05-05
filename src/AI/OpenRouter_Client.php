<?php
/**
 * OpenRouter API Client for AI-powered ad placement recommendations.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Client for communicating with the OpenRouter API.
 *
 * Handles API requests to Claude Opus 4.5 via OpenRouter for
 * generating ad placement recommendations.
 */
class OpenRouter_Client {

    /**
     * OpenRouter API endpoint.
     */
    private const API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Default model to use.
     */
    private const DEFAULT_MODEL = 'anthropic/claude-opus-4.5';

    /**
     * API key for authentication.
     *
     * @var string
     */
    private string $api_key;

    /**
     * Model to use for requests.
     *
     * @var string
     */
    private string $model;

    /**
     * Constructor.
     *
     * @param string $api_key OpenRouter API key.
     * @param string $model   Optional model override.
     */
    public function __construct( string $api_key, string $model = '' ) {
        $this->api_key = $api_key;
        $this->model   = ! empty( $model ) ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Send a chat completion request to OpenRouter.
     *
     * @param array $messages Array of message objects with 'role' and 'content'.
     * @return array{success: bool, content?: string, error?: string, usage?: array}
     */
    public function chat( array $messages ): array {
        $response = wp_remote_post(
            self::API_ENDPOINT,
            [
                'timeout' => 120, // AI responses can take time.
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => 'AdShimmer AI',
                ],
                'body'    => wp_json_encode(
                    [
                        'model'      => $this->model,
                        'messages'   => $messages,
                        'max_tokens' => 4096,
                    ]
                ),
            ]
        );

        // Handle network errors.
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();

            // Friendly messages for common errors.
            if ( strpos( $error_message, 'timed out' ) !== false ) {
                return [
                    'success' => false,
                    'error'   => __( 'Connection timed out. Please try again.', 'adshimmer' ),
                ];
            }

            if ( strpos( $error_message, 'cURL error' ) !== false ) {
                return [
                    'success' => false,
                    'error'   => __( 'Network error. Please check your connection and try again.', 'adshimmer' ),
                ];
            }

            return [
                'success' => false,
                'error'   => $error_message,
            ];
        }

        // Check HTTP status code.
        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        // Try to parse JSON response.
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [
                'success' => false,
                'error'   => __( 'Invalid response from AI service.', 'adshimmer' ),
            ];
        }

        // Handle HTTP errors.
        if ( $status_code >= 400 ) {
            return $this->handle_error_response( $status_code, $data );
        }

        // Extract content from successful response.
        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            return [
                'success' => false,
                'error'   => __( 'No recommendations generated. Please try again.', 'adshimmer' ),
            ];
        }

        return [
            'success' => true,
            'content' => $data['choices'][0]['message']['content'],
            'usage'   => $data['usage'] ?? [],
        ];
    }

    /**
     * Handle error responses from the API.
     *
     * @param int   $status_code HTTP status code.
     * @param array $data        Parsed response data.
     * @return array{success: bool, error: string}
     */
    private function handle_error_response( int $status_code, array $data ): array {
        $error_message = $data['error']['message'] ?? '';

        switch ( $status_code ) {
            case 401:
                return [
                    'success' => false,
                    'error'   => __( 'Invalid API key. Please check your OpenRouter API key in settings.', 'adshimmer' ),
                ];

            case 402:
                return [
                    'success' => false,
                    'error'   => __( 'Insufficient credits. Please add credits to your OpenRouter account.', 'adshimmer' ),
                ];

            case 429:
                return [
                    'success' => false,
                    'error'   => __( 'Rate limited. Please wait a moment and try again.', 'adshimmer' ),
                ];

            case 500:
            case 502:
            case 503:
                return [
                    'success' => false,
                    'error'   => __( 'AI service temporarily unavailable. Please try again later.', 'adshimmer' ),
                ];

            default:
                $message = ! empty( $error_message )
                    ? $error_message
                    /* translators: %d: HTTP status code */
                    : sprintf( __( 'API error (HTTP %d)', 'adshimmer' ), $status_code );

                return [
                    'success' => false,
                    'error'   => $message,
                ];
        }
    }
}
