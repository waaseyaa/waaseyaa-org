<?php

declare(strict_types=1);

namespace App\Provider;

use App\Cli\ContentSyncHandler;
use App\Content\ContentReader;
use App\Controller\ReleasesController;
use App\Controller\RoadmapController;
use App\Docs\SpecCorpus;
use App\Entity\CaseStudy;
use App\Entity\Release;
use App\Entity\RoadmapItem;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The proof engine: git-authored release / roadmap / case-study content
 * registered as real revisionable entities. group: 'content' + status=true
 * is what grants anonymous read via the kernel's PublishedContentAccessPolicy;
 * there is no app-side write surface (content:sync is the only writer).
 */
final class ContentServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        foreach (self::entityTypes() as $type) {
            $this->entityType($type);
        }
    }

    /**
     * @return iterable<HandlerCommand>
     */
    public function consoleCommands(): iterable
    {
        $root = $this->projectRoot !== '' ? $this->projectRoot : dirname(__DIR__, 2);

        yield new HandlerCommand(
            name: 'content:sync',
            description: 'Sync content/*.md into release, roadmap_item, and case_study entities (idempotent; new revision on change; unpublish on delete).',
            handler: \Closure::fromCallable([new ContentSyncHandler($root), 'execute']),
        );
    }

    /**
     * The proof engine's own pages: content entities rendered as HTML,
     * negotiated Markdown, and (later tasks) MCP tools reading the same
     * repository.
     */
    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $reader = new ContentReader($entityTypeManager);
        $corpus = SpecCorpus::default();
        $urls = SiteUrl::fromEnvironment();

        $releases = new ReleasesController($reader, $corpus, $urls);

        $router->addRoute(
            'releases.index',
            RouteBuilder::create('/releases')
                ->controller(fn (Request $request) => $releases->index($request))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'releases.show',
            RouteBuilder::create('/releases/{version}')
                ->controller(fn (Request $request, string $version) => $releases->show($request, $version))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $roadmap = new RoadmapController($reader, $corpus, $urls);

        $router->addRoute(
            'roadmap',
            RouteBuilder::create('/roadmap')
                ->controller(fn (Request $request) => $roadmap->page($request))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
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
