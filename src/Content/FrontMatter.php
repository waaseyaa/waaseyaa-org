<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal front matter parser for the git-authored content corpus:
 * a leading YAML block delimited by --- lines, then a markdown body.
 * Strict on purpose: malformed files must fail the sync loudly, not
 * publish half-parsed content.
 */
final class FrontMatter
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public static function parse(string $raw): array
    {
        if (!str_starts_with($raw, "---\n")) {
            throw new \InvalidArgumentException('Missing opening front matter delimiter (---).');
        }

        $end = strpos($raw, "\n---\n", 3);
        if ($end === false) {
            // Allow a file that is nothing but front matter ending in "\n---".
            if (str_ends_with(rtrim($raw, "\n"), "\n---") && substr_count($raw, "---") >= 2) {
                $end = strrpos($raw, "\n---");
                $bodyStart = strlen($raw);
            } else {
                throw new \InvalidArgumentException('Unterminated front matter block.');
            }
        } else {
            $bodyStart = $end + strlen("\n---\n");
        }

        $yaml = substr($raw, 4, (int) $end - 4);

        try {
            $meta = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid front matter YAML: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($meta) || array_is_list($meta)) {
            throw new \InvalidArgumentException('Front matter must be a YAML mapping.');
        }

        return ['meta' => $meta, 'body' => ltrim(substr($raw, $bodyStart), "\n")];
    }
}
