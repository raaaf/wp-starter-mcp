<?php

declare(strict_types=1);

// Builds dist/wp-starter-mcp-<version>.zip and a .sha256 file. Run: composer package
// The zip holds only the runtime files and a production-only autoloader.

$root = dirname(__DIR__);
$main = (string) file_get_contents($root . '/wp-starter-mcp.php');

if (preg_match('/^ \* Version:\s+(\S+)/m', $main, $m) !== 1) {
    fwrite(STDERR, "Version header not found\n");
    exit(1);
}

$version = $m[1];
$stage = sys_get_temp_dir() . '/wpsmcp-build-' . bin2hex(random_bytes(4));
$pluginDir = $stage . '/wp-starter-mcp';
$include = ['wp-starter-mcp.php', 'composer.json', 'composer.lock', 'src', 'README.md'];

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        mkdir($to, 0755, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }

        return;
    }
    copy($from, $to);
};

mkdir($pluginDir, 0755, true);
foreach ($include as $item) {
    if (file_exists($root . '/' . $item)) {
        $copy($root . '/' . $item, $pluginDir . '/' . $item);
    }
}

$cmd = sprintf('composer install --no-dev --classmap-authoritative --no-interaction -q -d %s 2>&1', escapeshellarg($pluginDir));
exec($cmd, $out, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(1);
}

// Composer metadata is only needed to build the autoloader.
foreach (['composer.json', 'composer.lock'] as $file) {
    @unlink($pluginDir . '/' . $file);
}

@mkdir($root . '/dist', 0755, true);
$zipPath = $root . "/dist/wp-starter-mcp-{$version}.zip";
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        $zip->addFile($file->getPathname(), 'wp-starter-mcp/' . substr($file->getPathname(), strlen($pluginDir) + 1));
    }
}
$zip->close();

$sum = hash_file('sha256', $zipPath);
file_put_contents($zipPath . '.sha256', $sum . '  ' . basename($zipPath) . "\n");

exec('rm -rf ' . escapeshellarg($stage));
echo basename($zipPath) . "\n" . $sum . "\n";
