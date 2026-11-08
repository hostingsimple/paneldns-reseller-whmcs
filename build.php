<?php
/**
 * Build script — paneldns-reseller-whmcs.
 *
 * Copies shared/*.php into modules/servers/paneldnsreseller/lib/ so the
 * release ZIP is self-contained, checks that every require in the module
 * resolves inside the module folder, then zips modules/ into
 * dist/paneldns-reseller-whmcs-{VERSION}.zip.
 *
 * Usage:
 *   php build.php                           # uses the default version below
 *   RELEASE_VERSION=1.9.3 php build.php    # CI passes the stripped tag
 *   PANELDNS_DIST_DIR=/tmp/out php build.php   # write the ZIP elsewhere (tests)
 */

$version = getenv('RELEASE_VERSION') ?: '1.9.3';
$root    = __DIR__;
$module  = 'paneldnsreseller';
$modDir  = $root . '/modules/servers/' . $module;
$libDir  = $modDir . '/lib';
$distDir = getenv('PANELDNS_DIST_DIR') ?: $root . '/dist';

if (!preg_match('/^[0-9A-Za-z.\-]+$/', $version)) {
    fwrite(STDERR, "ERROR: invalid RELEASE_VERSION '{$version}'\n");
    exit(1);
}

// 1. Copy shared/ files into lib/ so each deployed module is self-contained.
//    build.php is the source of truth; do not commit these copies to the repo.
$sharedFiles = ['PanelDnsApi.php', 'LicenceCheck.php', 'DriftSync.php', 'WelcomeMail.php'];
foreach ($sharedFiles as $file) {
    $src = $root . '/shared/' . $file;
    $dst = $libDir . '/' . $file;
    if (!copy($src, $dst)) {
        fwrite(STDERR, "ERROR: could not copy {$src} -> {$dst}\n");
        exit(1);
    }
    echo "  copied  shared/{$file} -> lib/{$file}\n";
}

// 2. BUILD-ZIP-01: every `require/include … __DIR__ . '…'` must resolve to a file inside
//    the module folder. Up to v1.9.2 two requires pointed at ../../../shared/, which the
//    ZIP does not contain, and the module fataled on load.
$modReal = realpath($modDir);
$problems = [];
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modDir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($phpFiles as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $src = (string) file_get_contents($f->getPathname());
    if (!preg_match_all('/\b(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*([\'"])(.+?)\1/', $src, $m)) continue;
    foreach ($m[2] as $rel) {
        $target = realpath(dirname($f->getPathname()) . $rel);
        if ($target === false || !str_starts_with($target, $modReal . DIRECTORY_SEPARATOR)) {
            $problems[] = substr($f->getPathname(), strlen($root) + 1) . " requires '{$rel}', which is not inside {$module}/";
        }
    }
}
if ($problems) {
    fwrite(STDERR, "ERROR: the module is not self-contained:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}
echo "  checked requires — all inside modules/servers/{$module}/\n";

// 3. Create dist/ if absent.
if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

// 4. Build the ZIP from modules/ only (shared/ is now inlined into lib/).
$zipFile = rtrim($distDir, '/\\') . '/paneldns-reseller-whmcs-' . $version . '.zip';
if (file_exists($zipFile)) {
    unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "ERROR: could not create {$zipFile}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/modules',
    RecursiveDirectoryIterator::SKIP_DOTS
));

$added = 0;
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $abs   = $file->getPathname();
    $local = 'modules' . substr($abs, strlen($root . '/modules'));
    $local = str_replace('\\', '/', $local);
    $zip->addFile($abs, $local);
    $added++;
}

$zip->close();
echo "  built   {$zipFile} ({$added} files)\n";
