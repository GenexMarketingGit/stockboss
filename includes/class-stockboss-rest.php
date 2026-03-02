<?php

if (! defined('ABSPATH')) {
    exit;
}

class Stockboss_Rest
{
    private Stockboss_Options $options;
    private Stockboss_OpenRouter $openrouter;

    public function __construct(Stockboss_Options $options, Stockboss_OpenRouter $openrouter)
    {
        $this->options = $options;
        $this->openrouter = $openrouter;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'stockboss/v1',
            '/generate-image',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'generate_image'],
                'permission_callback' => [$this, 'can_generate_images'],
                'args' => [
                    'prompt' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'useCustomSystemPrompt' => [
                        'required' => false,
                        'type' => 'boolean',
                    ],
                    'systemPrompt' => [
                        'required' => false,
                        'type' => 'string',
                    ],
                    'referenceImageIds' => [
                        'required' => false,
                        'type' => 'array',
                    ],
                ],
            ]
        );
    }

    public function can_generate_images(): bool
    {
        return current_user_can('upload_files') && current_user_can('edit_posts');
    }

    public function generate_image(WP_REST_Request $request)
    {
        $result = $this->openrouter->generate_and_store([
            'prompt' => $request->get_param('prompt'),
            'useCustomSystemPrompt' => (bool) $request->get_param('useCustomSystemPrompt'),
            'systemPrompt' => $request->get_param('systemPrompt'),
            'referenceImageIds' => $request->get_param('referenceImageIds'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'attachmentId' => $result['attachmentId'],
                'url' => $result['url'],
                'requestedModel' => $result['requestedModel'] ?? null,
                'modelUsed' => $result['modelUsed'] ?? null,
                'fallbackUsed' => ! empty($result['fallbackUsed']),
            ],
            200
        );
    }
}
