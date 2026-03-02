<?php

if (! defined('ABSPATH')) {
    exit;
}

class Stockboss_OpenRouter
{
    private Stockboss_Options $options;

    public function __construct(Stockboss_Options $options)
    {
        $this->options = $options;
    }

    public function generate_and_store(array $args)
    {
        $api_key = (string) $this->options->get('api_key', '');
        if ($api_key === '') {
            return new WP_Error('stockboss_missing_api_key', __('OpenRouter API key is not configured.', 'stockboss'), ['status' => 500]);
        }

        $model = (string) ($args['model'] ?? $this->options->get('default_model', Stockboss_Options::MODEL_HIGH_QUALITY));
        if (! $this->options->is_valid_model($model)) {
            return new WP_Error('stockboss_invalid_model', __('Invalid image model selected.', 'stockboss'), ['status' => 400]);
        }

        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return new WP_Error('stockboss_missing_prompt', __('A prompt is required.', 'stockboss'), ['status' => 400]);
        }

        $mode = (string) ($args['mode'] ?? 'standardized');
        $use_custom_system_prompt = ! empty($args['useCustomSystemPrompt']);
        $original_prompt = $prompt;

        $system_prompt = $use_custom_system_prompt
            ? trim((string) ($args['systemPrompt'] ?? ''))
            : trim((string) $this->options->get('global_system_prompt', ''));

        $global_reference_ids = $this->normalize_ids($this->options->get('global_reference_image_ids', []));
        $user_reference_ids = $this->normalize_ids($args['referenceImageIds'] ?? []);
        $system_reference_ids = $this->normalize_ids($args['systemReferenceImageIds'] ?? []);

        $all_user_reference_ids = array_values(array_unique(array_merge($global_reference_ids, $user_reference_ids)));
        $all_system_reference_ids = array_values(array_unique($system_reference_ids));

        $attempt_models = $this->build_model_attempt_order($model);
        $last_error = null;
        $attempt_count = count($attempt_models);

        foreach ($attempt_models as $attempt_index => $attempt_model) {
            $attempt_prompt = $original_prompt;
            $attempt_system_prompt = $system_prompt;
            $attempt_user_reference_ids = $all_user_reference_ids;
            $attempt_system_reference_ids = $all_system_reference_ids;

            if ($this->is_image_only_model($attempt_model)) {
                // Image-only models are more reliable when system instructions are folded into the user prompt.
                $attempt_prompt = $this->merge_system_prompt_into_user_prompt($attempt_prompt, $attempt_system_prompt);
                $attempt_system_prompt = '';
                $attempt_user_reference_ids = array_values(array_unique(array_merge($attempt_user_reference_ids, $attempt_system_reference_ids)));
                $attempt_system_reference_ids = [];
            }

            $payload = $this->build_chat_payload(
                $attempt_model,
                $attempt_prompt,
                $attempt_system_prompt,
                $attempt_user_reference_ids,
                $attempt_system_reference_ids,
                $mode,
                $this->modalities_for_model($attempt_model)
            );

            $decoded = $this->request_openrouter_generation($api_key, $payload);
            if (is_wp_error($decoded)) {
                $last_error = $decoded;
                $has_more_attempts = $attempt_index < ($attempt_count - 1);

                if ($has_more_attempts && $this->should_retry_with_fallback($decoded)) {
                    continue;
                }

                return $decoded;
            }

            $image_payload = $this->extract_image_payload($decoded);
            if (! $image_payload) {
                $assistant_message = $this->extract_assistant_message($decoded);
                $error_message = __('The model response did not include an image.', 'stockboss');
                if ($assistant_message !== '') {
                    $error_message .= ' ' . sprintf(
                        __('Assistant message: %s', 'stockboss'),
                        $assistant_message
                    );
                }

                return new WP_Error(
                    'stockboss_no_image_returned',
                    $error_message,
                    ['status' => 502]
                );
            }

            $saved = $this->save_generated_image($image_payload, $original_prompt);
            if (is_wp_error($saved)) {
                return $saved;
            }

            $saved['requestedModel'] = $model;
            $saved['modelUsed'] = $attempt_model;
            $saved['fallbackUsed'] = $attempt_model !== $model;
            return $saved;
        }

