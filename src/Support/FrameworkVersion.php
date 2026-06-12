<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;

/**
 * Resolves the installed waaseyaa/framework version for provenance
 * lines in the footer and the docs corpus sync. Never invents a
 * version: returns null when composer metadata is unavailable.
 */
final class FrameworkVersion
{
    public static function pretty(): ?string
    {
        try {
            if (!InstalledVersions::isInstalled('waaseyaa/framework')) {
                return null;
            }

            return InstalledVersions::getPrettyVersion('waaseyaa/framework');
        } catch (\Throwable) {
            return null;
        }
    }
}
