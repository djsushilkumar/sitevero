<?php

declare(strict_types=1);

namespace Sitevero\Mcp\Handlers;

use Sitevero\Capabilities\CapabilityRegistry;
use Sitevero\Capabilities\Elementor\ElementorModule;
use Sitevero\Capabilities\Elementor\FreeProDetector;

/**
 * Handler for the sitevero_discover universal tool.
 */
final class DiscoverHandler
{
    private CapabilityRegistry $registry;

    public function __construct(CapabilityRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Execute discovery.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments = []): array
    {
        return [
            'environment'  => $this->getEnvironmentDetails(),
            'builders'     => $this->getBuilderDetails(),
            'capabilities' => $this->registry->getDiscoveryCatalog(),
            'user_context' => $this->getUserContext(),
        ];
    }

    /**
     * Gather environment details.
     *
     * @return array<string, mixed>
     */
    private function getEnvironmentDetails(): array
    {
        global $wp_version;

        $wpVer = $wp_version ?? (function_exists('get_bloginfo') ? get_bloginfo('version') : 'unknown');
        $isMultisite = function_exists('is_multisite') && is_multisite();

        $activeTheme = [
            'name'           => 'unknown',
            'stylesheet'     => 'unknown',
            'is_block_theme' => false,
        ];

        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            $activeTheme = [
                'name'           => $theme->get('Name') ?: 'unknown',
                'stylesheet'     => $theme->get_stylesheet(),
                'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
            ];
        }

        $activePlugins = [];
        $inactiveCount = 0;

        if (function_exists('get_option')) {
            $rawActive = get_option('active_plugins', []);
            if (is_array($rawActive)) {
                $activePlugins = $rawActive;
            }
        }

        if (function_exists('get_plugins')) {
            $allPlugins = get_plugins();
            $inactiveCount = max(0, count($allPlugins) - count($activePlugins));
        }

        return [
            'wordpress_version' => $wpVer,
            'php_version'       => PHP_VERSION,
            'multisite'         => $isMultisite,
            'active_theme'      => $activeTheme,
            'installed_plugins' => [
                'active_count'   => count($activePlugins),
                'inactive_count' => $inactiveCount,
                'active'         => array_values($activePlugins),
            ],
        ];
    }

    /**
     * Gather builder detection details.
     *
     * @return array<string, mixed>
     */
    private function getBuilderDetails(): array
    {
        $elementorMod = $this->registry->get('elementor.manage_page');
        $detector = ($elementorMod instanceof ElementorModule)
            ? $elementorMod->getDetector()
            : new FreeProDetector();

        $elementorActive = $detector->isActive();

        $elementor = [
            'installed'   => $detector->isInstalled(),
            'active'      => $elementorActive,
            'version'     => $detector->getVersion(),
            'is_pro'      => $detector->isProActive(),
            'pro_version' => $detector->getProVersion(),
            'generation'  => $detector->detectGeneration(),
        ];

        $gutenberg = [
            'active'      => function_exists('parse_blocks') && function_exists('serialize_blocks'),
            'block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
        ];

        return [
            'elementor' => $elementor,
            'gutenberg' => $gutenberg,
        ];
    }

    /**
     * Gather current authenticated user context.
     *
     * @return array<string, mixed>
     */
    private function getUserContext(): array
    {
        if (!function_exists('wp_get_current_user')) {
            return [
                'user_id'            => 0,
                'user_login'         => 'system_cli',
                'roles'              => ['administrator'],
                'can_manage_options' => true,
                'can_edit_posts'     => true,
            ];
        }

        $user = wp_get_current_user();

        if ($user === null || $user->ID === 0) {
            return [
                'user_id'            => 0,
                'user_login'         => 'anonymous',
                'roles'              => [],
                'can_manage_options' => false,
                'can_edit_posts'     => false,
            ];
        }

        return [
            'user_id'            => $user->ID,
            'user_login'         => $user->user_login,
            'roles'              => array_values($user->roles),
            'can_manage_options' => function_exists('current_user_can') && current_user_can('manage_options'),
            'can_edit_posts'     => function_exists('current_user_can') && current_user_can('edit_posts'),
        ];
    }
}
