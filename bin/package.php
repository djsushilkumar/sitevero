<?php
/**
 * Sitevero — Production Plugin Packaging Script
 *
 * Packages the plugin into a standardized WordPress installable ZIP archive:
 * dist/sitevero.zip with root folder `sitevero/`.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$distDir = $rootDir . DIRECTORY_SEPARATOR . 'dist';
$zipFile = $distDir . DIRECTORY_SEPARATOR . 'sitevero.zip';

echo "=========================================================\n";
echo "  Sitevero — Production Plugin Packaging Script\n";
echo "=========================================================\n\n";

if (!is_dir($distDir) && !mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "ERROR: Unable to create dist directory: {$distDir}\n");
    exit(1);
}

if (file_exists($zipFile)) {
    unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERROR: Cannot open ZIP file for writing: {$zipFile}\n");
    exit(1);
}

// Production inclusion list
$filesToInclude = [
    'sitevero.php',
    'composer.json',
    'README.md',
];

$dirsToInclude = [
    'assets',
    'inc',
];

echo "Adding core files...\n";
foreach ($filesToInclude as $relFile) {
    $srcPath = $rootDir . DIRECTORY_SEPARATOR . $relFile;
    if (!file_exists($srcPath)) {
        fwrite(STDERR, "WARNING: Required file {$relFile} does not exist!\n");
        continue;
    }
    $inZipPath = 'sitevero/' . str_replace('\\', '/', $relFile);
    $zip->addFile($srcPath, $inZipPath);
    echo "  + {$inZipPath}\n";
}

echo "\nAdding directory contents...\n";
foreach ($dirsToInclude as $relDir) {
    $srcDir = $rootDir . DIRECTORY_SEPARATOR . $relDir;
    if (!is_dir($srcDir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $subPath = substr($item->getPathname(), strlen($rootDir) + 1);
        $inZipPath = 'sitevero/' . str_replace('\\', '/', $subPath);

        if ($item->isDir()) {
            $zip->addEmptyDir($inZipPath);
        } elseif ($item->isFile()) {
            $zip->addFile($item->getPathname(), $inZipPath);
            echo "  + {$inZipPath}\n";
        }
    }
}

$numFiles = $zip->numFiles;
$zip->close();

if (!file_exists($zipFile)) {
    fwrite(STDERR, "ERROR: Failed to create package at {$zipFile}\n");
    exit(1);
}

$fileSize = filesize($zipFile);
$sha256 = hash_file('sha256', $zipFile);

echo "\n=========================================================\n";
echo "SUCCESS: Plugin package built successfully!\n";
echo "Location:  dist/sitevero.zip\n";
echo "Files:     {$numFiles} packaged items\n";
echo "Size:      " . number_format($fileSize / 1024, 2) . " KB ({$fileSize} bytes)\n";
echo "SHA-256:   {$sha256}\n";
echo "=========================================================\n";
