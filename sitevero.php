<?php
/**
 * Plugin Name:       Sitevero
 * Plugin URI:        https://sitevero.com
 * Description:       Universal WordPress AI MCP Plugin. Connects MCP-compatible AI agents to WordPress capabilities and builders safely.
 * Version:           1.0.0-beta
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Sitevero Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitevero
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// 1. Strict PHP 8.1+ Version Gate
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Sitevero requires PHP 8.1 or higher to run. Please upgrade your PHP runtime.', 'sitevero') .
            '</p></div>';
    });

    if (isset($_GET['activate'])) {
        unset($_GET['activate']);
    }

    return;
}

// 2. Constants
define('SITEVERO_VERSION', '1.0.0-beta');
define('SITEVERO_FILE', __FILE__);
define('SITEVERO_PATH', plugin_dir_path(__FILE__));
define('SITEVERO_URL', plugin_dir_url(__FILE__));
define('SITEVERO_DB_VERSION', '1.0.0');

// 3. Built-in PSR-4 Autoloader (Zero Composer Dependency at runtime)
spl_autoload_register(static function (string $class): void {
    $prefix = 'Sitevero\\';
    $baseDir = SITEVERO_PATH . 'inc/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 4. Activation and Deactivation Hooks
register_activation_hook(__FILE__, static function (): void {
    \Sitevero\Core\Plugin::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \Sitevero\Core\Plugin::deactivate();
});

// 5. Bootstrap Plugin Lifecycle
\Sitevero\Core\Plugin::getInstance()->boot();
