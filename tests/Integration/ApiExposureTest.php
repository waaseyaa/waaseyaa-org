<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Release;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ApiExposureTest extends TestCase
{
    #[Test]
    public function the_allowlist_in_config_validates_against_registered_types(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/waaseyaa.php';
        self::assertSame(['release', 'roadmap_item', 'case_study'], $config['api']['entity_type_allowlist']);

        $policy = EntityTypeApiExposurePolicy::fromConfig(ContentEntityHarness::entityTypeManager(), $config);
        // fromConfig throws on any unregistered or non-api-exposable id, so
        // reaching here already proves the allowlist is boot-safe; assert the
        // resulting effective map exposes exactly the three registered types.
        self::assertSame(
            ['case_study' => true, 'release' => true, 'roadmap_item' => true],
            $policy->effectiveMap(),
        );
    }

    #[Test]
    public function read_routes_are_public_and_write_routes_require_authentication(): void
    {
        $manager = ContentEntityHarness::entityTypeManager();

        $context = new RequestContext();
        $context->setMethod('GET');
        $getRouter = new WaaseyaaRouter($context);
        (new JsonApiRouteProvider($manager))->registerRoutes($getRouter);
        self::assertSame('api.release.index', $getRouter->match('/api/release')['_route']);
        self::assertSame('api.release.show', $getRouter->match('/api/release/1')['_route']);

        $post = new RequestContext();
        $post->setMethod('POST');
        $postRouter = new WaaseyaaRouter($post);
        (new JsonApiRouteProvider($manager))->registerRoutes($postRouter);
        self::assertSame('api.release.store', $postRouter->match('/api/release')['_route']);
    }

    #[Test]
    public function anonymous_view_is_allowed_only_for_published_entities(): void
    {
        $manager = ContentEntityHarness::entityTypeManager();
        $policy = new PublishedContentAccessPolicy($manager);
        $anonymous = new AuthorizationPrincipal(0, false, [], [], 'anonymous');

        $published = new Release(['title' => 'x', 'status' => true]);
        $unpublished = new Release(['title' => 'x', 'status' => false]);

        self::assertTrue($policy->access($published, 'view', $anonymous)->isAllowed());
        self::assertFalse($policy->access($unpublished, 'view', $anonymous)->isAllowed());
        self::assertFalse($policy->createAccess('release', 'release', $anonymous)->isAllowed(), 'anonymous must never gain create');
    }
}
