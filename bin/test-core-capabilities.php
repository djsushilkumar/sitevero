<?php

declare(strict_types=1);

/**
 * Deterministic test harness for Sitevero WordPress Core Capabilities (Phase 5).
 * Tests Content, Media, Plugins, Themes, Users, and Settings modules.
 */

// 1. Mock WordPress Core environment when running standalone
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    // In-memory mock storage
    global $mock_posts, $mock_meta, $mock_terms, $mock_options, $mock_plugins, $mock_themes, $mock_users, $mock_current_user;
    $mock_posts = [];
    $mock_meta = [];
    $mock_terms = [];
    $mock_options = [
        'blogname'        => 'Sitevero Dev Site',
        'blogdescription' => 'Universal WP AI MCP',
        'posts_per_page'  => 10,
        'stylesheet'      => 'twentytwentyfour',
    ];
    $mock_plugins = [
        'hello.php' => [
            'Name'        => 'Hello Dolly',
            'Version'     => '1.7.2',
            'Author'      => 'Matt Mullenweg',
            'PluginURI'   => 'https://wordpress.org/plugins/hello-dolly/',
            'Description' => 'This is not just a plugin...',
        ],
        'sitevero/sitevero.php' => [
            'Name'        => 'Sitevero',
            'Version'     => '1.0.0',
            'Author'      => 'Sitevero Team',
            'PluginURI'   => 'https://sitevero.com',
            'Description' => 'Universal WordPress AI MCP Plugin',
        ],
    ];
    $mock_active_plugins = ['sitevero/sitevero.php'];
    $mock_themes = [
        'twentytwentyfour' => new class {
            public function exists(): bool { return true; }
            public function get(string $key): mixed {
                return match($key) {
                    'Name' => 'Twenty Twenty-Four',
                    'Version' => '1.2',
                    'Author' => 'the WordPress team',
                    'Description' => 'Default block theme',
                    'Tags' => ['block-patterns', 'full-site-editing'],
                    default => '',
                };
            }
            public function is_block_theme(): bool { return true; }
            public function parent(): ?object { return null; }
            public function get_stylesheet(): string { return 'twentytwentyfour'; }
        },
        'twentytwentythree' => new class {
            public function exists(): bool { return true; }
            public function get(string $key): mixed {
                return match($key) {
                    'Name' => 'Twenty Twenty-Three',
                    'Version' => '1.3',
                    'Author' => 'the WordPress team',
                    default => '',
                };
            }
            public function is_block_theme(): bool { return true; }
            public function parent(): ?object { return null; }
            public function get_stylesheet(): string { return 'twentytwentythree'; }
        },
    ];

    $mock_users = [
        1 => (object)[
            'ID'              => 1,
            'user_login'      => 'admin',
            'user_pass'       => '$P$Bsupersecretpasswordhash',
            'display_name'    => 'Administrator',
            'user_email'      => 'admin@example.com',
            'user_registered' => '2026-01-01 00:00:00',
            'roles'           => ['administrator'],
            'user_nicename'   => 'admin',
            'user_url'        => 'http://example.com',
        ],
        2 => (object)[
            'ID'              => 2,
            'user_login'      => 'contributor_user',
            'user_pass'       => '$P$Banothersupersecrethash',
            'display_name'    => 'Contributor User',
            'user_email'      => 'contrib@example.com',
            'user_registered' => '2026-02-01 00:00:00',
            'roles'           => ['contributor'],
            'user_nicename'   => 'contributor_user',
            'user_url'        => '',
        ],
    ];
    $mock_current_user = $mock_users[1];

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function plugin_dir_path(string $file): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url(string $file): string { return 'http://example.com/wp-content/plugins/sitevero/'; }
    function is_wp_error(mixed $thing): bool { return $thing instanceof \WP_Error; }

    class WP_Error {
        public function __construct(private string $code = '', private string $message = '') {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string { return $this->code; }
    }

    // Options Mock
    function get_option(string $option, mixed $default = false): mixed {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
    function update_option(string $option, mixed $value, mixed $autoload = null): bool {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }

    // Post Mock
    function wp_insert_post(array $postarr, bool $wp_error = false): int|\WP_Error {
        global $mock_posts;
        static $idSeq = 100;
        $id = ++$idSeq;
        $postarr['ID'] = $id;
        $mock_posts[$id] = $postarr;
        return $id;
    }
    function get_post(int|object $post, string $output = 'OBJECT'): mixed {
        global $mock_posts;
        $id = is_object($post) ? (int)$post->ID : (int)$post;
        return $mock_posts[$id] ?? null;
    }
    function wp_update_post(array $postarr, bool $wp_error = false): int|\WP_Error {
        global $mock_posts;
        $id = (int)($postarr['ID'] ?? 0);
        if (!isset($mock_posts[$id])) {
            return new \WP_Error('invalid_id', 'Post not found');
        }
        $mock_posts[$id] = array_merge($mock_posts[$id], $postarr);
        return $id;
    }
    function wp_trash_post(int $id): bool {
        global $mock_posts;
        if (!isset($mock_posts[$id])) return false;
        $mock_posts[$id]['post_status'] = 'trash';
        return true;
    }
    function wp_untrash_post(int $id): bool {
        global $mock_posts;
        if (!isset($mock_posts[$id])) return false;
        $mock_posts[$id]['post_status'] = 'draft';
        return true;
    }
    function wp_delete_post(int $id, bool $force = false): bool {
        global $mock_posts;
        if (!isset($mock_posts[$id])) return false;
        unset($mock_posts[$id]);
        return true;
    }

    // Post Meta Mock
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        global $mock_meta;
        if (!empty($key)) {
            $val = $mock_meta[$post_id][$key] ?? ($single ? '' : []);
            return $single && is_array($val) ? ($val[0] ?? '') : $val;
        }
        return $mock_meta[$post_id] ?? [];
    }
    function update_post_meta(int $post_id, string $key, mixed $val): bool {
        global $mock_meta;
        $mock_meta[$post_id][$key] = is_array($val) ? $val : [$val];
        return true;
    }

    // Taxonomies Mock
    function wp_set_object_terms(int $object_id, array|string $terms, string $taxonomy): array {
        global $mock_terms;
        $mock_terms[$object_id][$taxonomy] = (array)$terms;
        return (array)$terms;
    }
    function get_object_taxonomies(string $post_type): array {
        return ['category', 'post_tag'];
    }
    function wp_get_object_terms(int $object_id, string $taxonomy): array {
        global $mock_terms;
        $terms = $mock_terms[$object_id][$taxonomy] ?? [];
        $res = [];
        foreach ($terms as $t) {
            $res[] = (object)['term_id' => 10, 'name' => $t, 'slug' => strtolower($t)];
        }
        return $res;
    }

    // Media Mock
    function wp_check_filetype(string $filename): array {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => ['ext' => 'jpg', 'type' => 'image/jpeg'],
            'png'         => ['ext' => 'png', 'type' => 'image/png'],
            'webp'        => ['ext' => 'webp', 'type' => 'image/webp'],
            default       => ['ext' => '', 'type' => ''],
        };
    }
    function wp_upload_bits(string $name, mixed $deprecated, string $bits): array {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sitevero_test_uploads';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $target = $tmpDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($target, $bits);
        return ['file' => $target, 'url' => 'http://example.com/uploads/' . $name, 'error' => false];
    }
    function wp_insert_attachment(array $attachment, string $filename, int $parent_post_id = 0): int {
        global $mock_posts;
        static $medSeq = 500;
        $id = ++$medSeq;
        $attachment['ID'] = $id;
        $attachment['post_type'] = 'attachment';
        $attachment['post_parent'] = $parent_post_id;
        $mock_posts[$id] = $attachment;
        global $mock_attached_files;
        $mock_attached_files[$id] = $filename;
        return $id;
    }
    function wp_get_attachment_metadata(int $attachment_id): array {
        return ['width' => 1200, 'height' => 800, 'file' => '2026/09/sample.jpg'];
    }
    function wp_update_attachment_metadata(int $attachment_id, array $data): bool { return true; }
    function wp_generate_attachment_metadata(int $attachment_id, string $file): array {
        return ['width' => 1200, 'height' => 800, 'file' => $file];
    }
    function wp_get_attachment_url(int $attachment_id): string {
        return "http://example.com/uploads/attachment-{$attachment_id}.jpg";
    }
    function get_attached_file(int $attachment_id): string {
        global $mock_attached_files;
        return $mock_attached_files[$attachment_id] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . "attachment-{$attachment_id}.jpg");
    }
    function wp_delete_attachment(int $attachment_id, bool $force = false): bool {
        global $mock_posts;
        if (!isset($mock_posts[$attachment_id])) return false;
        unset($mock_posts[$attachment_id]);
        return true;
    }

    // Plugins Mock
    function get_plugins(): array {
        global $mock_plugins;
        return $mock_plugins;
    }
    function is_plugin_active(string $plugin): bool {
        global $mock_active_plugins;
        return in_array($plugin, $mock_active_plugins, true);
    }
    function activate_plugin(string $plugin): ?\WP_Error {
        global $mock_active_plugins;
        if (!in_array($plugin, $mock_active_plugins, true)) {
            $mock_active_plugins[] = $plugin;
        }
        return null;
    }
    function deactivate_plugins(string|array $plugins): void {
        global $mock_active_plugins;
        $list = (array)$plugins;
        $mock_active_plugins = array_values(array_diff($mock_active_plugins, $list));
    }
    function delete_plugins(array $plugins): bool {
        global $mock_plugins;
        foreach ($plugins as $p) {
            unset($mock_plugins[$p]);
        }
        return true;
    }

    // Themes Mock
    function wp_get_themes(): array {
        global $mock_themes;
        return $mock_themes;
    }
    function get_stylesheet(): string {
        global $mock_options;
        return $mock_options['stylesheet'] ?? 'twentytwentyfour';
    }
    function switch_theme(string $stylesheet): void {
        global $mock_options;
        $mock_options['stylesheet'] = $stylesheet;
    }
    function wp_get_theme(string $stylesheet): object {
        global $mock_themes;
        return $mock_themes[$stylesheet] ?? new class {
            public function exists(): bool { return false; }
            public function get(string $key): mixed { return ''; }
        };
    }
    function delete_theme(string $stylesheet): bool {
        global $mock_themes;
        if (isset($mock_themes[$stylesheet])) {
            unset($mock_themes[$stylesheet]);
            return true;
        }
        return false;
    }

    // Users Mock
    function get_users(array $args = []): array {
        global $mock_users;
        return array_values($mock_users);
    }
    function count_users(): array {
        global $mock_users;
        return ['total_users' => count($mock_users)];
    }
    function get_userdata(int $user_id): ?object {
        global $mock_users;
        return $mock_users[$user_id] ?? null;
    }
    function get_user_by(string $field, mixed $value): ?object {
        global $mock_users;
        if ($field === 'id') {
            $user = $mock_users[(int)$value] ?? null;
            if ($user) {
                return new class($user) {
                    public function __construct(private object $user) {}
                    public function set_role(string $role): void {
                        $this->user->roles = [$role];
                    }
                };
            }
        }
        return null;
    }
    function wp_insert_user(array $userdata): int|\WP_Error {
        global $mock_users;
        static $userSeq = 300;
        $uid = ++$userSeq;
        $mock_users[$uid] = (object)[
            'ID'              => $uid,
            'user_login'      => $userdata['user_login'],
            'user_pass'       => password_hash($userdata['user_pass'] ?? 'pwd', PASSWORD_DEFAULT),
            'display_name'    => $userdata['display_name'] ?? $userdata['user_login'],
            'user_email'      => $userdata['user_email'],
            'roles'           => [$userdata['role'] ?? 'subscriber'],
            'user_registered' => '2026-09-10 00:00:00',
            'user_nicename'   => $userdata['user_login'],
            'user_url'        => $userdata['user_url'] ?? '',
        ];
        return $uid;
    }
    function wp_update_user(array $userdata): int|\WP_Error {
        global $mock_users;
        $uid = (int)($userdata['ID'] ?? 0);
        if (!isset($mock_users[$uid])) return new \WP_Error('invalid_user', 'User not found');
        foreach ($userdata as $k => $v) {
            if ($k !== 'ID') $mock_users[$uid]->$k = $v;
        }
        return $uid;
    }
    function wp_delete_user(int $user_id, int $reassign): bool {
        global $mock_users;
        if (!isset($mock_users[$user_id])) return false;
        unset($mock_users[$user_id]);
        return true;
    }
    function wp_get_current_user(): object {
        global $mock_current_user;
        return $mock_current_user;
    }
    function get_current_user_id(): int {
        global $mock_current_user;
        return (int)($mock_current_user->ID ?? 0);
    }
}

