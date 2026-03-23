#!/usr/bin/env php
<?php

/**
 * CWM Scripture Library - Package Build Script
 *
 * Creates lib_cwmscripture-{version}.zip ready for Joomla installation.
 * Other extensions (Proclaim, plg_content_scripturelinks) can include this
 * ZIP in their own package manifests.
 *
 * Usage:
 *   php build/build-package.php              # Build library ZIP
 *   php build/build-package.php --verbose    # Show files being added
 *
 * The resulting ZIP can be:
 *   1. Installed directly via Joomla Extension Manager
 *   2. Included in a pkg_*.zip package alongside other extensions
 *   3. Referenced by other build scripts (Proclaim, ScriptureLinks)
 *
 * @package  CWM.Library.Scripture
 * @since    1.0.0
 */

const BASE_DIR = __DIR__ . '/..';

$verbose = \in_array('--verbose', $argv ?? [], true) || \in_array('-v', $argv ?? [], true);

try {
    build($verbose);
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

function build(bool $verbose = false): void
{
    // Read version from library manifest
    $manifestXml = simplexml_load_file(BASE_DIR . '/cwmscripture.xml');

    if (!$manifestXml) {
        throw new \RuntimeException('Could not parse cwmscripture.xml');
    }

    $version = (string) $manifestXml->version;
    echo "Building lib_cwmscripture v$version\n\n";

    $distDir = BASE_DIR . '/build/dist';

    if (is_dir($distDir)) {
        exec('rm -rf ' . escapeshellarg($distDir));
    }

    mkdir($distDir, 0777, true);

    // Verify minified assets exist
    $jsDir  = BASE_DIR . '/media/lib_cwmscripture/js';
    $cssDir = BASE_DIR . '/media/lib_cwmscripture/css';

    $missingMin = false;

    foreach (glob($jsDir . '/*.js') as $jsFile) {
        if (str_ends_with($jsFile, '.min.js')) {
            continue;
        }

        $minFile = str_replace('.js', '.min.js', $jsFile);

        if (!file_exists($minFile)) {
            echo "WARNING: Missing minified file: " . basename($minFile) . "\n";
            $missingMin = true;
        }
    }

    foreach (glob($cssDir . '/*.css') as $cssFile) {
        if (str_ends_with($cssFile, '.min.css')) {
            continue;
        }

        $minFile = str_replace('.css', '.min.css', $cssFile);

        if (!file_exists($minFile)) {
            echo "WARNING: Missing minified file: " . basename($minFile) . "\n";
            $missingMin = true;
        }
    }

    if ($missingMin) {
        echo "\nRun 'npm run build' first to generate minified assets.\n";
        exit(1);
    }

    // Create library ZIP
    $zipPath = $distDir . '/lib_cwmscripture-' . $version . '.zip';
    echo "Creating lib_cwmscripture-$version.zip...\n";

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('Could not create ' . $zipPath);
    }

    // Add manifest (goes in root of ZIP for Joomla to find it)
    $zip->addFile(BASE_DIR . '/cwmscripture.xml', 'cwmscripture.xml');

    if ($verbose) {
        echo "  + cwmscripture.xml\n";
    }

    // Add install script if present
    if (file_exists(BASE_DIR . '/script.php')) {
        $zip->addFile(BASE_DIR . '/script.php', 'script.php');

        if ($verbose) {
            echo "  + script.php\n";
        }
    }

    // Add library source files (src/, sql/, language/)
    addDirectoryToZip($zip, BASE_DIR . '/src', 'lib_cwmscripture/src', $verbose);
    addDirectoryToZip($zip, BASE_DIR . '/sql', 'lib_cwmscripture/sql', $verbose);
    addDirectoryToZip($zip, BASE_DIR . '/language', 'lib_cwmscripture/language', $verbose);

    // Add media files (js, css, joomla.asset.json)
    addDirectoryToZip($zip, BASE_DIR . '/media/lib_cwmscripture', 'media/lib_cwmscripture', $verbose);

    $fileCount = $zip->numFiles;
    $zip->close();

    $sizeKb = round(filesize($zipPath) / 1024);
    echo "\nPackage built: $zipPath\n";
    echo "  Files: $fileCount\n";
    echo "  Size:  {$sizeKb} KB\n";
}

/**
 * Recursively add a directory's contents to a ZipArchive.
 *
 * @param  ZipArchive  $zip        The ZIP archive
 * @param  string      $sourcePath Absolute path to source directory
 * @param  string      $zipPrefix  Path prefix inside the ZIP
 * @param  bool        $verbose    Whether to print each file
 */
function addDirectoryToZip(ZipArchive $zip, string $sourcePath, string $zipPrefix, bool $verbose): void
{
    if (!is_dir($sourcePath)) {
        echo "  SKIP: $sourcePath (not found)\n";

        return;
    }

    $excludeNames = [
        '.git', '.DS_Store', '.idea', 'node_modules', '.php-cs-fixer.cache',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            continue;
        }

        $filePath     = $file->getRealPath();
        $relativePath = substr($filePath, \strlen(realpath($sourcePath)) + 1);

        // Check excludes
        $skip = false;

        foreach ($excludeNames as $exclude) {
            if (str_contains($relativePath, $exclude)) {
                $skip = true;
                break;
            }
        }

        if ($skip) {
            continue;
        }

        $zipPath = $zipPrefix . '/' . $relativePath;

        if ($verbose) {
            echo "  + $zipPath\n";
        }

        $zip->addFile($filePath, $zipPath);
    }
}
