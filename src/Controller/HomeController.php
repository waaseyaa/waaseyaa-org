<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docs\SpecCorpus;
use App\Support\FrameworkVersion;
use App\Support\PiTelemetry;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\SSR\SsrServiceProvider;

final class HomeController
{
    public function __construct(
        private readonly ?PiTelemetry $telemetry = null,
        private readonly ?SpecCorpus $corpus = null,
    ) {
    }

    public function index(): Response
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Template engine unavailable.', 500);
        }

        $telemetry = $this->telemetry ?? PiTelemetry::fromEnvironment();
        $corpus = $this->corpus ?? SpecCorpus::default();

        $html = $twig->render('home.html.twig', [
            'pi_status' => $telemetry->read(),
            'framework_version' => FrameworkVersion::pretty(),
            'release_date' => $corpus->sourceReleasedAt(),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