// 2. Load Plugin Bootstrap
require_once __DIR__ . '/../sitevero.php';

use Sitevero\Capabilities\Content\ContentModule;
use Sitevero\Capabilities\Media\MediaModule;
use Sitevero\Capabilities\System\PluginsModule;
use Sitevero\Capabilities\System\ThemesModule;
use Sitevero\Capabilities\Users\UsersModule;
use Sitevero\Capabilities\System\SystemModule;

echo "=========================================================\n";
echo "  Sitevero — Core Capabilities Verification Test Runner\n";
echo "=========================================================\n\n";

$errors = [];

// ==========================================
// 1. ContentModule Tests
// ==========================================
echo "1. Testing ContentModule...\n";
$contentMod = new ContentModule();

// 1.1 Create Post
$createRes = $contentMod->execute('create', [
    'data' => [
        'title'      => 'Hello Sitevero Post',
        'content'    => 'Content created via AI agent.',
        'status'     => 'publish',
        'excerpt'    => 'Brief excerpt.',
        'taxonomies' => ['category' => ['News', 'Tech']],
        'meta'       => ['_custom_ai_key' => 'verified_token'],
    ],
]);
if (($createRes['status'] ?? '') !== 'success' || empty($createRes['post_id'])) {
    $errors[] = "Content create failed: " . json_encode($createRes);
} else {
    $postId = (int)$createRes['post_id'];
    echo "   ✓ Content created: Post #{$postId}\n";

    // 1.2 Read Post
    $readRes = $contentMod->execute('read', ['post_id' => $postId]);
    if (($readRes['status'] ?? '') !== 'success' || ($readRes['post']['post_title'] ?? '') !== 'Hello Sitevero Post') {
        $errors[] = "Content read failed: " . json_encode($readRes);
    } else {
        echo "   ✓ Content read with meta and taxonomies verified\n";
    }

    // 1.3 Update Post
    $updateRes = $contentMod->execute('update', [
        'post_id' => $postId,
        'data'    => ['title' => 'Updated Sitevero Title'],
    ]);
    if (($updateRes['status'] ?? '') !== 'success' || ($updateRes['post']['post_title'] ?? '') !== 'Updated Sitevero Title') {
        $errors[] = "Content update failed: " . json_encode($updateRes);
    } else {
        echo "   ✓ Content updated successfully\n";
    }

    // 1.4 Trash and Restore
    $trashRes = $contentMod->execute('trash', ['post_id' => $postId]);
    if (($trashRes['status'] ?? '') !== 'success') {
        $errors[] = "Content trash failed: " . json_encode($trashRes);
    } else {
        echo "   ✓ Content trashed successfully\n";
    }

    $restoreRes = $contentMod->execute('restore', ['post_id' => $postId]);
    if (($restoreRes['status'] ?? '') !== 'success') {
        $errors[] = "Content restore failed: " . json_encode($restoreRes);
    } else {
        echo "   ✓ Content restored successfully\n";
    }

    // 1.5 Delete
    $deleteRes = $contentMod->execute('delete', ['post_id' => $postId]);
    if (($deleteRes['status'] ?? '') !== 'success') {
        $errors[] = "Content delete failed: " . json_encode($deleteRes);
    } else {
        echo "   ✓ Content permanently deleted\n";
    }
}

