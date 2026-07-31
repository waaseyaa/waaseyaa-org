<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Controller\LlmsTxtController;
use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\SsrServiceProvider;

final class LlmsTxtTest extends TestCase
{
    private static SpecCorpus $corpus;
    private static string $body;

    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();

        self::$corpus = SpecCorpus::default();

        $manager = ContentEntityHarness::entityTypeManager();
        // Sync the REAL repo corpus so tests cover the shipped content.
        new ContentSync($manager, dirname(__DIR__, 2) . '/content')->sync();

        $response = new LlmsTxtController(self::$corpus, new SiteUrl('https://waaseyaa.org'), new ContentReader($manager))->serve();
        self::$body = (string) $response->getContent();
    }

    #[Test]
    public function llms_txt_is_a_valid_index(): void
    {
        $this->assertStringStartsWith("# Waaseyaa\n", self::$body);
        $this->assertStringContainsString("\n> ", self::$body);
        $this->assertStringContainsString('## Specs', self::$body);
    }

    #[Test]
    public function llms_txt_indexes_every_published_spec(): void
    {
        $specs = self::$corpus->all();
        $this->assertNotEmpty($specs);

        foreach ($specs as $spec) {
            $this->assertStringContainsString(
                'https://waaseyaa.org/docs/specs/' . $spec['name'] . '.md',
                self::$body,
                $spec['name'],
            );
        }
    }

    #[Test]
    public function llms_txt_indexes_content_surfaces(): void
    {
        $this->assertStringContainsString('## Releases', self::$body);
        $this->assertStringContainsString('/releases/v0.1.0-alpha.276.md', self::$body);
        $this->assertStringContainsString('## Roadmap', self::$body);
        $this->assertStringContainsString('## Production', self::$body);
    }

    #[Test]
    public function llms_txt_links_per_topic_pages_not_a_full_dump(): void
    {
        $this->assertStringNotContainsString('llms-full', self::$body);
        // The index stays an index: it must be far smaller than the corpus.
        $this->assertLessThan(64 * 1024, strlen(self::$body));
    }
}
