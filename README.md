# Stockboss WordPress Plugin

Stockboss adds a Gutenberg block that generates images through OpenRouter and saves them into your WordPress Media Library.

## Features

- Default model presets:
  - `google/gemini-3.1-flash-image-preview` (highest quality)
  - `bytedance-seed/seedream-4.5` (cost efficient)
- Global system prompt and global reference images (Settings > Stockboss)
- Per-block prompt + optional custom system prompt override
- Per-block reference images
- Per-block **system prompt reference images** (available in free mode too)
- Animated editor UI with live preview

## Install

1. Copy this plugin folder into `wp-content/plugins/stockboss`.
2. Activate **Stockboss** in WordPress Plugins.
3. Open **Settings > Stockboss**, add your OpenRouter API key, and save.
4. In the block editor, insert **Stockboss Image** and generate images.

## Notes

- Generated images are imported into the Media Library with prompt-based titles.
- The plugin sends generation requests server-side; API keys are never exposed to the editor client.