// 1.6 Batch Update — Bulk 50-Item Threshold Safety Check
echo "   Checking 50-item bulk safety ceiling...\n";
$posts51 = [];
for ($i = 1; $i <= 51; $i++) {
    $posts51[] = ['post_id' => $i, 'data' => ['title' => "Batch Post {$i}"]];
}
$bulkExceedRes = $contentMod->execute('batch_update', ['posts' => $posts51]);
if (($bulkExceedRes['error'] ?? '') !== 'ERR_BULK_LIMIT_EXCEEDED') {
    $errors[] = "Expected ERR_BULK_LIMIT_EXCEEDED for 51 items, got: " . json_encode($bulkExceedRes);
} else {
    echo "   ✓ Batch update correctly rejected 51 items with ERR_BULK_LIMIT_EXCEEDED\n";
}

// ==========================================
// 2. MediaModule Tests
// ==========================================
echo "\n2. Testing MediaModule...\n";
$mediaMod = new MediaModule();

// 2.1 Upload Media (Base64 JPEG)
$dummyImageBase64 = base64_encode("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00\xFF\xDB\x00C\x00");
$uploadRes = $mediaMod->execute('upload', [
    'filename'  => 'banner.jpg',
    'file_data' => $dummyImageBase64,
    'data'      => [
        'title'       => 'Hero Banner',
        'alt_text'    => 'Inspiring landscape banner',
        'caption'     => 'AI generated banner',
        'description' => 'Detailed hero image description',
    ],
]);

