<?php

declare(strict_types=1);

namespace App\Tests\Tutorial;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

final class WalkthroughContractTest extends TestCase
{
    #[Test]
    public function walkthrough_supplies_complete_files_and_the_supported_lifecycle(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block content %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['autoescape' => 'html']);
        $html = $twig->render('start.html.twig', [
            'framework_version' => 'v0.1.0-alpha.306',
            'canonical_base' => 'https://waaseyaa.org',
        ]);
        foreach (['site:init', 'install:init', 'composer site-verify'] as $command) {
            self::assertStringContainsString($command, $html);
        }
        foreach (['site.seed.yaml', 'src/Entity/Todo.php', 'config/entity-types.php',
            'src/Provider/TodoServiceProvider.php', 'src/Controller/TodoController.php',
            'templates/todo/list.html.twig', 'src/Access/TodoAccessPolicy.php'] as $file) {
            self::assertStringContainsString('data-tutorial-file="' . $file . '"', $html);
        }
        self::assertStringContainsString('function register(): void', $html);
        self::assertStringNotContainsString('every code sample below also lives', $html);
    }
}
