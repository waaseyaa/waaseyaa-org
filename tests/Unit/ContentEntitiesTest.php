<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Provider\ContentServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentEntitiesTest extends TestCase
{
    #[Test]
    public function three_content_types_are_defined_with_the_proof_engine_contract(): void
    {
        $types = ContentServiceProvider::entityTypes();
        $byId = [];
        foreach ($types as $type) {
            $byId[$type->id()] = $type;
        }

        $ids = array_keys($byId);
        sort($ids);
        self::assertSame(['case_study', 'release', 'roadmap_item'], $ids);

        foreach ($byId as $id => $type) {
            self::assertTrue($type->isRevisionable(), $id . ' must be revisionable');
            self::assertSame('content', $type->getGroup(), $id . ' must be in the content group for public read');
            self::assertTrue($type->isApiExposed(), $id . ' must declare api: true');
        }
    }

    #[Test]
    public function entities_default_to_published(): void
    {
        self::assertTrue(new \App\Entity\Release(['title' => 'x'])->get('status'));
        self::assertTrue(new \App\Entity\RoadmapItem(['title' => 'x'])->get('status'));
        self::assertTrue(new \App\Entity\CaseStudy(['title' => 'x'])->get('status'));
    }
}
