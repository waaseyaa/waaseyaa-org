<?php

declare(strict_types=1);

namespace App\Provider;

use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\StaticPageController;
use App\Docs\SpecCorpus;
use App\Health\ReadinessCheck;
use App\Support\Db;
use App\Support\SiteUrl;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $controller = new HomeController();
        $pages = new StaticPageController(SiteUrl::fromEnvironment());
        $health = new HealthController(new ReadinessCheck(
            databasePath: Db::path(),
            corpus: SpecCorpus::default(),
            environment: getenv('APP_ENV') ?: 'production',
        ));

        $router->addRoute(
            'healthz',
            RouteBuilder::create('/healthz')
                ->controller(fn () => $health->healthz())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'home',
            RouteBuilder::create('/')
                ->controller(fn () => $controller->index())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'start',
            RouteBuilder::create('/start')
                ->controller(fn () => $pages->start())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'why',
            RouteBuilder::create('/why')
                ->controller(fn () => $pages->why())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'compare',
            RouteBuilder::create('/compare')
                ->controller(fn () => $pages->compare())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }
}