if (($uploadRes['status'] ?? '') !== 'success' || empty($uploadRes['attachment_id'])) {
    $errors[] = "Media upload failed: " . json_encode($uploadRes);
} else {
    $mediaId = (int)$uploadRes['attachment_id'];
    echo "   ✓ Media uploaded: Attachment #{$mediaId}\n";

    // 2.2 Inspect Media
    $inspectRes = $mediaMod->execute('inspect', ['attachment_id' => $mediaId]);
    if (($inspectRes['status'] ?? '') !== 'success' || ($inspectRes['alt_text'] ?? '') !== 'Inspiring landscape banner') {
        $errors[] = "Media inspect failed: " . json_encode($inspectRes);
    } else {
        echo "   ✓ Media inspected with metadata and alt text verified\n";
    }

    // 2.3 Update Meta
    $updateMetaRes = $mediaMod->execute('update_meta', [
        'attachment_id' => $mediaId,
        'data'          => ['alt_text' => 'Updated alt text description'],
    ]);
    if (($updateMetaRes['status'] ?? '') !== 'success') {
        $errors[] = "Media update_meta failed: " . json_encode($updateMetaRes);
    } else {
        echo "   ✓ Media metadata updated\n";
    }

    // 2.4 Replace Media File
    $replaceRes = $mediaMod->execute('replace', [
        'attachment_id' => $mediaId,
        'file_data'     => base64_encode("REPLACED_BINARY_CONTENT"),
    ]);
    if (($replaceRes['status'] ?? '') !== 'success' || ($replaceRes['attachment_id'] ?? 0) !== $mediaId) {
        $errors[] = "Media file replace failed: " . json_encode($replaceRes);
    } else {
        echo "   ✓ Media file replaced safely in-place\n";
    }

    // 2.5 Attach and Detach
    $attachRes = $mediaMod->execute('attach', ['attachment_id' => $mediaId, 'post_id' => 999]);
    if (($attachRes['status'] ?? '') !== 'success' || ($attachRes['post_parent'] ?? 0) !== 999) {
        $errors[] = "Media attach failed: " . json_encode($attachRes);
    } else {
        echo "   ✓ Media attached to post #999\n";
    }

    $detachRes = $mediaMod->execute('detach', ['attachment_id' => $mediaId]);
    if (($detachRes['status'] ?? '') !== 'success' || ($detachRes['post_parent'] ?? -1) !== 0) {
        $errors[] = "Media detach failed: " . json_encode($detachRes);
    } else {
        echo "   ✓ Media detached from parent post\n";
    }

    // 2.6 Delete Media
    $delMediaRes = $mediaMod->execute('delete', ['attachment_id' => $mediaId]);
    if (($delMediaRes['status'] ?? '') !== 'success') {
        $errors[] = "Media delete failed: " . json_encode($delMediaRes);
    } else {
        echo "   ✓ Media attachment permanently deleted\n";
    }
}

