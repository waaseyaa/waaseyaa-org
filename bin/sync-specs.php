<?php

declare(strict_types=1);

/**
 * Build-time docs corpus sync.
 *
 * Copies the framework's spec corpus (docs/specs/*.md) from the installed
 * vendor dist into resources/specs/ and writes a provenance manifest
 * (spec name, title, sha1, framework version, sync time). The vendor dist
 * is the canonical source: it is version-locked by composer, so provenance
 * is exact by construction.
 *
 * Usage: php bin/sync-specs.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Composer\InstalledVersions;

$root = dirname(__DIR__);
$source = $root . '/vendor/waaseyaa/framework/docs/specs';
$dest = $root . '/resources/specs';

if (!is_dir($source)) {
    fwrite(STDERR, "Spec source not found: {$source}\n");
    exit(1);
}

$version = InstalledVersions::getPrettyVersion('waaseyaa/framework');

if (!is_dir($dest) && !mkdir($dest, recursive: true)) {
    fwrite(STDERR, "Cannot create {$dest}\n");
    exit(1);
}

// Remove previously synced specs so deletions upstream propagate.
foreach (glob($dest . '/*.md') ?: [] as $stale) {
    unlink($stale);
}

$specs = [];
foreach (glob($source . '/*.md') ?: [] as $file) {
    $name = basename($file, '.md');
    $markdown = (string) file_get_contents($file);

    $title = $name;
    if (preg_match('/^#\s+(.+)$/m', $markdown, $m) === 1) {
        $title = trim($m[1]);
    }

    file_put_contents($dest . '/' . $name . '.md', $markdown);

    $specs[] = [
        'name' => $name,
        'title' => $title,
        'sha1' => sha1($markdown),
    ];
}

usort($specs, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

$manifest = [
    'framework_version' => $version,
    'synced_at' => gmdate('c'),
    'source' => 'vendor/waaseyaa/framework/docs/specs',
    'specs' => $specs,
];

file_put_contents(
    $dest . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

echo sprintf("Synced %d specs from waaseyaa/framework %s\n", count($specs), $version);
