<?php
/**
 * Test bootstrap. The module assumes it is loaded inside WHMCS, which defines the
 * WHMCS constant and provides logModuleCall(), Capsule, the cache, etc. We stub those
 * so the classes can be tested in isolation.
 *
 * The module is loaded from its SHIPPED layout: shared/*.php is copied into the
 * module's lib/ exactly as build.php does, and every class is loaded through the
 * module's own require_once chain. A require that escapes lib/ fails here, as it
 * would in the release ZIP.
 */

if (!defined('WHMCS')) {
    define('WHMCS', '8.0.0-test');
}

/** Calls to logModuleCall / logActivity, for assertions. */
$GLOBALS['__module_log']   = [];
$GLOBALS['__activity_log'] = [];

if (!function_exists('logModuleCall')) {
    function logModuleCall(...$args): void { $GLOBALS['__module_log'][] = $args; }
}

if (!function_exists('logActivity')) {
    function logActivity(string $msg, int $userId = 0): void { $GLOBALS['__activity_log'][] = $msg; }
}

if (!function_exists('localAPI')) {
    function localAPI(string $command, array $vars = []): array
    {
        $GLOBALS['__local_api'][] = [$command, $vars];
        return ['result' => 'success'];
    }
}

// Stub WHMCS Cache namespace so the module's optional cache calls don't fatal.
if (!class_exists('\\WHMCS\\Cache\\Store', false)) {
    eval('
        namespace WHMCS\\Cache;
        class Store {
            private static array $store = [];
            public function get(string $key) { return self::$store[$key] ?? null; }
            public function put(string $key, $value, int $ttl): void { self::$store[$key] = $value; }
            public static function clear(): void { self::$store = []; }
        }
    ');
}

if (!function_exists('decrypt')) {
    function decrypt(string $s): string { return $s; } // stub — no encryption in tests
}

require __DIR__ . '/Support/FakeCapsule.php';

// The embedded manager starts a PHP session if none is active (WHMCS always has one).
// Start an in-memory one up front, as WHMCS would have, so tests can set $_SESSION
// freely and no session file or cookie header is involved.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cache_limiter', '');
    session_set_save_handler(new class implements SessionHandlerInterface {
        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string|false { return ''; }
        public function write(string $id, string $data): bool { return true; }
        public function destroy(string $id): bool { return true; }
        public function gc(int $max): int|false { return 0; }
    }, true);
    @session_start();
}

// Same step as build.php #1: shared/ → the module's lib/ (those copies are gitignored).
$libDir = __DIR__ . '/../modules/servers/paneldnsreseller/lib';
foreach (['PanelDnsApi.php', 'LicenceCheck.php', 'DriftSync.php', 'WelcomeMail.php'] as $f) {
    if (!copy(__DIR__ . '/../shared/' . $f, $libDir . '/' . $f)) {
        fwrite(STDERR, "bootstrap: could not copy shared/{$f} into lib/\n");
        exit(1);
    }
}

// Load the module through its own require chain.
require_once $libDir . '/PanelDnsResellerService.php';
require_once $libDir . '/DriftSync.php';
require_once $libDir . '/PanelDnsResellerMigration.php';

require __DIR__ . '/Support/FakeApi.php';
