<?php

declare(strict_types=1);

namespace App\Content;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;

/**
 * Git-to-entity sync: content/<dir>/*.md becomes release / roadmap_item /
 * case_study entities. Idempotent by source sha1: unchanged files are
 * no-ops, changed files save (which creates a new revision on these
 * revisionable types), files removed from git unpublish their entity but
 * keep its history. Any malformed file aborts the whole sync loudly.
 */
final class ContentSync
{
    private const array KINDS = [
        'release' => [
            'dir' => 'releases',
            'required' => ['title', 'version', 'released_at', 'summary'],
            'optional' => ['breaking', 'tag_url'],
        ],
        'roadmap_item' => [
            'dir' => 'roadmap',
            'required' => ['title', 'horizon', 'status_note'],
            'optional' => ['related_specs', 'weight'],
        ],
        'case_study' => [
            'dir' => 'case-studies',
            'required' => ['title', 'org', 'summary'],
            'optional' => ['site_url'],
        ],
    ];

    private const array HORIZONS = ['now', 'next', 'later'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly string $contentRoot,
    ) {
    }

    public function sync(): ContentSyncReport
    {
        $report = new ContentSyncReport();

        foreach (self::KINDS as $entityTypeId => $kind) {
            $files = $this->parseKind($entityTypeId, $kind);
            $repository = $this->entityTypeManager->getRepository($entityTypeId);

            $existing = [];
            foreach ($repository->findBy([]) as $entity) {
                $existing[(string) $entity->get('slug')] = $entity;
            }

            foreach ($files as $slug => $fields) {
                $current = $existing[$slug] ?? null;

                if ($current !== null
                    && (string) $current->get('source_sha1') === $fields['source_sha1']
                    && EntityValues::statusToInt($current->get('status')) === 1
                ) {
                    ++$report->unchanged;
                    continue;
                }

                if ($current === null) {
                    $repository->save($repository->create($fields));
                    ++$report->created;
                    continue;
                }

                foreach ($fields as $name => $value) {
                    $current->set($name, $value);
                }
                $repository->save($current);
                ++$report->updated;
            }

            foreach ($existing as $slug => $entity) {
                if (!isset($files[$slug]) && EntityValues::statusToInt($entity->get('status')) === 1) {
                    $entity->set('status', false);
                    $repository->save($entity);
                    ++$report->unpublished;
                }
            }
        }

        return $report;
    }

    /**
     * @param array{dir: string, required: list<string>, optional: list<string>} $kind
     * @return array<string, array<string, mixed>> slug => entity field values
     */
    private function parseKind(string $entityTypeId, array $kind): array
    {
        $dir = $this->contentRoot . '/' . $kind['dir'];
        $out = [];

        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            $raw = (string) file_get_contents($file);

            try {
                $parsed = FrontMatter::parse($raw);
            } catch (\InvalidArgumentException $e) {
                throw new ContentSyncException($file . ': ' . $e->getMessage(), 0, $e);
            }

            $meta = $parsed['meta'];
            $allowed = array_merge($kind['required'], $kind['optional']);

            foreach ($kind['required'] as $key) {
                if (!isset($meta[$key]) || $meta[$key] === '') {
                    throw new ContentSyncException($file . ': missing required front matter key "' . $key . '".');
                }
            }
            foreach (array_keys($meta) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw new ContentSyncException($file . ': unknown front matter key "' . $key . '".');
                }
            }
            if ($entityTypeId === 'roadmap_item' && !in_array($meta['horizon'], self::HORIZONS, true)) {
                throw new ContentSyncException($file . ': horizon must be one of now, next, later.');
            }
            if ($entityTypeId === 'release' && $slug !== $meta['version']) {
                throw new ContentSyncException($file . ': filename must equal the version front matter key.');
            }

            $fields = $meta;
            $fields['slug'] = $slug;
            $fields['body'] = $parsed['body'];
            $fields['status'] = true;
            $fields['source_sha1'] = sha1($raw);

            // Normalize scalar types the YAML parser may widen.
            if (isset($fields['released_at'])) {
                $fields['released_at'] = (string) $fields['released_at'];
            }
            if (isset($fields['weight'])) {
                $fields['weight'] = (int) $fields['weight'];
            }
            if (isset($fields['breaking'])) {
                $fields['breaking'] = (bool) $fields['breaking'];
            }

            $out[$slug] = $fields;
        }

        ksort($out);

        return $out;
    }
}
