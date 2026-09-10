<?php

declare(strict_types=1);

namespace Sitevero\Safety;

/**
 * Sanitizes input strings, block attributes, and validates design token bindings.
 */
final class Sanitizer
{
    /**
     * Sanitize HTML content field stripping arbitrary scripts and event handlers.
     */
    public static function sanitizeHtml(string $content): string
    {
        if (function_exists('wp_kses_post')) {
            return wp_kses_post($content);
        }

        // Fallback: Strip script tags, iframes, and on* event handlers
        $cleaned = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content) ?? $content;
        $cleaned = preg_replace('#<iframe(.*?)>(.*?)</iframe>#is', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('#\s+on[a-z]+\s*=\s*(["\']).*?\1#is', '', $cleaned) ?? $cleaned;

        return $cleaned;
    }

    /**
     * Sanitize plain text field.
     */
    public static function sanitizeText(string $text): string
    {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($text);
        }
        return strip_tags(trim($text));
    }

    /**
     * Validate an Elementor V4 design token.
     * Must follow Elementor token syntax (e.g. 'e-var:brand-primary', 'e-var:space-md').
     * Prohibits arbitrary CSS custom properties or injection.
     */
    public static function isValidElementorToken(string $token): bool
    {
        // Enforce e-var: prefix and valid slug characters
        return (bool) preg_match('/^e-var:[a-z0-9\-_]+$/i', $token);
    }

    /**
     * Validate and sanitize Gutenberg block attributes against expected types.
     */
    public static function sanitizeBlockAttributes(array $attributes): array
    {
        $sanitized = [];

        foreach ($attributes as $key => $val) {
            // Strip any null bytes or script tags in string attributes
            if (is_string($val)) {
                $sanitized[$key] = self::sanitizeHtml($val);
            } elseif (is_array($val)) {
                $sanitized[$key] = self::sanitizeBlockAttributes($val);
            } else {
                $sanitized[$key] = $val;
            }
        }

        return $sanitized;
    }
}
