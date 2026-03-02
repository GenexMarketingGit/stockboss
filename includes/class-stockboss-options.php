<?php

if (! defined('ABSPATH')) {
    exit;
}

class Stockboss_Options
{
    public const OPTION_KEY = 'stockboss_options';
    public const MODEL_HIGH_QUALITY = 'google/gemini-3.1-flash-image-preview';
    public const MODEL_COST_EFFICIENT = 'bytedance-seed/seedream-4.5';

    public function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function get_all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return wp_parse_args(
            is_array($stored) ? $stored : [],
            [
                'api_key' => '',
                'default_model' => self::MODEL_HIGH_QUALITY,
                'enable_model_fallback' => false,
                'global_system_prompt' => '',
                'global_reference_image_ids' => [],
            ]
        );
    }

    public function get(string $key, $default = null)
    {
        $options = $this->get_all();
        return $options[$key] ?? $default;
    }

    public function get_available_models(): array
    {
        return [
            self::MODEL_HIGH_QUALITY => __('Gemini 3.1 Flash Image Preview (Highest Quality)', 'stockboss'),
            self::MODEL_COST_EFFICIENT => __('Seedream 4.5 (Cost Efficient)', 'stockboss'),
        ];
    }

    public function is_valid_model(string $model): bool
    {
        return array_key_exists($model, $this->get_available_models());
    }

    public function register_settings(): void
    {
        register_setting(
            'stockboss_options_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->get_all(),
            ]
        );

        add_settings_section(
            'stockboss_main_section',
            __('Stockboss OpenRouter Settings', 'stockboss'),
            function () {
                echo '<p>' . esc_html__('Configure your default generation behavior and reusable style references.', 'stockboss') . '</p>';
            },
            'stockboss'
        );

        add_settings_field(
            'api_key',
            __('OpenRouter API Key', 'stockboss'),
            [$this, 'render_api_key_field'],
            'stockboss',
            'stockboss_main_section'
        );

        add_settings_field(
            'default_model',
            __('Default Model', 'stockboss'),
            [$this, 'render_default_model_field'],
            'stockboss',
            'stockboss_main_section'
        );

        add_settings_field(
            'enable_model_fallback',
            __('Fallback Model', 'stockboss'),
            [$this, 'render_enable_model_fallback_field'],
            'stockboss',
            'stockboss_main_section'
        );

        add_settings_field(
            'global_system_prompt',
            __('Global System Prompt', 'stockboss'),
            [$this, 'render_global_system_prompt_field'],
            'stockboss',
            'stockboss_main_section'
        );

        add_settings_field(
            'global_reference_image_ids',
            __('Global Reference Images', 'stockboss'),
            [$this, 'render_global_reference_images_field'],
            'stockboss',
            'stockboss_main_section'
        );
    }

    public function sanitize_options(array $input): array
    {
        $existing = $this->get_all();
        $output = $existing;

        $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

        $input_model = isset($input['default_model']) ? sanitize_text_field($input['default_model']) : self::MODEL_HIGH_QUALITY;
        $output['default_model'] = $this->is_valid_model($input_model) ? $input_model : self::MODEL_HIGH_QUALITY;
        $output['enable_model_fallback'] = ! empty($input['enable_model_fallback']);

        $output['global_system_prompt'] = isset($input['global_system_prompt'])
            ? sanitize_textarea_field($input['global_system_prompt'])
            : '';

        $reference_ids = [];
        if (isset($input['global_reference_image_ids'])) {
            if (is_array($input['global_reference_image_ids'])) {
                $reference_ids = array_map('absint', $input['global_reference_image_ids']);
            } else {
                $reference_ids = array_map('absint', explode(',', (string) $input['global_reference_image_ids']));
            }
        }

        $output['global_reference_image_ids'] = array_values(array_filter(array_unique($reference_ids)));

        return $output;
    }

    public function add_settings_page(): void
    {
        add_options_page(
            __('Stockboss', 'stockboss'),
            __('Stockboss', 'stockboss'),
            'manage_options',
            'stockboss',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Stockboss', 'stockboss') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields('stockboss_options_group');
        do_settings_sections('stockboss');
        submit_button(__('Save Settings', 'stockboss'));
        echo '</form>';
        echo '</div>';
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'settings_page_stockboss') {
            return;
        }

        wp_enqueue_media();

        wp_register_script(
            'stockboss-settings',
            false,
            ['jquery'],
            STOCKBOSS_VERSION,
            true
        );

        wp_enqueue_script('stockboss-settings');

        wp_add_inline_script(
            'stockboss-settings',
            $this->get_settings_inline_script()
        );

        wp_register_style('stockboss-settings-style', false, [], STOCKBOSS_VERSION);
        wp_enqueue_style('stockboss-settings-style');
        wp_add_inline_style('stockboss-settings-style', $this->get_settings_inline_style());
    }

    private function get_settings_inline_script(): string
    {
        return <<<'JS'
jQuery(function ($) {
    const $input = $('#stockboss-global-reference-image-ids');
    const $preview = $('#stockboss-global-reference-preview');

    function updatePreview(ids) {
        $preview.empty();

        if (!ids.length) {
            $preview.append('<p class="stockboss-muted">No global references selected.</p>');
            return;
        }

        ids.forEach(function (id) {
            wp.media.attachment(id).fetch().then(function () {
                const attachment = wp.media.attachment(id);
                const sizes = attachment.get('sizes') || {};
                const thumbnail = sizes.thumbnail || {};
                const url = thumbnail.url || attachment.get('url');

                if (!url) {
                    return;
                }

                $preview.append(
                    $('<div class="stockboss-admin-thumb" />').append(
                        $('<img />').attr('src', url).attr('alt', 'Reference image')
                    )
                );
            });
        });
    }

    $('#stockboss-select-global-reference-images').on('click', function (event) {
        event.preventDefault();

        const frame = wp.media({
            title: 'Select Global Reference Images',
            button: { text: 'Use Images' },
            multiple: true,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            const selection = frame.state().get('selection');
            const ids = [];

            selection.each(function (attachment) {
                ids.push(attachment.id);
            });

            $input.val(ids.join(','));
            updatePreview(ids);
        });

        frame.open();
    });

    $('#stockboss-clear-global-reference-images').on('click', function (event) {
        event.preventDefault();
        $input.val('');
        updatePreview([]);
    });

    const initialIds = ($input.val() || '').split(',').map(function (id) {
        return parseInt(id, 10);
    }).filter(Boolean);

    updatePreview(initialIds);
});
JS;
    }

    private function get_settings_inline_style(): string
    {
        return <<<'CSS'
#stockboss-global-reference-preview {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.stockboss-admin-thumb {
    width: 80px;
    height: 80px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #d8dde4;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
}
.stockboss-admin-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.stockboss-muted {
    color: #5f6b7a;
    font-style: italic;
}
.stockboss-field-hint {
    color: #5f6b7a;
    margin-top: 6px;
}
CSS;
    }

    public function render_api_key_field(): void
    {
        $value = (string) $this->get('api_key', '');
        printf(
            '<input type="password" class="regular-text" name="%1$s[api_key]" value="%2$s" autocomplete="off" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($value)
        );
        echo '<p class="stockboss-field-hint">' . esc_html__('Used server-side only. Stored in WordPress options.', 'stockboss') . '</p>';
    }

    public function render_default_model_field(): void
    {
        $value = (string) $this->get('default_model', self::MODEL_HIGH_QUALITY);
        $models = $this->get_available_models();

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[default_model]">';
        foreach ($models as $model_id => $label) {
            echo '<option value="' . esc_attr($model_id) . '" ' . selected($value, $model_id, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_enable_model_fallback_field(): void
    {
        $value = (bool) $this->get('enable_model_fallback', false);
        printf(
            '<label><input type="checkbox" name="%1$s[enable_model_fallback]" value="1" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_KEY),
            checked($value, true, false),
            esc_html__('If the default model fails temporarily (e.g. rate-limit), retry with Seedream.', 'stockboss')
        );
        echo '<p class="stockboss-field-hint">' . esc_html__('Off = strict mode (always use selected default model).', 'stockboss') . '</p>';
    }

    public function render_global_system_prompt_field(): void
    {
        $value = (string) $this->get('global_system_prompt', '');
        printf(
            '<textarea name="%1$s[global_system_prompt]" rows="6" class="large-text code">%2$s</textarea>',
            esc_attr(self::OPTION_KEY),
            esc_textarea($value)
        );
        echo '<p class="stockboss-field-hint">' . esc_html__('Used by default in standardized mode and as fallback in free mode unless overridden per block.', 'stockboss') . '</p>';
    }

    public function render_global_reference_images_field(): void
    {
        $ids = $this->get('global_reference_image_ids', []);

        if (! is_array($ids)) {
            $ids = [];
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));

        echo '<input type="hidden" id="stockboss-global-reference-image-ids" name="' . esc_attr(self::OPTION_KEY) . '[global_reference_image_ids]" value="' . esc_attr(implode(',', $ids)) . '" />';
        echo '<button type="button" class="button" id="stockboss-select-global-reference-images">' . esc_html__('Select Images', 'stockboss') . '</button> ';
        echo '<button type="button" class="button button-secondary" id="stockboss-clear-global-reference-images">' . esc_html__('Clear', 'stockboss') . '</button>';
        echo '<p class="stockboss-field-hint">' . esc_html__('These references are always available to generated images unless you remove them.', 'stockboss') . '</p>';
        echo '<div id="stockboss-global-reference-preview"></div>';
    }
}