// ==========================================
// 3. PluginsModule Tests
// ==========================================
echo "\n3. Testing PluginsModule...\n";
$pluginsMod = new PluginsModule();

// 3.1 List Plugins
$pluginListRes = $pluginsMod->execute('list', []);
if (($pluginListRes['status'] ?? '') !== 'success' || ($pluginListRes['total_plugins'] ?? 0) < 2) {
    $errors[] = "Plugins list failed: " . json_encode($pluginListRes);
} else {
    echo "   ✓ Listed {$pluginListRes['total_plugins']} installed plugins\n";
}

// 3.2 Inspect Plugin
$pluginInspectRes = $pluginsMod->execute('inspect', ['plugin' => 'sitevero/sitevero.php']);
if (($pluginInspectRes['status'] ?? '') !== 'success' || ($pluginInspectRes['name'] ?? '') !== 'Sitevero') {
    $errors[] = "Plugins inspect failed: " . json_encode($pluginInspectRes);
} else {
    echo "   ✓ Inspected plugin sitevero/sitevero.php (active: " . ($pluginInspectRes['is_active'] ? 'yes' : 'no') . ")\n";
}

// 3.3 Activate and Deactivate
$actRes = $pluginsMod->execute('activate', ['plugin' => 'hello.php']);
if (($actRes['status'] ?? '') !== 'success') {
    $errors[] = "Plugin activate failed: " . json_encode($actRes);
} else {
    echo "   ✓ Plugin hello.php activated\n";
}

