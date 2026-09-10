<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

/**
 * Manages WordPress page templates and block template assignments.
 */
final class TemplateManager
{
    /**
     * Get available templates for the active theme.
     *
     * @return array<string, string> Keyed by template slug (e.g. 'default' => 'Default Template')
     */
    public function getAvailableTemplates(): array
    {
        if (function_exists('wp_get_theme')) {
            $templates = wp_get_theme()->get_page_templates();
            return array_merge(['default' => 'Default Template'], $templates);
        }

        return [
            'default'    => 'Default Template',
            'full-width' => 'Full Width',
            'blank'      => 'Blank Canvas',
        ];
    }

    /**
     * Get the active template for a given post or page.
     */
    public function getPostTemplate(int $postId): string
    {
        if (function_exists('get_post_meta')) {
            $template = get_post_meta($postId, '_wp_page_template', true);
            if (!empty($template)) {
                return (string) $template;
            }
        }
        return 'default';
    }

    /**
     * Assign a template to a post or page.
     */
    public function setPostTemplate(int $postId, string $template): bool
    {
        $available = $this->getAvailableTemplates();
        if (!isset($available[$template]) && $template !== 'default') {
            return false;
        }

        if (function_exists('update_post_meta')) {
            return update_post_meta($postId, '_wp_page_template', $template) !== false;
        }

        return true;
    }
}
