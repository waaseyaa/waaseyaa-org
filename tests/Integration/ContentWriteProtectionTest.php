<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Access\ContentWriteProtectionPolicy;
use App\Entity\CaseStudy;
use App\Entity\Release;
use App\Entity\RoadmapItem;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Proves ContentWriteProtectionPolicy makes git-only publishing structural:
 * admin-surface write routes exist and require authentication (JSON:API
 * write routes do not exist at all for these three types today, because
 * `api: true` is deliberately withdrawn pending waaseyaa/framework#2159,
 * see ApiAbsenceTest), but no account, including one holding "administer
 * content", can ever use the routes that do exist, because this policy
 * denies every write operation for the three git-authored content types
 * by design.
 *
 * The EntityAccessHandler is composed here exactly the way the kernel ends
 * up composing it for a request touching a content-group entity type:
 * PublishedContentAccessPolicy and ContentAdminAccessPolicy are both
 * unconditional kernel defaults (see their class docblocks in
 * vendor/waaseyaa/access/src/Policy/), and app policies discovered via
 * #[PolicyAttribute] are added alongside them. See EntityAccessHandler::check()
 * / checkCreateAccess() (vendor/waaseyaa/access/src/EntityAccessHandler.php)
 * for the OR-combine / Forbidden-short-circuits composition this test
 * exercises against real policy instances (no mocks).
 */
final class ContentWriteProtectionTest extends TestCase
{
    private function productionShapedHandler(): EntityAccessHandler
    {
        $manager = ContentEntityHarness::entityTypeManager();

        return new EntityAccessHandler([
            new PublishedContentAccessPolicy($manager),
            new ContentAdminAccessPolicy($manager),
            new ContentWriteProtectionPolicy(),
        ]);
    }

    private function anonymous(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(0, false, [], [], 'anonymous');
    }

