<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Release;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\EntityTypeApiExposure;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Proves JSON:API is genuinely absent for `release`, `roadmap_item`, and
 * `case_study`, not merely undocumented.
 *
 * We deliberately withdrew `api: true` from all three entity types (and the
 * `config/waaseyaa.php` `api.entity_type_allowlist` that used to close the
 * world around them) before merge. Root cause: on waaseyaa/framework
 * alpha.276, anonymous JSON:API reads of these types return 200 with empty
 * data instead of erroring, because the kernel routes anonymous `view`
 * through the protected-entity-read path, and that path's compiled subject
 * only carries `status` when a field declares `Protected` +
 * `settings: ['authorizationInput' => true]` (the `Node` pattern); our
 * `status` fields are `Public`. Shipping an enabled surface that silently
 * returns nothing was judged worse than shipping no surface at all. See
 * https://github.com/waaseyaa/framework/issues/2159.
 *
 * These tests flip (id and route name assertions reverse, the `isExposed()`
 * assertions become `assertTrue`) the day `api: true` returns to
 * src/Entity/{Release,RoadmapItem,CaseStudy}.php after the framework fix
 * lands. Until then this class is the executable proof that withdrawal
 * actually took: no `api.{type}.index`/`.show`/`.store` route exists for
 * any of the three types, and `/api/{type}` resolves to the framework's own
 * generic not-exposed diagnostic (404, no route to a real controller).
 */
final class ApiAbsenceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function contentTypes(): array
    {
        return [
            'release' => ['release'],
            'roadmap_item' => ['roadmap_item'],
            'case_study' => ['case_study'],
        ];
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function the_entity_type_does_not_declare_api_exposure(string $typeId): void
    {
        $manager = ContentEntityHarness::entityTypeManager();
        $definition = $manager->getDefinition($typeId);

        self::assertFalse(
            EntityTypeApiExposure::isExposed($definition),
            $typeId . ': EntityTypeApiExposure::isExposed() must be false while framework#2159 is open.',
        );
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function get_the_collection_path_resolves_to_the_frameworks_not_exposed_diagnostic(string $typeId): void
    {
        $manager = ContentEntityHarness::entityTypeManager();

        $context = new RequestContext();
        $context->setMethod('GET');
        $router = new WaaseyaaRouter($context);
        (new JsonApiRouteProvider($manager))->registerRoutes($router);

        $match = $router->match('/api/' . $typeId);

        self::assertSame(
            'api.' . $typeId . '.not_exposed',
            $match['_route'],
            $typeId . ': GET /api/' . $typeId . ' must resolve to the framework\'s not_exposed diagnostic route, not a real index route.',
        );

        $routes = $router->getRouteCollection();
        self::assertNull(
            $routes->get('api.' . $typeId . '.index'),
            $typeId . ': no api.' . $typeId . '.index route may be registered while unexposed.',
        );
        self::assertNull(
            $routes->get('api.' . $typeId . '.show'),
            $typeId . ': no api.' . $typeId . '.show route may be registered while unexposed.',
        );
    }

    #[Test]
    #[DataProvider('contentTypes')]
    public function post_the_collection_path_also_lands_on_the_not_exposed_diagnostic_not_a_store_route(string $typeId): void
    {
        $manager = ContentEntityHarness::entityTypeManager();

        $context = new RequestContext();
        $context->setMethod('POST');
        $router = new WaaseyaaRouter($context);
        (new JsonApiRouteProvider($manager))->registerRoutes($router);

        $match = $router->match('/api/' . $typeId);

        self::assertSame(
            'api.' . $typeId . '.not_exposed',
            $match['_route'],
            $typeId . ': POST /api/' . $typeId . ' must resolve to the framework\'s not_exposed diagnostic route, not a store route.',
        );

        self::assertNull(
            $router->getRouteCollection()->get('api.' . $typeId . '.store'),
            $typeId . ': no api.' . $typeId . '.store route may be registered while unexposed.',
        );
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
