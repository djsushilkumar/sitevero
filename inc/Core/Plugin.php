<?php

declare(strict_types=1);

namespace Sitevero\Core;

use Sitevero\Admin\AdminPage;
use Sitevero\Capabilities\CapabilityRegistry;
use Sitevero\Capabilities\Content\ContentModule;
use Sitevero\Capabilities\Elementor\ElementorModule;
use Sitevero\Capabilities\Gutenberg\GutenbergModule;
use Sitevero\Capabilities\Media\MediaModule;
use Sitevero\Capabilities\System\PluginsModule;
use Sitevero\Capabilities\System\SystemModule;
use Sitevero\Capabilities\System\ThemesModule;
use Sitevero\Capabilities\Users\UsersModule;
use Sitevero\Mcp\AdapterBridge;
use Sitevero\Storage\DatabaseMigrator;

/**
 * Main plugin orchestrator and singleton lifecycle manager.
 */
final class Plugin
{
    private static ?self $instance = null;
    private Container $container;
    private bool $booted = false;

    private function __construct()
    {
        $this->container = Container::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstrap plugin hooks.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 10);
        add_action('init', [$this, 'onInit'], 10);
    }

    /**
     * Initialization on plugins_loaded.
     */
    public function onPluginsLoaded(): void
    {
        $this->registerServices();
        $this->registerCapabilities();

        // Initialize MCP Adapter Bridge
        $adapterBridge = $this->container->get(AdapterBridge::class);
        if ($adapterBridge instanceof AdapterBridge) {
            $adapterBridge->init();
        }

        // Initialize Admin Dashboard
        $adminPage = $this->container->get(AdminPage::class);
        if ($adminPage instanceof AdminPage) {
            $adminPage->init();
        }
    }

    /**
     * Initialization on WordPress init hook.
     */
    public function onInit(): void
    {
        // Register daily maintenance cron if missing
        if (function_exists('wp_schedule_event') && function_exists('wp_next_scheduled')) {
            if (!wp_next_scheduled('sitevero_daily_maintenance_event')) {
                wp_schedule_event(time(), 'daily', 'sitevero_daily_maintenance_event');
            }
        }
    }

    /**
     * Register core services in Container.
     */
    private function registerServices(): void
    {
        $this->container->set(Container::class, $this->container);

        $this->container->bind(CapabilityRegistry::class, static function (): CapabilityRegistry {
            return new CapabilityRegistry();
        });

        $this->container->bind(AdapterBridge::class, function (Container $c): AdapterBridge {
            return new AdapterBridge($c->get(CapabilityRegistry::class));
        });

        $this->container->bind(AdminPage::class, function (Container $c): AdminPage {
            return new AdminPage($c->get(CapabilityRegistry::class));
        });
    }

    /**
     * Register built-in capability modules.
     */
    private function registerCapabilities(): void
    {
        $registry = $this->container->get(CapabilityRegistry::class);
        if (!$registry instanceof CapabilityRegistry) {
            return;
        }

        $registry->register(new ContentModule());
        $registry->register(new MediaModule());
        $registry->register(new ElementorModule());
        $registry->register(new GutenbergModule());
        $registry->register(new SystemModule());
        $registry->register(new PluginsModule());
        $registry->register(new ThemesModule());
        $registry->register(new UsersModule());
    }

    /**
     * Activation handler.
     */
    public static function activate(): void
    {
        DatabaseMigrator::migrate();
    }

    /**
     * Deactivation handler.
     */
    public static function deactivate(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('sitevero_daily_maintenance_event');
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
