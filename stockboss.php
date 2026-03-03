<?php
/**
 * Plugin Name: Stockboss
 * Description: Generate and standardize stock images directly in Gutenberg using OpenRouter image models.
 * Version: 0.1.6
 * Author: Genex Marketing Agency Ltd.
 * Text Domain: stockboss
 */

if (! defined('ABSPATH')) {
    exit;
}

define('STOCKBOSS_VERSION', '0.1.6');
define('STOCKBOSS_PLUGIN_FILE', __FILE__);
define('STOCKBOSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STOCKBOSS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once STOCKBOSS_PLUGIN_DIR . 'includes/class-stockboss-options.php';
require_once STOCKBOSS_PLUGIN_DIR . 'includes/class-stockboss-openrouter.php';
require_once STOCKBOSS_PLUGIN_DIR . 'includes/class-stockboss-rest.php';
require_once STOCKBOSS_PLUGIN_DIR . 'includes/class-stockboss-block.php';

final class Stockboss_Plugin
{
    private Stockboss_Options $options;

    public function __construct()
    {
        $this->options = new Stockboss_Options();

        add_action('init', [$this, 'register_block']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_block(): void
    {
        $block = new Stockboss_Block($this->options);
        $block->register();
    }

    public function register_rest_routes(): void
    {
        $openrouter_client = new Stockboss_OpenRouter($this->options);
        $rest = new Stockboss_Rest($this->options, $openrouter_client);
        $rest->register_routes();
    }
}

new Stockboss_Plugin();
