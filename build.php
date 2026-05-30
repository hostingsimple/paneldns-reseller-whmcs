<?php
/**
 * Build script — paneldns-reseller-whmcs.
 *
 * Copies shared/*.php into modules/servers/paneldns-reseller/lib/ so the
 * release ZIP is self-contained, then zips modules/ into
 * dist/paneldns-reseller-whmcs-{VERSION}.zip.
 *
 * Usage:
 *   php build.php                           # uses hardcoded default version
 *   RELEASE_VERSION=1.4.0 php build.php    # CI passes the stripped tag
 */

$version = getenv('RELEASE_VERSION') ?: '1.4.0';
$root    = __DIR__;
$libDir  = $root . '/modules/servers/paneldns-reseller/lib';
$distDir = $root . '/dist';

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

// 2. Create dist/ if absent.
if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

// 3. Build the ZIP from modules/ only (shared/ is now inlined into lib/).
$zipFile = $distDir . '/paneldns-reseller-whmcs-' . $version . '.zip';
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
