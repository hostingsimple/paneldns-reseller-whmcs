<?php

use PHPUnit\Framework\TestCase;

/**
 * BUILD-ZIP-01: the release ZIP must be self-contained. Builds it with build.php,
 * checks that every require inside it resolves to a file inside it, then extracts it
 * into a scratch folder and loads the module file and hooks.php in a fresh PHP
 * process, as WHMCS would. Up to v1.9.2 the ZIP required ../../../shared/, which it
 * does not contain, and fataled on load.
 *
 * @coversNothing
 */
final class BuildZipTest extends TestCase
{
    private static string $work;
    private static string $zip;
    private static array $build;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('ext-zip not available');
        }
        self::$work = sys_get_temp_dir() . '/paneldns-zip-test-' . bin2hex(random_bytes(4));
        mkdir(self::$work . '/dist', 0777, true);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build.php');
        $env = array_merge(getenv(), ['RELEASE_VERSION' => '0.0.0-test', 'PANELDNS_DIST_DIR' => self::$work . '/dist']);
        self::$build = self::exec($cmd, $env);
        self::$zip = self::$work . '/dist/paneldns-reseller-whmcs-0.0.0-test.zip';
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$work) && is_dir(self::$work)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$work, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir(self::$work);
        }
    }

    /** @return array{code:int, out:string, err:string} */
    private static function exec(string $cmd, ?array $env = null): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
    }

    /** @return array<string,string> entry name => content */
    private static function entries(): array
    {
        $z = new ZipArchive();
        $z->open(self::$zip);
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = $z->getNameIndex($i);
            $out[$name] = (string) $z->getFromIndex($i);
        }
        $z->close();
        return $out;
    }

    public function test_build_succeeds(): void
    {
        $this->assertSame(0, self::$build['code'], self::$build['out'] . self::$build['err']);
        $this->assertFileExists(self::$zip);
        $this->assertStringContainsString('checked requires', self::$build['out']);
    }

    public function test_zip_holds_only_the_renamed_module_with_its_lib_copies(): void
    {
        $names = array_keys(self::entries());
        foreach ($names as $n) {
            $this->assertStringStartsWith('modules/servers/paneldnsreseller/', $n);
        }
        foreach ([
            'paneldnsreseller.php', 'hooks.php',
            'lib/PanelDnsApi.php', 'lib/LicenceCheck.php', 'lib/DriftSync.php', 'lib/WelcomeMail.php',
            'lib/PanelDnsResellerService.php', 'lib/PanelDnsResellerHooks.php', 'lib/PanelDnsResellerMigration.php',
            'lib/EmbeddedDnsManager.php', 'templates/client/overview.tpl', 'templates/client/zone-import.tpl',
        ] as $required) {
            $this->assertContains('modules/servers/paneldnsreseller/' . $required, $names);
        }
    }

    public function test_every_require_in_the_zip_resolves_inside_the_zip(): void
    {
        $entries = self::entries();
        $checked = 0;
        foreach ($entries as $name => $src) {
            if (!str_ends_with($name, '.php')) continue;
            preg_match_all('/\b(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*([\'"])(.+?)\1/', $src, $m);
            foreach ($m[2] as $rel) {
                $target = self::normalise(dirname($name) . $rel);
                $this->assertArrayHasKey($target, $entries, "{$name} requires '{$rel}' → {$target}, which is not in the ZIP");
                $checked++;
            }
        }
        $this->assertGreaterThan(8, $checked);
    }

    public function test_lib_copies_match_shared(): void
    {
        $entries = self::entries();
        foreach (['PanelDnsApi.php', 'LicenceCheck.php', 'DriftSync.php', 'WelcomeMail.php'] as $f) {
            $this->assertSame(
                str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/shared/' . $f)),
                str_replace("\r\n", "\n", $entries['modules/servers/paneldnsreseller/lib/' . $f]),
                "lib/{$f} in the ZIP is not the shared/ copy"
            );
        }
    }

    public function test_extracted_zip_loads_in_plain_php(): void
    {
        $root = self::$work . '/whmcs';
        $z = new ZipArchive();
        $z->open(self::$zip);
        $z->extractTo($root);
        $z->close();

        $script = self::$work . '/load.php';
        file_put_contents($script, <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { fwrite(STDERR, "E{$no}: {$str} at {$file}:{$line}\n"); exit(2); });
define('WHMCS', 'zip-load-test');
$GLOBALS['hooks'] = [];
function add_hook($point, $priority, $fn) { $GLOBALS['hooks'][] = $point; }
function logModuleCall(...$a) {}
function logActivity(...$a) {}
$root = $argv[1];
require $root . '/modules/servers/paneldnsreseller/paneldnsreseller.php';
require $root . '/modules/servers/paneldnsreseller/hooks.php';
foreach (['MetaData', 'ConfigOptions', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount',
          'ChangePackage', 'ClientArea', 'AdminServicesTabFields', 'ListAccounts', 'AdminSingleSignOn',
          'ServiceSingleSignOn', 'UsageUpdate'] as $f) {
    if (!function_exists('paneldnsreseller_' . $f)) { fwrite(STDERR, "missing paneldnsreseller_{$f}\n"); exit(3); }
}
foreach (['PanelDnsResellerApi', 'PanelDnsResellerLicenceCheck', 'PanelDnsResellerWelcomeMail', 'PanelDnsResellerDriftSync',
          'PanelDnsResellerService', 'PanelDnsResellerHooks', 'PanelDnsResellerMigration', 'PanelDnsEmbeddedDnsManager'] as $c) {
    if (!class_exists($c, false)) { fwrite(STDERR, "missing class {$c}\n"); exit(4); }
}
if (!method_exists('PanelDnsResellerApi', 'getLegalVersion')) { fwrite(STDERR, "no getLegalVersion\n"); exit(5); }
echo 'LOADED ', paneldnsreseller_MetaData()['DisplayName'], ' hooks=', count($GLOBALS['hooks']), "\n";
PHP);

        $r = self::exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($root));
        $this->assertSame(0, $r['code'], "load failed:\n" . $r['out'] . $r['err']);
        $this->assertStringContainsString('LOADED PanelDNS (Reseller', $r['out']);
        $this->assertStringContainsString('hooks=11', $r['out']);
    }

    private static function normalise(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        return implode('/', $out);
    }
}
