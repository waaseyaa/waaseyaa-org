<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Docs\SpecCandidateResolver;
use App\Docs\SpecCorpus;
use App\Mcp\SpecReaderAccount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Search\SearchCandidateReference;

final class SpecCandidateResolverTest extends TestCase
{
    #[Test]
    public function resolves_only_published_spec_pointers_for_a_reader(): void
    {
        $corpus = SpecCorpus::default();
        $resolver = new SpecCandidateResolver($corpus);
        $reader = new SpecReaderAccount();
        $reference = new SearchCandidateReference('spec:entity-system', 'document');
        $projection = $resolver->resolve($reference, $reader);

        self::assertNotNull($projection);
        self::assertSame($corpus->title('entity-system'), $projection->title);
        self::assertSame('/docs/specs/entity-system', $projection->url);
        self::assertNull($resolver->resolve($reference, new AuthorizationPrincipal(0, false, [], [], 'anonymous')));
        self::assertNull($resolver->resolve(new SearchCandidateReference('spec:entity-system', 'node'), $reader));
        self::assertNull($resolver->resolve(new SearchCandidateReference('node:entity-system', 'document'), $reader));
        self::assertNull($resolver->resolve(new SearchCandidateReference('spec:missing-spec', 'document'), $reader));
        self::assertNull($resolver->resolve(new SearchCandidateReference('spec:../composer', 'document'), $reader));
    }
}