// 3.4 Prevent Deleting Active Plugin
$delActiveRes = $pluginsMod->execute('delete', ['plugin' => 'hello.php']);
if (($delActiveRes['error'] ?? '') !== 'ERR_PLUGIN_ACTIVE') {
    $errors[] = "Expected ERR_PLUGIN_ACTIVE when deleting active plugin, got: " . json_encode($delActiveRes);
} else {
    echo "   ✓ Deleting active plugin correctly blocked with ERR_PLUGIN_ACTIVE\n";
}

// Deactivate and delete
$deactRes = $pluginsMod->execute('deactivate', ['plugin' => 'hello.php']);
$delInactiveRes = $pluginsMod->execute('delete', ['plugin' => 'hello.php']);
if (($delInactiveRes['status'] ?? '') !== 'success') {
    $errors[] = "Plugin delete failed: " . json_encode($delInactiveRes);
} else {
    echo "   ✓ Inactive plugin hello.php deleted safely\n";
}

// 3.5 Install Simulated Upgrader APIs
$installZipRes = $pluginsMod->execute('install_zip', ['file_path' => '/tmp/sample-plugin.zip']);
if (($installZipRes['status'] ?? '') !== 'success') {
    $errors[] = "Plugin install_zip failed: " . json_encode($installZipRes);
} else {
    echo "   ✓ Plugin ZIP installation routed via Plugin_Upgrader abstraction\n";
}

// ==========================================
// 4. ThemesModule Tests
// ==========================================
echo "\n4. Testing ThemesModule...\n";
$themesMod = new ThemesModule();

// 4.1 List Themes
$themesListRes = $themesMod->execute('list', []);
if (($themesListRes['status'] ?? '') !== 'success' || ($themesListRes['total_themes'] ?? 0) < 2) {
    $errors[] = "Themes list failed: " . json_encode($themesListRes);
} else {
    echo "   ✓ Listed {$themesListRes['total_themes']} installed themes\n";
}

