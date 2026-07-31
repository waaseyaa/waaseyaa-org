<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\CaseStudy;
use App\Entity\Release;
use App\Entity\RoadmapItem;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * The proof engine: git-authored release / roadmap / case-study content
 * registered as real revisionable entities. group: 'content' + status=true
 * is what grants anonymous read via the kernel's PublishedContentAccessPolicy;
 * there is no app-side write surface (content:sync is the only writer).
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (self::entityTypes() as $type) {
            $this->entityType($type);
        }
    }

    /**
     * Shared with the test harness so tests register exactly what production
     * registers.
     *
     * @return list<EntityType>
     */
    public static function entityTypes(): array
    {
        return [
            EntityType::fromClass(Release::class, revisionable: true, revisionDefault: true, group: 'content'),
            EntityType::fromClass(RoadmapItem::class, revisionable: true, revisionDefault: true, group: 'content'),
            EntityType::fromClass(CaseStudy::class, revisionable: true, revisionDefault: true, group: 'content'),
        ];
    }
}