    private function contentAdmin(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            42,
            true,
            [],
            [ContentAdminAccessPolicy::ADMIN_PERMISSION],
            'content-admin',
        );
    }

    /**
     * @return array<string, array{0: class-string<ContentEntityBase>, 1: string}>
     */
    public static function contentTypes(): array
    {
        return [
            'release' => [Release::class, 'release'],
            'roadmap_item' => [RoadmapItem::class, 'roadmap_item'],
            'case_study' => [CaseStudy::class, 'case_study'],
        ];
    }

    /**
     * Proves this policy never turns 'view' into a denial: it must stay
     * Neutral so whatever grants published-read (PublishedContentAccessPolicy)
     * keeps working unobstructed and drafts stay closed.
     *
     * This does NOT assert `isAllowed()` on the composed handler. Verified
     * against this app's actual entity definitions: EntityAccessHandler::check()
     * routes every 'view' call through its "Protected entity-read" path
     * whenever any applicable policy implements ProtectedReadPolicyProviderInterface
     * (vendor/waaseyaa/access/src/EntityAccessHandler.php, hasProtectedEntityReadPolicy()
     * / checkProtectedEntityRead()), which PublishedContentAccessPolicy always
     * does. That path only sees 'status' on the compiled policy subject when
     * some field on the entity type declares `settings: ['authorizationInput' => true]`
     * (vendor/waaseyaa/entity/src/EntityReadRuntime.php ~L188). None of
     * Release/RoadmapItem/CaseStudy's fields declare this (confirmed against
     * both a directly-constructed entity and one hydrated via ContentSync +
     * the repository), so the compiled subject is always empty and
     * PublishedContentProtectedEntityReadPolicy returns Neutral regardless of
     * published status. This read-wiring gap (root-caused to
     * waaseyaa/framework#2159) is why JSON:API is withdrawn for these three
     * types entirely rather than shipped read-only; it is orthogonal to
     * write protection and out of scope here. ApiAbsenceTest pins both that
     * withdrawal and the invariant that PublishedContentAccessPolicy::access()
     * itself grants published view directly. What THIS test guards is
     * narrower and directly relevant to this policy: view must never become
     * Forbidden.
     */
    #[Test]
    #[DataProvider('contentTypes')]
    public function published_view_is_never_forbidden_by_the_new_policy(string $class, string $typeId): void
    {
        $handler = $this->productionShapedHandler();
        $entity = new $class(['title' => 'x', 'status' => true]);

        $result = $handler->check($entity, 'view', $this->anonymous());

        self::assertFalse($result->isForbidden(), sprintf('%s: published view must never be Forbidden.', $typeId));
    }

    /**
     * The narrow, directly-relevant grant check: ContentWriteProtectionPolicy's
     * own access() must be Neutral (not Forbidden, not Allowed) for 'view', on
     * all three types, so it never overrides or fights PublishedContentAccessPolicy.
     */
    #[Test]
    #[DataProvider('contentTypes')]
    public function the_new_policy_itself_is_neutral_on_view(string $class, string $typeId): void
    {
        $policy = new ContentWriteProtectionPolicy();
        $entity = new $class(['title' => 'x', 'status' => true]);

        $result = $policy->access($entity, 'view', $this->anonymous());

        self::assertTrue($result->isNeutral(), sprintf('%s: ContentWriteProtectionPolicy must be Neutral on view.', $typeId));
    }

    #[Test]
    public function anonymous_view_of_unpublished_release_is_not_allowed(): void
    {
        $handler = $this->productionShapedHandler();
        $entity = new Release(['title' => 'x', 'status' => false]);

        $result = $handler->check($entity, 'view', $this->anonymous());

        self::assertFalse($result->isAllowed(), 'unpublished content must not be viewable by anonymous.');
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function update_is_forbidden_for_anonymous_and_content_admin(string $class, string $typeId): void
    {
        $handler = $this->productionShapedHandler();
        $entity = new $class(['title' => 'x', 'status' => true]);

        $anonResult = $handler->check($entity, 'update', $this->anonymous());
        $adminResult = $handler->check($entity, 'update', $this->contentAdmin());

        self::assertTrue($anonResult->isForbidden(), sprintf('%s: update must be Forbidden for anonymous.', $typeId));
        self::assertTrue($adminResult->isForbidden(), sprintf('%s: update must be Forbidden even for an "administer content" holder.', $typeId));
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function delete_is_forbidden_for_anonymous_and_content_admin(string $class, string $typeId): void
    {
        $handler = $this->productionShapedHandler();
        $entity = new $class(['title' => 'x', 'status' => true]);

        $anonResult = $handler->check($entity, 'delete', $this->anonymous());
        $adminResult = $handler->check($entity, 'delete', $this->contentAdmin());

        self::assertTrue($anonResult->isForbidden(), sprintf('%s: delete must be Forbidden for anonymous.', $typeId));
        self::assertTrue($adminResult->isForbidden(), sprintf('%s: delete must be Forbidden even for an "administer content" holder.', $typeId));
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function create_is_forbidden_for_anonymous_and_content_admin(string $class, string $typeId): void
    {
        $handler = $this->productionShapedHandler();

        $anonResult = $handler->checkCreateAccess($typeId, $typeId, $this->anonymous());
        $adminResult = $handler->checkCreateAccess($typeId, $typeId, $this->contentAdmin());

        self::assertFalse($anonResult->isAllowed(), sprintf('%s: create must not be allowed for anonymous.', $typeId));
        self::assertTrue($anonResult->isForbidden(), sprintf('%s: create must be Forbidden for anonymous.', $typeId));
        self::assertFalse($adminResult->isAllowed(), sprintf('%s: create must not be allowed even for an "administer content" holder.', $typeId));
        self::assertTrue($adminResult->isForbidden(), sprintf('%s: create must be Forbidden even for an "administer content" holder.', $typeId));
    }

    /**
     * Control proof that ContentWriteProtectionPolicy is load-bearing: without
     * it, ContentAdminAccessPolicy alone grants an "administer content" holder
     * update access on a Release (its docblock says exactly this: full manage
     * of any content-group entity with no hand-written per-type policy). This
     * documents the exact widening ContentWriteProtectionPolicy exists to
     * close.
     */
    #[Test]
    public function without_the_new_policy_content_admin_would_be_allowed_to_update(): void
    {
        $manager = ContentEntityHarness::entityTypeManager();
        $handlerWithoutProtection = new EntityAccessHandler([
            new PublishedContentAccessPolicy($manager),
            new ContentAdminAccessPolicy($manager),
        ]);

        $entity = new Release(['title' => 'x', 'status' => true]);

        $result = $handlerWithoutProtection->check($entity, 'update', $this->contentAdmin());

        self::assertTrue($result->isAllowed(), 'control: ContentAdminAccessPolicy alone must grant update (proves the gap the new policy closes).');
    }

    /**
     * Wiring guard: the #[PolicyAttribute] on ContentWriteProtectionPolicy
     * must list exactly the three git-authored content type ids, otherwise
     * kernel auto-discovery (attribute scan of root PSR-4 namespaces) would
     * either miss a type or over-apply to types the policy was never
     * reviewed against.
     */
    #[Test]
    public function policy_attribute_declares_exactly_the_three_content_types(): void
    {
        self::assertTrue(is_a(ContentWriteProtectionPolicy::class, AccessPolicyInterface::class, true));

        $reflection = new \ReflectionClass(ContentWriteProtectionPolicy::class);
        $attributes = $reflection->getAttributes(PolicyAttribute::class);

        self::assertCount(1, $attributes, 'ContentWriteProtectionPolicy must carry exactly one #[PolicyAttribute].');

        /** @var PolicyAttribute $attribute */
        $attribute = $attributes[0]->newInstance();

        self::assertSame(['release', 'roadmap_item', 'case_study'], $attribute->entityTypes);
    }
}
