<?php

if (! defined('ABSPATH')) {
    exit;
}

class Stockboss_Block
{
    private Stockboss_Options $options;

    public function __construct(Stockboss_Options $options)
    {
        $this->options = $options;
    }

    public function register(): void
    {
        wp_register_script(
            'stockboss-editor',
            STOCKBOSS_PLUGIN_URL . 'assets/js/editor.js',
            [
                'wp-blocks',
                'wp-element',
                'wp-components',
                'wp-block-editor',
                'wp-api-fetch',
                'wp-i18n',
                'wp-data',
                'wp-hooks',
                'wp-compose',
            ],
            STOCKBOSS_VERSION,
            true
        );

        wp_localize_script(
            'stockboss-editor',
            'StockbossConfig',
            [
                'restPath' => '/stockboss/v1/generate-image',
            ]
        );

        wp_register_style(
            'stockboss-editor-style',
            STOCKBOSS_PLUGIN_URL . 'assets/css/editor.css',
            [],
            STOCKBOSS_VERSION
        );

        wp_register_style(
            'stockboss-style',
            STOCKBOSS_PLUGIN_URL . 'assets/css/style.css',
            [],
            STOCKBOSS_VERSION
        );

        register_block_type('stockboss/image-generator', [
            'api_version' => 3,
            'editor_script' => 'stockboss-editor',
            'editor_style' => 'stockboss-editor-style',
            'style' => 'stockboss-style',
            'render_callback' => [$this, 'render'],
            'attributes' => [
                'imageId' => [
                    'type' => 'number',
                ],
                'imageUrl' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'alt' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'prompt' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'useCustomSystemPrompt' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
                'systemPrompt' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'referenceImageIds' => [
                    'type' => 'array',
                    'default' => [],
                ],
            ],
        ]);
    }

    public function render(array $attributes): string
    {
        $image_url = isset($attributes['imageUrl']) ? esc_url($attributes['imageUrl']) : '';
        if ($image_url === '') {
            return '';
        }

        $alt = isset($attributes['alt']) ? esc_attr($attributes['alt']) : '';

        return sprintf(
            '<figure class="wp-block-stockboss-image-generator"><img src="%1$s" alt="%2$s" loading="lazy" decoding="async" /></figure>',
            $image_url,
            $alt
        );
    }
}