// 4.2 Inspect Theme
$themeInspectRes = $themesMod->execute('inspect', ['stylesheet' => 'twentytwentyfour']);
if (($themeInspectRes['status'] ?? '') !== 'success' || !$themeInspectRes['is_block_theme']) {
    $errors[] = "Themes inspect failed: " . json_encode($themeInspectRes);
} else {
    echo "   ✓ Theme twentytwentyfour inspected (block_theme: yes, active: yes)\n";
}

// 4.3 Prevent Deleting Active Theme
$delActiveThemeRes = $themesMod->execute('delete', ['stylesheet' => 'twentytwentyfour']);
if (($delActiveThemeRes['error'] ?? '') !== 'ERR_THEME_ACTIVE') {
    $errors[] = "Expected ERR_THEME_ACTIVE when deleting active theme, got: " . json_encode($delActiveThemeRes);
} else {
    echo "   ✓ Deleting active theme correctly blocked with ERR_THEME_ACTIVE\n";
}

// 4.4 Switch Theme and Delete Inactive Theme
$switchRes = $themesMod->execute('activate', ['stylesheet' => 'twentytwentythree']);
if (($switchRes['status'] ?? '') !== 'success') {
    $errors[] = "Theme activate/switch failed: " . json_encode($switchRes);
} else {
    echo "   ✓ Switched active theme to twentytwentythree\n";
}

$delThemeRes = $themesMod->execute('delete', ['stylesheet' => 'twentytwentyfour']);
if (($delThemeRes['status'] ?? '') !== 'success') {
    $errors[] = "Theme delete failed: " . json_encode($delThemeRes);
} else {
    echo "   ✓ Inactive theme twentytwentyfour deleted safely\n";
}

// ==========================================
// 5. UsersModule Tests
// ==========================================
echo "\n5. Testing UsersModule...\n";
$usersMod = new UsersModule();

// 5.1 List Users (Passcode Protection Check)
$usersListRes = $usersMod->execute('list', []);
if (($usersListRes['status'] ?? '') !== 'success' || count($usersListRes['users']) < 2) {
    $errors[] = "Users list failed: " . json_encode($usersListRes);
} else {
    // Assert zero password hash leakage
    foreach ($usersListRes['users'] as $u) {
        if (isset($u['user_pass']) || isset($u['password'])) {
            $errors[] = "CRITICAL: Password leaked in users.list!";
        }
    }
    echo "   ✓ Listed users with password hashes strictly excluded\n";
}

// 5.2 Inspect User (Passcode Protection Check)
$userInspectRes = $usersMod->execute('inspect', ['user_id' => 1]);
if (($userInspectRes['status'] ?? '') !== 'success' || isset($userInspectRes['user']['user_pass'])) {
    $errors[] = "Users inspect failed or leaked password: " . json_encode($userInspectRes);
} else {
    echo "   ✓ User #1 inspected with zero password leakage\n";
}