        if (is_wp_error($last_error)) {
            return $last_error;
        }

        return new WP_Error(
            'stockboss_openrouter_request_failed',
            __('Image generation failed after attempting configured models.', 'stockboss'),
            ['status' => 502]
        );
    }

    private function request_openrouter_generation(string $api_key, array $payload)
    {
        $response = wp_remote_post(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => home_url('/'),
                    'X-Title' => get_bloginfo('name') ?: 'WordPress Stockboss',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'stockboss_openrouter_error',
                $response->get_error_message(),
                [
                    'status' => 502,
                    'response_code' => 0,
                ]
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $decoded_array = is_array($decoded) ? $decoded : [];

        if ($status_code >= 300) {
            $message = $this->extract_openrouter_error_message($decoded_array, $status_code);
            $error_data = [
                'status' => 502,
                'response_code' => $status_code,
            ];

            if (! empty($decoded_array['error']) && is_array($decoded_array['error'])) {
                $error_data['openrouter_error'] = $decoded_array['error'];
            }

            return new WP_Error('stockboss_openrouter_request_failed', $message, $error_data);
        }

        return $decoded_array;
    }

    private function build_chat_payload(
        string $model,
        string $prompt,
        string $system_prompt,
        array $reference_ids,
        array $system_reference_ids,
        string $mode,
        array $modalities
    ): array {
        $messages = [];

        if ($system_prompt !== '' || ! empty($system_reference_ids)) {
            $system_content = [];

            if ($system_prompt !== '') {
                $system_content[] = [
                    'type' => 'text',
                    'text' => $system_prompt,
                ];
            }

            foreach ($system_reference_ids as $attachment_id) {
                $source = $this->attachment_to_data_url($attachment_id);
                if ($source) {
                    $system_content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $source,
                        ],
                    ];
                }
            }

            if (! empty($system_content)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $system_content,
                ];
            }
        }

        $user_content = [
            [
                'type' => 'text',
                'text' => $this->build_user_prompt($prompt, $mode),
            ],
        ];

        foreach ($reference_ids as $attachment_id) {
            $source = $this->attachment_to_data_url($attachment_id);
            if ($source) {
                $user_content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $source,
                    ],
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $user_content,
        ];

        return [
            'model' => $model,
            'messages' => $messages,
            'modalities' => $modalities,
            'stream' => false,
        ];
    }

    private function build_user_prompt(string $prompt, string $mode): string
    {
        if ($mode === 'free') {
            return "Generate one polished image from this prompt. Return only the generated image output. Prompt: {$prompt}";
        }

        return "Generate one polished stock-ready image that follows the provided system style guidance and references. Return only the generated image output. Prompt: {$prompt}";
    }

    private function normalize_ids($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = array_map('absint', $ids);
        return array_values(array_filter(array_unique($normalized)));
    }

    private function attachment_to_data_url(int $attachment_id): ?string
    {
        $file_path = get_attached_file($attachment_id);

        if (! $file_path || ! file_exists($file_path)) {
            return null;
        }

        $mime_type = get_post_mime_type($attachment_id);
        if (! is_string($mime_type) || strpos($mime_type, 'image/') !== 0) {
            return null;
        }

        $bytes = file_get_contents($file_path);
        if ($bytes === false) {
            return null;
        }

        return 'data:' . $mime_type . ';base64,' . base64_encode($bytes);
    }

    private function extract_image_payload(array $response): ?array
    {
        // OpenAI-style image generation response.
        if (isset($response['data'][0]['b64_json'])) {
            return [
                'type' => 'base64',
                'data' => (string) $response['data'][0]['b64_json'],
                'mime' => 'image/png',
            ];
        }

        if (isset($response['data'][0]['url'])) {
            return $this->normalize_url_or_data_url((string) $response['data'][0]['url']);
        }

        // Chat completion with structured content.
        $choice = $response['choices'][0]['message'] ?? null;
        if (is_array($choice)) {
            if (isset($choice['images'][0]['image_url']['url'])) {
                return $this->normalize_url_or_data_url((string) $choice['images'][0]['image_url']['url']);
            }

            if (isset($choice['images'][0]['b64_json'])) {
                return [
                    'type' => 'base64',
                    'data' => (string) $choice['images'][0]['b64_json'],
                    'mime' => 'image/png',
                ];
            }

            if (isset($choice['content']) && is_array($choice['content'])) {
                foreach ($choice['content'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    if (isset($item['type']) && $item['type'] === 'image_url' && isset($item['image_url']['url'])) {
                        return $this->normalize_url_or_data_url((string) $item['image_url']['url']);
                    }

                    if (isset($item['type']) && $item['type'] === 'image_base64' && isset($item['image_base64'])) {
                        $base64 = (string) $item['image_base64'];
                        if (strpos($base64, 'data:image/') === 0) {
                            return $this->normalize_url_or_data_url($base64);
                        }

                        return [
                            'type' => 'base64',
                            'data' => $base64,
                            'mime' => 'image/png',
                        ];
                    }
                }
            }

            if (isset($choice['content']) && is_string($choice['content'])) {
                if (preg_match('#https?://[^\s\)\]\}<>\"]+#', $choice['content'], $matches)) {
                    return $this->normalize_url_or_data_url((string) $matches[0]);
                }
            }
        }

        return null;
    }

    private function is_image_only_model(string $model): bool
    {
        return $model === Stockboss_Options::MODEL_COST_EFFICIENT;
    }

    private function build_model_attempt_order(string $requested_model): array
    {
        $models = [$requested_model];
        $fallback_enabled = (bool) $this->options->get('enable_model_fallback', false);

        if (! $fallback_enabled) {
            return $models;
        }

        if (
            $requested_model === Stockboss_Options::MODEL_HIGH_QUALITY &&
            $this->options->is_valid_model(Stockboss_Options::MODEL_COST_EFFICIENT)
        ) {
            $models[] = Stockboss_Options::MODEL_COST_EFFICIENT;
        }

        return array_values(array_unique($models));
    }

    private function should_retry_with_fallback(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $response_code = is_array($data) && isset($data['response_code']) ? (int) $data['response_code'] : 0;

        if (in_array($response_code, [408, 409, 429, 500, 502, 503, 504], true)) {
            return true;
        }

        $message = strtolower($error->get_error_message());
        $retryable_markers = [
            'rate-limit',
            'rate limit',
            'temporarily',
            'upstream',
            'overloaded',
            'try again',
            'timeout',
        ];

        foreach ($retryable_markers as $marker) {
            if (strpos($message, $marker) !== false) {
                return true;
            }
        }

        if (is_array($data) && ! empty($data['openrouter_error']['metadata']['raw'])) {
            $raw = $data['openrouter_error']['metadata']['raw'];
            $raw_string = is_scalar($raw) ? strtolower((string) $raw) : strtolower((string) wp_json_encode($raw));
            foreach ($retryable_markers as $marker) {
                if (strpos($raw_string, $marker) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function modalities_for_model(string $model): array
    {
        if ($this->is_image_only_model($model)) {
            return ['image'];
        }

        return ['image', 'text'];
    }

    private function merge_system_prompt_into_user_prompt(string $prompt, string $system_prompt): string
    {
        if ($system_prompt === '') {
            return $prompt;
        }

        return "Style/system guidance:\n{$system_prompt}\n\nUser request:\n{$prompt}";
    }

    private function normalize_url_or_data_url(string $image_url): array
    {
        if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $image_url, $matches)) {
            return [
                'type' => 'base64',
                'data' => $matches[2],
                'mime' => $matches[1],
            ];
        }

        return [
            'type' => 'url',
            'data' => $image_url,
        ];
    }

    private function extract_openrouter_error_message(array $decoded, int $status_code): string
    {
        $message = sprintf(
            __('OpenRouter request failed (HTTP %d).', 'stockboss'),
            $status_code
        );

        if (! isset($decoded['error']) || ! is_array($decoded['error'])) {
            return $message;
        }

        $error = $decoded['error'];
        if (isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            $message = $error['message'];
        }

        if (isset($error['metadata']) && is_array($error['metadata'])) {
            $metadata = $error['metadata'];

            if (! empty($metadata['provider_name']) && is_string($metadata['provider_name'])) {
                $message .= ' ' . sprintf(
                    __('Provider: %s.', 'stockboss'),
                    $metadata['provider_name']
                );
            }

            if (array_key_exists('raw', $metadata)) {
                $raw_detail = is_scalar($metadata['raw'])
                    ? (string) $metadata['raw']
                    : wp_json_encode($metadata['raw']);
                if (is_string($raw_detail) && $raw_detail !== '') {
                    $message .= ' ' . sprintf(
                        __('Details: %s', 'stockboss'),
                        wp_html_excerpt($raw_detail, 300, '…')
                    );
                }
            }
        }

        return $message;
    }

    private function extract_assistant_message(array $decoded): string
    {
        $content = $decoded['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return wp_html_excerpt($content, 220, '…');
        }

        if (is_array($content)) {
            $text_fragments = [];
            foreach ($content as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (! empty($item['text']) && is_string($item['text'])) {
                    $text_fragments[] = $item['text'];
                }
            }

            if (! empty($text_fragments)) {
                return wp_html_excerpt(implode(' ', $text_fragments), 220, '…');
            }
        }

        return '';
    }

    private function save_generated_image(array $image_payload, string $prompt)
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $temp_file = null;

        if ($image_payload['type'] === 'url') {
            $temp_file = download_url($image_payload['data']);
            if (is_wp_error($temp_file)) {
                return new WP_Error('stockboss_download_error', $temp_file->get_error_message(), ['status' => 500]);
            }

            $filename = wp_basename(parse_url($image_payload['data'], PHP_URL_PATH) ?: 'stockboss-generated.png');
            if (! preg_match('/\.(png|jpg|jpeg|webp)$/i', $filename)) {
                $filename .= '.png';
            }

            $file_array = [
                'name' => sanitize_file_name($filename),
                'tmp_name' => $temp_file,
            ];
        } else {
            $bytes = base64_decode((string) $image_payload['data'], true);
            if ($bytes === false) {
                return new WP_Error('stockboss_base64_error', __('Could not decode generated image.', 'stockboss'), ['status' => 500]);
            }

            $temp_file = wp_tempnam('stockboss-generated');
            if (! $temp_file) {
                return new WP_Error('stockboss_temp_file_error', __('Could not create a temp file.', 'stockboss'), ['status' => 500]);
            }

            $write_result = file_put_contents($temp_file, $bytes);
            if ($write_result === false) {
                @unlink($temp_file);
                return new WP_Error('stockboss_temp_write_error', __('Could not write generated image data.', 'stockboss'), ['status' => 500]);
            }

            $extension = 'png';
            if (! empty($image_payload['mime']) && $image_payload['mime'] === 'image/jpeg') {
                $extension = 'jpg';
            }
            if (! empty($image_payload['mime']) && $image_payload['mime'] === 'image/webp') {
                $extension = 'webp';
            }

            $file_array = [
                'name' => 'stockboss-generated-' . gmdate('Ymd-His') . '.' . $extension,
                'tmp_name' => $temp_file,
            ];
        }

        $title = wp_trim_words($prompt, 8, '');
        if ($title === '') {
            $title = __('Stockboss Generated Image', 'stockboss');
        }

        $attachment_id = media_handle_sideload(
            $file_array,
            0,
            null,
            [
                'post_title' => $title,
            ]
        );

        if (is_wp_error($attachment_id)) {
            if (! empty($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            return new WP_Error('stockboss_media_error', $attachment_id->get_error_message(), ['status' => 500]);
        }

        return [
            'attachmentId' => (int) $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ];
    }
}
