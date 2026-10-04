<?php

declare(strict_types=1);

namespace App\Docs;

use App\Mcp\SpecReaderAccount;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;

/** Resolves public spec pointers against the canonical published corpus. */
final class SpecCandidateResolver implements SearchCandidateResolverInterface
{
    public function __construct(private readonly SpecCorpus $corpus)
    {
    }

    public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
    {
        if (!$principal->hasPermission(SpecReaderAccount::CAPABILITY)
            || $reference->entityType !== 'document'
            || $reference->namespace() !== 'spec') {
            return null;
        }

        $name = substr($reference->documentId, strlen('spec:'));
        $title = $this->corpus->title($name);
        $body = $this->corpus->markdown($name);
        if ($title === null || $body === null) {
            return null;
        }

        return new SearchCandidateProjection(
            id: $reference->documentId,
            entityType: 'document',
            title: $title,
            body: mb_substr($body, 0, SearchCandidateProjection::MAX_BODY_LENGTH),
            url: '/docs/specs/' . $name,
            sourceName: $name,
        );
    }
}