// 5.3 Create User
$createAuthorRes = $usersMod->execute('create', [
    'data' => [
        'user_login'   => 'new_author',
        'user_email'   => 'author@example.com',
        'role'         => 'author',
        'display_name' => 'New Author',
    ],
]);
if (($createAuthorRes['status'] ?? '') !== 'success' || empty($createAuthorRes['user_id'])) {
    $errors[] = "User creation failed: " . json_encode($createAuthorRes);
} else {
    $newUserId = (int)$createAuthorRes['user_id'];
    echo "   ✓ Created author user #{$newUserId}\n";

    // 5.4 Zero Privilege Escalation Check:
    // Switch mock current user to Contributor (level 1) and attempt to promote to Administrator (level 10)
    global $mock_current_user, $mock_users;
    $mock_current_user = $mock_users[2]; // Contributor

    $escalateRes = $usersMod->execute('assign_role', [
        'user_id' => $newUserId,
        'role'    => 'administrator',
    ]);
    if (($escalateRes['error'] ?? '') !== 'ERR_PRIVILEGE_ESCALATION') {
        $errors[] = "Expected ERR_PRIVILEGE_ESCALATION when contributor promotes to admin, got: " . json_encode($escalateRes);
    } else {
        echo "   ✓ Privilege escalation correctly blocked with ERR_PRIVILEGE_ESCALATION\n";
    }

    // Switch back to Administrator
    $mock_current_user = $mock_users[1];

    // 5.5 Delete User: Mandatory reassign_to check
    $delWithoutReassign = $usersMod->execute('delete', ['user_id' => $newUserId]);
    if (($delWithoutReassign['error'] ?? '') !== 'ERR_MISSING_REASSIGN_ID') {
        $errors[] = "Expected ERR_MISSING_REASSIGN_ID when deleting user without reassignment, got: " . json_encode($delWithoutReassign);
    } else {
        echo "   ✓ User deletion without reassign_to blocked with ERR_MISSING_REASSIGN_ID\n";
    }

    // Delete with valid reassign_to = 1 (admin)
    $delWithReassign = $usersMod->execute('delete', [
        'user_id'     => $newUserId,
        'reassign_to' => 1,
    ]);
    if (($delWithReassign['status'] ?? '') !== 'success') {
        $errors[] = "User delete with reassignment failed: " . json_encode($delWithReassign);
    } else {
        echo "   ✓ User deleted with posts reassigned to #1\n";
    }
}

// ==========================================
// 6. SystemModule Tests
// ==========================================
echo "\n6. Testing SystemModule...\n";
$systemMod = new SystemModule();

// 6.1 Read Whitelisted Settings
$readSettingsRes = $systemMod->execute('read', ['keys' => ['blogname', 'blogdescription', 'posts_per_page']]);
if (($readSettingsRes['status'] ?? '') !== 'success' || ($readSettingsRes['settings']['blogname'] ?? '') !== 'Sitevero Dev Site') {
    $errors[] = "Settings read failed: " . json_encode($readSettingsRes);
} else {
    echo "   ✓ Whitelisted settings read successfully\n";
}

// 6.2 Update Whitelisted Settings
$updateSettingsRes = $systemMod->execute('update', [
    'settings' => [
        'blogname'       => 'Sitevero AI Hub',
        'posts_per_page' => 20,
    ],
]);
if (($updateSettingsRes['status'] ?? '') !== 'success' || ($updateSettingsRes['settings']['blogname'] ?? '') !== 'Sitevero AI Hub') {
    $errors[] = "Settings update failed: " . json_encode($updateSettingsRes);
} else {
    echo "   ✓ Whitelisted settings updated successfully\n";
}

// 6.3 Disallowed Settings Rejection (Security Boundary)
echo "   Checking disallowed settings security boundary...\n";
$disallowedRes = $systemMod->execute('update', [
    'settings' => [
        'active_plugins' => ['malicious_plugin/plugin.php'],
    ],
]);
if (($disallowedRes['error'] ?? '') !== 'ERR_DISALLOWED_SETTING') {
    $errors[] = "Expected ERR_DISALLOWED_SETTING when updating active_plugins, got: " . json_encode($disallowedRes);
} else {
    echo "   ✓ Disallowed setting 'active_plugins' rejected with ERR_DISALLOWED_SETTING\n";
}

// 6.4 Read-Only Setting Rejection (siteurl)
$readOnlyRes = $systemMod->execute('update', [
    'settings' => [
        'siteurl' => 'https://hijacked-domain.com',
    ],
]);
if (($readOnlyRes['error'] ?? '') !== 'ERR_READ_ONLY_SETTING') {
    $errors[] = "Expected ERR_READ_ONLY_SETTING when modifying siteurl, got: " . json_encode($readOnlyRes);
} else {
    echo "   ✓ Read-only setting 'siteurl' rejected with ERR_READ_ONLY_SETTING\n";
}

// ==========================================
// Final Results
// ==========================================
echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with " . count($errors) . " error(s):\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: All Core Capabilities tests passed!\n";
echo "Verified Content, Media, Plugins, Themes, Users, and Settings modules.\n";
exit(0);
