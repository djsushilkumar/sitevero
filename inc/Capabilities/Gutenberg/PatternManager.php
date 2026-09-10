<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

/**
 * Manages discovery, retrieval, and parsing of WordPress Block Patterns.
 */
final class PatternManager
{
    /**
     * Get available registered patterns.
     *
     * @param string|null $category
     * @return array<int, array<string, mixed>>
     */
    public function getPatterns(?string $category = null): array
    {
        $patterns = [];

        if (class_exists('\WP_Block_Patterns_Registry')) {
            $registered = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
            foreach ($registered as $p) {
                if ($category !== null && !in_array($category, $p['categories'] ?? [], true)) {
                    continue;
                }
                $patterns[] = [
                    'name'        => $p['name'],
                    'title'       => $p['title'] ?? $p['name'],
                    'description' => $p['description'] ?? '',
                    'categories'  => $p['categories'] ?? [],
                ];
            }
        }

        // Add built-in starter patterns if empty or outside WP
        if (empty($patterns)) {
            $patterns[] = [
                'name'        => 'sitevero/hero-card',
                'title'       => 'Hero Card with CTA',
                'description' => 'A clean heading, paragraph, and button pattern.',
                'categories'  => ['featured', 'call-to-action'],
            ];
            $patterns[] = [
                'name'        => 'sitevero/two-columns',
                'title'       => 'Two Column Feature Grid',
                'description' => 'Side-by-side feature comparison columns.',
                'categories'  => ['columns'],
            ];
        }

        return $patterns;
    }

    /**
     * Retrieve pattern content markup by name.
     */
    public function getPatternContent(string $name): ?string
    {
        if (class_exists('\WP_Block_Patterns_Registry')) {
            $registry = \WP_Block_Patterns_Registry::get_instance();
            if ($registry->is_registered($name)) {
                $p = $registry->get_registered($name);
                return $p['content'] ?? null;
            }
        }

        return match ($name) {
            'sitevero/hero-card' => "<!-- wp:heading {\"level\":1} -->\n<h1>Build Fast with Sitevero</h1>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Empower your AI agents with safe, universal WordPress control.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:buttons -->\n<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"#\">Get Started</a></div>\n<!-- /wp:button -->\n<!-- /wp:buttons -->",
            'sitevero/two-columns' => "<!-- wp:columns -->\n<!-- wp:column -->\n<!-- wp:heading {\"level\":3} -->\n<h3>Feature A</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Description of feature A.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:column -->\n<!-- wp:column -->\n<!-- wp:heading {\"level\":3} -->\n<h3>Feature B</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Description of feature B.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:column -->\n<!-- /wp:columns -->",
            default => null,
        };
    }
}
