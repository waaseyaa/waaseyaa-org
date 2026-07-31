<?php

declare(strict_types=1);

namespace App\Content;

use App\Support\OperationalLog;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\EntityStorage\EntityRepository;

/**
 * Read-side gateway over the synced content entities. Never throws:
 * a public page must degrade to an honest empty state, not a 500,
 * when the database has no synced content yet.
 */
final class ContentReader
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
    ) {
    }

    /** @return list<EntityInterface> */
    public function releases(): array
    {
        $releases = $this->published('release');
        usort($releases, fn (EntityInterface $a, EntityInterface $b): int => strcmp((string) $b->get('released_at'), (string) $a->get('released_at')));

        return $releases;
    }

    public function release(string $version): ?EntityInterface
    {
        foreach ($this->published('release') as $entity) {
            if ((string) $entity->get('version') === $version) {
                return $entity;
            }
        }

        return null;
    }

    /** @return array{now: list<EntityInterface>, next: list<EntityInterface>, later: list<EntityInterface>} */
    public function roadmap(): array
    {
        $grouped = ['now' => [], 'next' => [], 'later' => []];
        foreach ($this->published('roadmap_item') as $entity) {
            $horizon = (string) $entity->get('horizon');
            if (isset($grouped[$horizon])) {
                $grouped[$horizon][] = $entity;
            }
        }
        foreach ($grouped as &$items) {
            usort($items, fn (EntityInterface $a, EntityInterface $b): int => [(int) $a->get('weight'), (string) $a->get('title')] <=> [(int) $b->get('weight'), (string) $b->get('title')]);
        }

        return $grouped;
    }

    /** @return list<EntityInterface> */
    public function caseStudies(): array
    {
        $studies = $this->published('case_study');
        usort($studies, fn (EntityInterface $a, EntityInterface $b): int => strcmp((string) $a->get('title'), (string) $b->get('title')));

        return $studies;
    }

    public function caseStudy(string $slug): ?EntityInterface
    {
        foreach ($this->published('case_study') as $entity) {
            if ((string) $entity->get('slug') === $slug) {
                return $entity;
            }
        }

        return null;
    }

    public function revisionCount(string $entityTypeId, string $entityId): ?int
    {
        try {
            $repository = $this->entityTypeManager?->getRepository($entityTypeId);
            if (!$repository instanceof EntityRepository) {
                return null;
            }

            return count($repository->listRevisions($entityId));
        } catch (\Throwable $e) {
            OperationalLog::warning('content_read_failed', $e);

            return null;
        }
    }

    /** @return list<EntityInterface> */
    private function published(string $entityTypeId): array
    {
        if ($this->entityTypeManager === null) {
            return [];
        }

        try {
            $all = $this->entityTypeManager->getRepository($entityTypeId)->findBy([]);
        } catch (\Throwable $e) {
            OperationalLog::warning('content_read_failed', $e);

            return [];
        }

        return array_values(array_filter(
            $all,
            fn (EntityInterface $entity): bool => EntityValues::statusToInt($entity->get('status')) === 1,
        ));
    }
}
