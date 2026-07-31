<?php

declare(strict_types=1);

/**
 * Scaffold a release note: php bin/scaffold-release.php v0.1.0-alpha.277
 * Prefills front matter; the summary and body are then written by hand.
 * If the scaffolded version matches the locked framework version, the
 * release date is prefilled from the corpus manifest.
 */

$version = $argv[1] ?? '';
if (preg_match('/^v\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Usage: php bin/scaffold-release.php vX.Y.Z[-suffix]\n");
    exit(2);
}

$root = dirname(__DIR__);
$target = $root . '/content/releases/' . $version . '.md';
if (is_file($target)) {
    fwrite(STDERR, "Already exists: {$target}\n");
    exit(1);
}

$date = date('Y-m-d');
$manifest = json_decode((string) @file_get_contents($root . '/resources/specs/manifest.json'), true);
if (is_array($manifest) && ($manifest['framework_version'] ?? null) === $version && is_string($manifest['source_released_at'] ?? null)) {
    $date = substr($manifest['source_released_at'], 0, 10);
}

file_put_contents($target, <<<MD
---
title: Framework {$version}
version: {$version}
released_at: "{$date}"
summary: One-sentence summary of the release.
breaking: false
tag_url: https://github.com/waaseyaa/framework/releases/tag/{$version}
---

Write the highlights here, honestly. Delete this line.
MD . "\n");

echo "Wrote {$target}\n";
