<?php

declare(strict_types=1);

namespace App\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Makes git-only publishing of `release`, `roadmap_item`, and `case_study`
 * structural rather than incidental.
 *
 * The framework auto-registers admin-surface write routes for every content
 * type regardless of API exposure, and would additionally register
 * authenticated JSON:API write routes for these three types if they were
 * ever declared `api: true` (they are not, pending
 * waaseyaa/framework#2159; see tests/Integration/ApiAbsenceTest.php). Either
 * way, this policy denies runtime writes regardless of which surface reaches
 * it: "no account exists today" was the only thing standing between the
 * admin-surface routes and a runtime write before this policy existed.
 * Registering one account with
 * `administer content` (kernel default {@see \Waaseyaa\Access\Policy\ContentAdminAccessPolicy},
 * which never returns Forbidden by design) would immediately open a second
 * publishing path alongside `content:sync`, and runtime state could then
 * diverge from git.
 *
 * This policy closes that gap by denying every write operation for these
 * three type ids to every account, including administrators. Because
 * {@see \Waaseyaa\Access\EntityAccessHandler} composes policies with OR and
 * lets Forbidden short-circuit and win over any Allowed, this Forbidden
 * cannot be overridden by ContentAdminAccessPolicy or any future policy
 * granting broader permissions. `content:sync` remains the sole writer
 * because it saves through {@see \Waaseyaa\EntityStorage\EntityRepository::save()},
 * which does not consult access policies at all (only entity-checked reads
 * via {@see \Waaseyaa\EntityStorage\EntityRepository::getQuery()} do).
 *
 * 'view' is left Neutral so published-read keeps flowing from
 * PublishedContentAccessPolicy and unpublished drafts stay denied exactly as
 * before; this policy only ever narrows write operations, never widens read.
 */
#[PolicyAttribute(entityType: ['release', 'roadmap_item', 'case_study'])]
final class ContentWriteProtectionPolicy implements AccessPolicyInterface
{
    /** Entity type ids this policy structurally denies runtime writes for. */
    private const array PROTECTED_ENTITY_TYPES = ['release', 'roadmap_item', 'case_study'];

    private const string DENY_REASON = 'Runtime writes to git-authored content are denied by design; content:sync is the sole writer.';

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view') {
            return AccessResult::neutral('This policy has no opinion on view; published-read flows from PublishedContentAccessPolicy.');
        }

        return AccessResult::forbidden(self::DENY_REASON);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!in_array($entityTypeId, self::PROTECTED_ENTITY_TYPES, true)) {
            // Defensive: appliesTo() already gates the handler's dispatch to
            // this policy, but createAccess() re-checks the id explicitly.
            return AccessResult::neutral('Not a git-authored content type.');
        }

        return AccessResult::forbidden(self::DENY_REASON);
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::PROTECTED_ENTITY_TYPES, true);
    }
}
