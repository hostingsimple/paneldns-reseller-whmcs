<?php
/**
 * Test bootstrap. The shared/ classes assume they're loaded inside WHMCS,
 * which defines the WHMCS constant and provides logModuleCall(). We stub
 * both so the classes can be tested in isolation.
 */

if (!defined('WHMCS')) {
    define('WHMCS', '8.0.0-test');
}

if (!function_exists('logModuleCall')) {
    function logModuleCall(): void { /* stub — no-op in tests */ }
}

if (!function_exists('logActivity')) {
    function logActivity(string $msg, int $userId = 0): void { /* stub */ }
}

if (!function_exists('localAPI')) {
    function localAPI(string $command, array $vars = []): array
    {
        // Tests can override this by pre-defining the function or by stubbing
        // PanelDnsWelcomeMail::send. Default returns success so welcome-email
        // tests don't fail.
        return ['result' => 'success'];
    }
}

// Stub WHMCS Cache namespace so LicenceCheck's optional Cache calls don't fatal.
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

// Load the production shared/ classes.
require __DIR__ . '/../shared/PanelDnsApi.php';
require __DIR__ . '/../shared/LicenceCheck.php';
require __DIR__ . '/../shared/WelcomeMail.php';
require __DIR__ . '/../shared/DriftSync.php';
