# Proof Engine Implementation Plan

## Addendum (2026-07-31): JSON:API withdrawn before merge

Before this mission merged, the `api: true` attribute on `Release`,
`RoadmapItem`, and `CaseStudy` and `config/waaseyaa.php`'s
`api.entity_type_allowlist` (added by Task 12) were removed. On framework
alpha.276, anonymous JSON:API reads of these types return 200 with empty
data (protected-entity-read subject never carries `status` because our
fields are `Public`, not `Protected` + `authorizationInput`) rather than
serving real data, and shipping an enabled surface that returns empty data
was judged worse than not shipping it. It is withdrawn pending
waaseyaa/framework#2159. The shipped machine surfaces are HTML, Markdown
negotiation, and the `release_list` / `roadmap_read` MCP tools;
`tests/Integration/ApiAbsenceTest.php` (formerly `ApiExposureTest.php`)
proves the framework's own not-exposed diagnostic answers `/api/{type}`
instead of a real route. Every JSON:API reference in the task bodies below
describes the pre-withdrawal plan as originally executed and is superseded
by this addendum; task step bodies are kept as-is for history rather than
rewritten.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make waaseyaa.org dogfood the entity pipeline it advertises: git-authored releases, roadmap, and case studies become real revisionable entities served as HTML, Markdown, ~~JSON:API,~~ and MCP (JSON:API withdrawn before merge, see the addendum above; waaseyaa/framework#2159).

**Architecture:** Frontmatter markdown under `content/` is synced into three `ContentEntityBase` entity types by an idempotent `content:sync` CLI command (create / new-revision-on-change / unpublish-on-delete). A read-side `ContentReader` gateway feeds new controllers (`/releases`, `/roadmap`, `/production`), the sitemap, llms.txt, and two new MCP tools. ~~Anonymous JSON:API read comes from the framework: `api: true` + `group: 'content'` + `status = true` triggers the kernel's `PublishedContentAccessPolicy`.~~ Withdrawn before merge, see the addendum at the top of this document (waaseyaa/framework#2159); the shipped machine read surfaces are Markdown negotiation and MCP.

**Tech Stack:** PHP 8.5, Waaseyaa framework v0.1.0-alpha.276 (exact pin), symfony/yaml (already installed), league/commonmark, PHPUnit, Twig.

**Spec:** `docs/superpowers/specs/2026-07-31-proof-engine-design.md`

**Approved deviations from the spec** (all forced by framework reality, found during API research):
1. The roadmap item's own status field is named `status_note` because `status` is the framework's published-flag convention (`PublishedContentStatusReader` reads raw `status`); the publish flag on all three types is `public bool $status = true`.
2. Related specs on roadmap items are a comma-separated string field `related_specs` (array-typed `#[Field]` support is unverified on the sql-blob backend; a string is enough for template links).
3. The `/production` telemetry block shows uptime, temperature, and framework version; `response_ms` is an optional passthrough rendered only when the Pi telemetry JSON supplies it.
4. Revisions are opted in at registration (`EntityType::fromClass(..., revisionable: true)`), not in the attribute, and are created by default on every save of a revisionable type. There is no `newRevision` flag.

## Global Constraints

- PHP 8.5, `declare(strict_types=1)` in every file, `final class` by default, PSR-4 one-class-per-file, namespace matches directory (`App\` = `src/`, `App\Tests\` = `tests/`).
- Never use `$_ENV`; use `getenv()`.
- Framework pin is exact: `waaseyaa/framework v0.1.0-alpha.276`. Do not run `composer update`.
- No em dash characters (U+2014) and no phrase "cutting edge" in ANY authored file (templates, CSS, src, content markdown). `ContentHonestyTest` enforces this; Task 13 extends it to `content/`.
- Forbidden: Laravel/Illuminate anything, raw PDO, `env()`/`config()` helpers, ActiveRecord patterns. Entity data access via `EntityTypeManager::getRepository()`.
- Every task ends with `./vendor/bin/phpunit` green and a commit. Final task also runs `vendor/bin/phpstan analyse --no-progress` and `PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix` (CI runs these).
- Commit messages: `feat(proof-engine): <what>` style, ending with the two trailer lines used by this session's harness (Co-Authored-By + Claude-Session, see recent `git log`).
- Do not hand-edit `resources/specs/` (generated). New generated-at-deploy data lives in the DB, not in the repo.

## File Structure

```
content/
├── releases/v0.1.0-alpha.276.md        initial release note
├── roadmap/*.md                        5 roadmap items
└── case-studies/{fnpi,oiatc,waaseyaa-org}.md
src/
├── Entity/{Release,RoadmapItem,CaseStudy}.php
├── Content/{FrontMatter,ContentSync,ContentSyncReport,ContentSyncException,ContentReader}.php
├── Cli/ContentSyncHandler.php
├── Controller/{ReleasesController,RoadmapController,ProductionController}.php
├── Provider/ContentServiceProvider.php
├── Support/Markdown.php                shared CommonMark converter
└── Mcp/Tool/{ReleaseListTool,RoadmapReadTool}.php
templates/{releases-index,release,roadmap,production,case-study}.html.twig
bin/scaffold-release.php
tests/Support/ContentEntityHarness.php
tests/Unit/{FrontMatterTest,ContentEntitiesTest,ReleaseHonestyTest}.php
tests/Integration/{ContentSyncTest,ContentPagesTest,ApiExposureTest,ContentMcpToolsTest}.php
```

Key framework facts (verified against vendor at alpha.276; file paths for reference):

- Provider entity registration: `protected entityType(EntityTypeInterface)` on `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`; providers are listed in root `composer.json` `extra.waaseyaa.providers`.
- `EntityType::fromClass(Class::class, revisionable: true, revisionDefault: true, group: 'content')` reads `#[ContentEntityType]`/`#[ContentEntityKeys]`/`#[Field]`. `api:` comes only from the attribute.
- `db:init` materializes schema for ALL registered types by default (base + revision tables), and repositories also lazily `ensureTable()` on first access. No migration writing needed.
- App CLI commands: provider implements `Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface`, yields `Waaseyaa\CLI\Command\HandlerCommand`; handler receives `Waaseyaa\CLI\Command\SymfonyCommandIO`. Pattern to copy: `vendor/waaseyaa/cli/src/Provider/ConfigCacheDbAuditServiceProvider.php` (db:init) and `vendor/waaseyaa/bimaaji/src/BimaajiServiceProvider.php:233`.
- Saving a revisionable entity creates a new revision by default; `Waaseyaa\EntityStorage\EntityRepository::listRevisions(string $entityId): array` lists them. (`SaveContext` exists to suppress; we never suppress.)
- JSON:API routes are registered by `waaseyaa/api` under `/api/{type}` (GET index/show `allowAll()`, POST/PATCH/DELETE `requireAuthentication()` so anonymous gets 401 before any policy runs). Exposure = registered + `api: true` + (if configured) `api.entity_type_allowlist` in `config/waaseyaa.php`, validated at boot by `Waaseyaa\Api\EntityTypeApiExposurePolicy::fromConfig()` which throws on bad ids.
- Anonymous read: kernel unconditionally adds `Waaseyaa\Access\Policy\PublishedContentAccessPolicy`, which allows `view` for any entity whose type has `group === 'content'` and whose raw `status` normalizes to 1 (`EntityValues::statusToInt(true) === 1`). Unpublished rows are filtered from index and 404-concealed on show.

---

### Task 1: FrontMatter parser

**Files:**
- Create: `src/Content/FrontMatter.php`
- Test: `tests/Unit/FrontMatterTest.php`

**Interfaces:**
- Produces: `App\Content\FrontMatter::parse(string $raw): array{meta: array<string, mixed>, body: string}` (static). Throws `\InvalidArgumentException` on missing/unterminated delimiters or non-map YAML.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Content\FrontMatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrontMatterTest extends TestCase
{
    #[Test]
    public function parses_meta_and_body(): void
    {
        $raw = "---\ntitle: Hello\nbreaking: true\nweight: 3\n---\n\nBody text.\n";
        $parsed = FrontMatter::parse($raw);

        self::assertSame('Hello', $parsed['meta']['title']);
        self::assertTrue($parsed['meta']['breaking']);
        self::assertSame(3, $parsed['meta']['weight']);
        self::assertSame("Body text.\n", $parsed['body']);
    }

    #[Test]
    public function empty_body_is_allowed(): void
    {
        $parsed = FrontMatter::parse("---\ntitle: X\n---\n");
        self::assertSame('', $parsed['body']);
    }

    #[Test]
    public function missing_opening_delimiter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("title: X\n");
    }

    #[Test]
    public function unterminated_front_matter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("---\ntitle: X\n");
    }

    #[Test]
    public function non_map_yaml_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("---\n- just\n- a list\n---\nbody\n");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/FrontMatterTest.php`
Expected: FAIL with "Class App\Content\FrontMatter not found"

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal front matter parser for the git-authored content corpus:
 * a leading YAML block delimited by --- lines, then a markdown body.
 * Strict on purpose: malformed files must fail the sync loudly, not
 * publish half-parsed content.
 */
final class FrontMatter
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public static function parse(string $raw): array
    {
        if (!str_starts_with($raw, "---\n")) {
            throw new \InvalidArgumentException('Missing opening front matter delimiter (---).');
        }

        $end = strpos($raw, "\n---\n", 3);
        if ($end === false) {
            // Allow a file that is nothing but front matter ending in "\n---".
            if (str_ends_with(rtrim($raw, "\n"), "\n---") && substr_count($raw, "---") >= 2) {
                $end = strrpos($raw, "\n---");
                $bodyStart = strlen($raw);
            } else {
                throw new \InvalidArgumentException('Unterminated front matter block.');
            }
        } else {
            $bodyStart = $end + strlen("\n---\n");
        }

        $yaml = substr($raw, 4, (int) $end - 4);

        try {
            $meta = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid front matter YAML: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($meta) || array_is_list($meta)) {
            throw new \InvalidArgumentException('Front matter must be a YAML mapping.');
        }

        return ['meta' => $meta, 'body' => ltrim(substr($raw, $bodyStart), "\n")];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/FrontMatterTest.php`
Expected: PASS (5 tests). Adjust the `unterminated`/`empty body` edge handling if the exact offsets are off; the test file is the contract.

- [ ] **Step 5: Run the whole suite, then commit**

Run: `./vendor/bin/phpunit`
Expected: PASS.

```bash
git add src/Content/FrontMatter.php tests/Unit/FrontMatterTest.php
git commit -m "feat(proof-engine): strict front matter parser for the content corpus"
```

---

### Task 2: Entity classes and provider registration

**Files:**
- Create: `src/Entity/Release.php`, `src/Entity/RoadmapItem.php`, `src/Entity/CaseStudy.php`, `src/Provider/ContentServiceProvider.php`
- Modify: `composer.json` (add provider to `extra.waaseyaa.providers`)
- Test: `tests/Unit/ContentEntitiesTest.php`

**Interfaces:**
- Produces: `App\Entity\Release` (fields: `title`, `slug`, `version`, `released_at`, `summary`, `body`, `breaking` bool, `tag_url`, `status` bool default true, `source_sha1`); `App\Entity\RoadmapItem` (fields: `title`, `slug`, `horizon`, `status_note`, `related_specs`, `weight` int, `body`, `status`, `source_sha1`); `App\Entity\CaseStudy` (fields: `title`, `slug`, `org`, `site_url`, `summary`, `body`, `status`, `source_sha1`).
- Produces: `App\Provider\ContentServiceProvider::entityTypes(): array` (static, `list<\Waaseyaa\Entity\EntityType>`) used by both `register()` and the test harness. Entity type ids: `release`, `roadmap_item`, `case_study`; all `revisionable: true, revisionDefault: true, group: 'content'`, `api: true` via attribute.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Provider\ContentServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentEntitiesTest extends TestCase
{
    #[Test]
    public function three_content_types_are_defined_with_the_proof_engine_contract(): void
    {
        $types = ContentServiceProvider::entityTypes();
        $byId = [];
        foreach ($types as $type) {
            $byId[$type->id()] = $type;
        }

        $ids = array_keys($byId);
        sort($ids);
        self::assertSame(['case_study', 'release', 'roadmap_item'], $ids);

        foreach ($byId as $id => $type) {
            self::assertTrue($type->isRevisionable(), $id . ' must be revisionable');
            self::assertSame('content', $type->getGroup(), $id . ' must be in the content group for public read');
            self::assertTrue($type->isApiExposed(), $id . ' must declare api: true');
        }
    }

    #[Test]
    public function entities_default_to_published(): void
    {
        self::assertTrue(new \App\Entity\Release(['title' => 'x'])->get('status'));
        self::assertTrue(new \App\Entity\RoadmapItem(['title' => 'x'])->get('status'));
        self::assertTrue(new \App\Entity\CaseStudy(['title' => 'x'])->get('status'));
    }
}
```

Note: if `EntityType` exposes different accessor names than `id()`/`getGroup()`/`isRevisionable()`/`isApiExposed()`, check `vendor/waaseyaa/entity/src/EntityType.php` (accessors confirmed present at alpha.276: `isApiExposed()` at line 439; `getGroup()` and `isRevisionable()` are the standard accessors) and match them in the test, not the other way around.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/ContentEntitiesTest.php`
Expected: FAIL, provider class not found.

- [ ] **Step 3: Write the three entity classes**

`src/Entity/Release.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One tracked waaseyaa/framework release. Git-authored: synced from
 * content/releases/{version}.md by content:sync; never written at runtime.
 */
#[ContentEntityType(id: 'release', label: 'Release', description: 'A tracked waaseyaa/framework release.', api: true)]
#[ContentEntityKeys(label: 'title')]
final class Release extends ContentEntityBase
{
    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(label: 'Slug', required: true, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Version', required: true, read: FieldReadLevel::Public)]
    public string $version = '';

    #[Field(label: 'Released at', required: true, read: FieldReadLevel::Public)]
    public string $released_at = '';

    #[Field(label: 'Summary', required: true, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Breaking changes', read: FieldReadLevel::Public)]
    public bool $breaking = false;

    #[Field(label: 'Tag URL', read: FieldReadLevel::Public)]
    public string $tag_url = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
```

`src/Entity/RoadmapItem.php` (same shape; only the field list differs):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One roadmap item, grouped by stage-based horizon (now / next / later),
 * never by date. Git-authored via content/roadmap/*.md.
 */
#[ContentEntityType(id: 'roadmap_item', label: 'Roadmap item', description: 'A stage-based roadmap item for the Waaseyaa framework.', api: true)]
#[ContentEntityKeys(label: 'title')]
final class RoadmapItem extends ContentEntityBase
{
    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(label: 'Slug', required: true, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Horizon', required: true, read: FieldReadLevel::Public)]
    public string $horizon = 'later';

    #[Field(label: 'Status note', required: true, read: FieldReadLevel::Public)]
    public string $status_note = '';

    #[Field(label: 'Related specs', read: FieldReadLevel::Public)]
    public string $related_specs = '';

    #[Field(label: 'Weight', read: FieldReadLevel::Public)]
    public int $weight = 0;

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
```

`src/Entity/CaseStudy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One production deployment write-up. Git-authored via
 * content/case-studies/*.md.
 */
#[ContentEntityType(id: 'case_study', label: 'Case study', description: 'A production Waaseyaa deployment.', api: true)]
#[ContentEntityKeys(label: 'title')]
final class CaseStudy extends ContentEntityBase
{
    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(label: 'Slug', required: true, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Organization', required: true, read: FieldReadLevel::Public)]
    public string $org = '';

    #[Field(label: 'Site URL', read: FieldReadLevel::Public)]
    public string $site_url = '';

    #[Field(label: 'Summary', required: true, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
```

- [ ] **Step 4: Write the provider (registration only for now)**

`src/Provider/ContentServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Provider;

use App\Entity\CaseStudy;
use App\Entity\Release;
use App\Entity\RoadmapItem;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * The proof engine: git-authored release / roadmap / case-study content
 * registered as real revisionable entities. group: 'content' + status=true
 * is what grants anonymous read via the kernel's PublishedContentAccessPolicy;
 * there is no app-side write surface (content:sync is the only writer).
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (self::entityTypes() as $type) {
            $this->entityType($type);
        }
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
```

- [ ] **Step 5: Register the provider in composer.json**

In `composer.json`, `extra.waaseyaa.providers`, append `"App\\Provider\\ContentServiceProvider"` after the existing two entries. Do not touch anything else in the file.

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/ContentEntitiesTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite, commit**

Run: `./vendor/bin/phpunit`
Expected: PASS.

```bash
git add src/Entity src/Provider/ContentServiceProvider.php composer.json tests/Unit/ContentEntitiesTest.php
git commit -m "feat(proof-engine): release, roadmap_item, case_study entity types (revisionable, content group, api)"
```

---

### Task 3: Test harness + ContentSync engine

**Files:**
- Create: `tests/Support/ContentEntityHarness.php`, `src/Content/ContentSync.php`, `src/Content/ContentSyncReport.php`, `src/Content/ContentSyncException.php`
- Test: `tests/Integration/ContentSyncTest.php`

**Interfaces:**
- Consumes: `ContentServiceProvider::entityTypes()`, `FrontMatter::parse()`.
- Produces: `App\Tests\Support\ContentEntityHarness::entityTypeManager(): \Waaseyaa\Entity\EntityTypeManager` (static; in-memory SQLite, all three types registered).
- Produces: `App\Content\ContentSync::__construct(\Waaseyaa\Entity\EntityTypeManager $entityTypeManager, string $contentRoot)`; `sync(): ContentSyncReport`. Throws `App\Content\ContentSyncException` (message includes the offending file path) on any malformed file.
- Produces: `App\Content\ContentSyncReport` with `public int $created`, `$updated`, `$unpublished`, `$unchanged` and `summary(): string`.

- [ ] **Step 1: Write the harness**

`tests/Support/ContentEntityHarness.php` (modeled exactly on `tests/Tutorial/TodoAppTest.php::setUp`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Provider\ContentServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;

/**
 * In-memory entity stack registering exactly the types production
 * registers (ContentServiceProvider::entityTypes()).
 */
final class ContentEntityHarness
{
    public static function entityTypeManager(): EntityTypeManager
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $manager = new EntityTypeManager(
            $dispatcher,
            repositoryFactory: function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher) {
                $schemaHandler = new SqlSchemaHandler($definition, $database);
                $schemaHandler->ensureTable();
                if ($definition->isRevisionable()) {
                    $schemaHandler->ensureRevisionTable();
                }

                $resolver = new SingleConnectionResolver($database);
                $driver = new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id');

                return V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    $driver,
                    $dispatcher,
                    database: $database,
                );
            },
        );

        foreach (ContentServiceProvider::entityTypes() as $type) {
            $manager->registerEntityType($type);
        }

        return $manager;
    }
}
```

Contingency (check before debugging test failures): if revision writes fail through this factory, inspect `vendor/waaseyaa/entity-storage/src/Testing/V2EntityRepositoryFactory.php` for a revision-capable factory method and, failing that, mirror how `vendor/waaseyaa/foundation/src/Kernel/EntityTypeManagerFactory.php` wraps `SqlStorageDriver` in `RevisionableStorageDriverV2` for revisionable definitions. The harness must end up with `listRevisions()` working.

- [ ] **Step 2: Write the failing sync test**

`tests/Integration/ContentSyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentSync;
use App\Content\ContentSyncException;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;

final class ContentSyncTest extends TestCase
{
    private EntityTypeManager $manager;
    private string $root;

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        $this->root = sys_get_temp_dir() . '/content-sync-' . bin2hex(random_bytes(6));
        foreach (['releases', 'roadmap', 'case-studies'] as $dir) {
            mkdir($this->root . '/' . $dir, recursive: true);
        }
    }

    private function writeRelease(string $version, string $summary = 'A release.'): void
    {
        file_put_contents(
            $this->root . '/releases/' . $version . '.md',
            "---\ntitle: Framework {$version}\nversion: {$version}\nreleased_at: \"2026-07-27\"\nsummary: {$summary}\n---\n\nHighlights here.\n",
        );
    }

    private function sync(): \App\Content\ContentSyncReport
    {
        return new ContentSync($this->manager, $this->root)->sync();
    }

    #[Test]
    public function first_sync_creates_entities(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $report = $this->sync();

        self::assertSame(1, $report->created);
        $all = $this->manager->getRepository('release')->findBy([]);
        self::assertCount(1, $all);
        self::assertSame('v0.1.0-alpha.900', $all[0]->get('slug'));
        self::assertSame(1, EntityValues::statusToInt($all[0]->get('status')));
        self::assertNotSame('', (string) $all[0]->get('source_sha1'));
    }

    #[Test]
    public function second_sync_is_a_noop(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $this->sync();
        $report = $this->sync();

        self::assertSame(0, $report->created + $report->updated + $report->unpublished);
        self::assertSame(1, $report->unchanged);

        $repository = $this->manager->getRepository('release');
        $entity = $repository->findBy([])[0];
        self::assertCount(1, $repository->listRevisions((string) $entity->id()), 'no-op sync must not add revisions');
    }

    #[Test]
    public function changed_file_saves_a_new_revision(): void
    {
        $this->writeRelease('v0.1.0-alpha.900', 'Original summary.');
        $this->sync();
        $this->writeRelease('v0.1.0-alpha.900', 'Amended summary.');
        $report = $this->sync();

        self::assertSame(1, $report->updated);

        $repository = $this->manager->getRepository('release');
        $entity = $repository->findBy([])[0];
        self::assertSame('Amended summary.', $entity->get('summary'));
        self::assertGreaterThanOrEqual(2, count($repository->listRevisions((string) $entity->id())));
    }

    #[Test]
    public function removed_file_unpublishes_but_keeps_history(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $this->sync();
        unlink($this->root . '/releases/v0.1.0-alpha.900.md');
        $report = $this->sync();

        self::assertSame(1, $report->unpublished);
        $entity = $this->manager->getRepository('release')->findBy([])[0];
        self::assertSame(0, EntityValues::statusToInt($entity->get('status')));
    }

    #[Test]
    public function malformed_front_matter_fails_loudly_with_the_file_path(): void
    {
        file_put_contents($this->root . '/releases/bad.md', "no front matter\n");

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/bad\.md/');
        $this->sync();
    }

    #[Test]
    public function missing_required_key_fails_loudly(): void
    {
        file_put_contents($this->root . '/releases/v1.md', "---\ntitle: X\n---\nbody\n");

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/version/');
        $this->sync();
    }

    #[Test]
    public function unknown_key_fails_loudly(): void
    {
        file_put_contents(
            $this->root . '/releases/v1.md',
            "---\ntitle: X\nversion: v1\nreleased_at: \"2026-01-01\"\nsummary: s\nsurprise: y\n---\n",
        );

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/surprise/');
        $this->sync();
    }

    #[Test]
    public function invalid_horizon_fails_loudly(): void
    {
        file_put_contents(
            $this->root . '/roadmap/thing.md',
            "---\ntitle: X\nhorizon: someday\nstatus_note: open\n---\n",
        );

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/horizon/');
        $this->sync();
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Integration/ContentSyncTest.php`
Expected: FAIL, ContentSync not found.

- [ ] **Step 4: Write the sync engine**

`src/Content/ContentSyncException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content;

final class ContentSyncException extends \RuntimeException
{
}
```

`src/Content/ContentSyncReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content;

final class ContentSyncReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $unpublished = 0;
    public int $unchanged = 0;

    public function summary(): string
    {
        return sprintf(
            'created %d, updated %d, unpublished %d, unchanged %d',
            $this->created,
            $this->updated,
            $this->unpublished,
            $this->unchanged,
        );
    }
}
```

`src/Content/ContentSync.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;

/**
 * Git-to-entity sync: content/<dir>/*.md becomes release / roadmap_item /
 * case_study entities. Idempotent by source sha1: unchanged files are
 * no-ops, changed files save (which creates a new revision on these
 * revisionable types), files removed from git unpublish their entity but
 * keep its history. Any malformed file aborts the whole sync loudly.
 */
final class ContentSync
{
    private const array KINDS = [
        'release' => [
            'dir' => 'releases',
            'required' => ['title', 'version', 'released_at', 'summary'],
            'optional' => ['breaking', 'tag_url'],
        ],
        'roadmap_item' => [
            'dir' => 'roadmap',
            'required' => ['title', 'horizon', 'status_note'],
            'optional' => ['related_specs', 'weight'],
        ],
        'case_study' => [
            'dir' => 'case-studies',
            'required' => ['title', 'org', 'summary'],
            'optional' => ['site_url'],
        ],
    ];

    private const array HORIZONS = ['now', 'next', 'later'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly string $contentRoot,
    ) {
    }

    public function sync(): ContentSyncReport
    {
        $report = new ContentSyncReport();

        foreach (self::KINDS as $entityTypeId => $kind) {
            $files = $this->parseKind($entityTypeId, $kind);
            $repository = $this->entityTypeManager->getRepository($entityTypeId);

            $existing = [];
            foreach ($repository->findBy([]) as $entity) {
                $existing[(string) $entity->get('slug')] = $entity;
            }

            foreach ($files as $slug => $fields) {
                $current = $existing[$slug] ?? null;

                if ($current !== null
                    && (string) $current->get('source_sha1') === $fields['source_sha1']
                    && EntityValues::statusToInt($current->get('status')) === 1
                ) {
                    ++$report->unchanged;
                    continue;
                }

                if ($current === null) {
                    $repository->save($repository->create($fields));
                    ++$report->created;
                    continue;
                }

                foreach ($fields as $name => $value) {
                    $current->set($name, $value);
                }
                $repository->save($current);
                ++$report->updated;
            }

            foreach ($existing as $slug => $entity) {
                if (!isset($files[$slug]) && EntityValues::statusToInt($entity->get('status')) === 1) {
                    $entity->set('status', false);
                    $repository->save($entity);
                    ++$report->unpublished;
                }
            }
        }

        return $report;
    }

    /**
     * @param array{dir: string, required: list<string>, optional: list<string>} $kind
     * @return array<string, array<string, mixed>> slug => entity field values
     */
    private function parseKind(string $entityTypeId, array $kind): array
    {
        $dir = $this->contentRoot . '/' . $kind['dir'];
        $out = [];

        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            $raw = (string) file_get_contents($file);

            try {
                $parsed = FrontMatter::parse($raw);
            } catch (\InvalidArgumentException $e) {
                throw new ContentSyncException($file . ': ' . $e->getMessage(), 0, $e);
            }

            $meta = $parsed['meta'];
            $allowed = array_merge($kind['required'], $kind['optional']);

            foreach ($kind['required'] as $key) {
                if (!isset($meta[$key]) || $meta[$key] === '') {
                    throw new ContentSyncException($file . ': missing required front matter key "' . $key . '".');
                }
            }
            foreach (array_keys($meta) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw new ContentSyncException($file . ': unknown front matter key "' . $key . '".');
                }
            }
            if ($entityTypeId === 'roadmap_item' && !in_array($meta['horizon'], self::HORIZONS, true)) {
                throw new ContentSyncException($file . ': horizon must be one of now, next, later.');
            }
            if ($entityTypeId === 'release' && $slug !== $meta['version']) {
                throw new ContentSyncException($file . ': filename must equal the version front matter key.');
            }

            $fields = $meta;
            $fields['slug'] = $slug;
            $fields['body'] = $parsed['body'];
            $fields['status'] = true;
            $fields['source_sha1'] = sha1($raw);

            // Normalize scalar types the YAML parser may widen.
            if (isset($fields['released_at'])) {
                $fields['released_at'] = (string) $fields['released_at'];
            }
            if (isset($fields['weight'])) {
                $fields['weight'] = (int) $fields['weight'];
            }
            if (isset($fields['breaking'])) {
                $fields['breaking'] = (bool) $fields['breaking'];
            }

            $out[$slug] = $fields;
        }

        ksort($out);

        return $out;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Integration/ContentSyncTest.php`
Expected: PASS (8 tests). If `listRevisions()` fails, apply the harness contingency from Step 1 before touching ContentSync.

- [ ] **Step 6: Full suite, commit**

```bash
./vendor/bin/phpunit
git add src/Content tests/Support tests/Integration/ContentSyncTest.php
git commit -m "feat(proof-engine): idempotent git-to-entity content sync with revisions and loud failure"
```

---

### Task 4: content:sync CLI command

**Files:**
- Create: `src/Cli/ContentSyncHandler.php`
- Modify: `src/Provider/ContentServiceProvider.php` (implement `ProvidesConsoleCommandsInterface`)

**Interfaces:**
- Consumes: `ContentSync`, `ContentSyncReport`, `ContentSyncException`.
- Produces: CLI command `content:sync` on `vendor/bin/waaseyaa`; exit 0 on success, 1 on sync failure.

- [ ] **Step 1: Write the handler**

`src/Cli/ContentSyncHandler.php` (kernel-boot pattern copied from `vendor/waaseyaa/cli/src/Handler/DbInitHandler.php::syncSchema`):

```php
<?php

declare(strict_types=1);

namespace App\Cli;

use App\Content\ContentSync;
use App\Content\ContentSyncException;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * `waaseyaa content:sync`: sync content/*.md into entities. Runs as a
 * one-shot on deploy right after db:init; a failure exits non-zero so
 * the deploy fails rather than half-publishing.
 */
final class ContentSyncHandler
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForCli();

        try {
            $report = new ContentSync(
                $kernel->getEntityTypeManager(),
                $this->projectRoot . '/content',
            )->sync();
            $io->writeln('content:sync ' . $report->summary());

            return 0;
        } catch (ContentSyncException $e) {
            $io->error('content:sync failed: ' . $e->getMessage());

            return 1;
        }
    }
}
```

Before finalizing, open `vendor/waaseyaa/cli/src/Handler/DbInitHandler.php` and mirror its `finally` block for closing the booted kernel's database connection (method name as used there); add the same `try { ... } finally { ... }` around the sync if DbInitHandler closes explicitly.

- [ ] **Step 2: Export the command from the provider**

Modify `src/Provider/ContentServiceProvider.php`: add `implements \Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface` and:

```php
public function consoleCommands(): iterable
{
    $root = $this->projectRoot !== '' ? $this->projectRoot : dirname(__DIR__, 2);

    yield new \Waaseyaa\CLI\Command\HandlerCommand(
        name: 'content:sync',
        description: 'Sync content/*.md into release, roadmap_item, and case_study entities (idempotent; new revision on change; unpublish on delete).',
        handler: \Closure::fromCallable([new \App\Cli\ContentSyncHandler($root), 'execute']),
    );
}
```

- [ ] **Step 3: Verify the command registers and runs end-to-end**

```bash
mkdir -p content/releases content/roadmap content/case-studies
WAASEYAA_DB=$(mktemp -u).sqlite ./vendor/bin/waaseyaa db:init
WAASEYAA_DB=<same path> ./vendor/bin/waaseyaa content:sync
```

Expected: `content:sync created 0, updated 0, unpublished 0, unchanged 0` and exit 0 (directories are still empty; Task 5 adds content). Use one literal temp path for both commands. If `bin/waaseyaa` does not exist at the repo root, the binary is `vendor/bin/waaseyaa`.

- [ ] **Step 4: Full suite, commit**

```bash
./vendor/bin/phpunit
git add src/Cli src/Provider/ContentServiceProvider.php content
git commit -m "feat(proof-engine): content:sync CLI command (one-shot deploy step)"
```

Note: if `git add content` fails because empty directories are not tracked, add a `.gitkeep` file to each of the three `content/` subdirectories (they will be replaced by real content in Task 5).

---

### Task 5: Initial content files + scaffold helper

**Files:**
- Create: `content/releases/v0.1.0-alpha.276.md`, `content/roadmap/admin-composable-listings.md`, `content/roadmap/guides-tier.md`, `content/roadmap/chat-retrieval-quality.md`, `content/roadmap/mcp-registry-listing.md`, `content/roadmap/beta-criteria.md`, `content/case-studies/fnpi.md`, `content/case-studies/oiatc.md`, `content/case-studies/waaseyaa-org.md`, `bin/scaffold-release.php`

**Interfaces:**
- Consumes: the front matter contract from Task 3 (`ContentSync::KINDS`).
- Produces: a corpus that `content:sync` ingests cleanly; `php bin/scaffold-release.php <version>` writes a prefilled release file.

- [ ] **Step 1: Write the release note for the running version**

`content/releases/v0.1.0-alpha.276.md` (released_at comes from the manifest's `source_released_at`, which is `2026-07-27T17:50:36+00:00` for alpha.276):

```markdown
---
title: Framework v0.1.0-alpha.276
version: v0.1.0-alpha.276
released_at: "2026-07-27"
summary: Production boot gates for auth token secrets and field-access classification, a DecisionAccountResolver gate on the MCP endpoint, and an 83-spec corpus.
breaking: true
tag_url: https://github.com/waaseyaa/framework/releases/tag/v0.1.0-alpha.276
---

This is the release currently serving waaseyaa.org.

- Production kernels now refuse to boot without an auth token secret and
  without explicit field-access classification for outward surfaces.
- The MCP endpoint requires accounts to cross the DecisionAccountResolver
  gate; this site's public SpecReaderAccount implements
  AuthorizationPrincipalInterface to satisfy it.
- waaseyaa/ai-agent is no longer bundled with the framework package;
  consuming apps declare it explicitly.
- The spec corpus this site serves grew to 83 specs.

Breaking for consuming apps: the boot gates above must be configured
before an upgrade boots in production.
```

- [ ] **Step 2: Write the five roadmap items**

`content/roadmap/admin-composable-listings.md`:

```markdown
---
title: Admin-composable listings
horizon: next
status_note: Not started; the largest named gap against Drupal Views
related_specs: drupal-comparison-matrix, listing-pipeline-v1
weight: 0
---

Listings are controllers plus templates today. The framework's own
comparison matrix names admin-composable listings as its largest gap
against Drupal. The listing pipeline spec is the staging ground.
```

`content/roadmap/guides-tier.md`:

```markdown
---
title: Curated guides tier on waaseyaa.org
horizon: now
status_note: Planned as the next mission for this site
related_specs: workflow
weight: 0
---

The spec corpus stays verbatim as the reference tier. A human-written
guides tier (concepts, how-tos, tested tutorials in the style of /start)
goes on top, linking down into the specs.
```

`content/roadmap/chat-retrieval-quality.md`:

```markdown
---
title: Docs chat retrieval quality pass
horizon: next
status_note: Known mis-ordering cases documented; index works, refinement queued
related_specs: workspace-chat-surface
weight: 1
---

Retrieval ranks specs through a title-weighted FTS5 index. Remaining
refinements: stemmed token comparison so plural queries match, and title
weighting that is robust to long titles repeating a package name.
```

`content/roadmap/mcp-registry-listing.md`:

```markdown
---
title: MCP registry listing
horizon: now
status_note: Deploy-time follow-up; server card already published
related_specs: mcp-endpoint
weight: 1
---

The public MCP endpoint and server card are live. Submitting the server
to public MCP registries is a deploy-time follow-up.
```

`content/roadmap/beta-criteria.md`:

```markdown
---
title: Beta stage criteria
horizon: later
status_note: Alpha; APIs move within the bounds of the stability charter
related_specs: stability-charter
weight: 0
---

Waaseyaa is alpha and says so. The stability charter defines which
surfaces are protected today; beta is declared when the charter's
protected set covers the framework's public surface.
```

- [ ] **Step 3: Write the three case studies**

`content/case-studies/fnpi.md`:

```markdown
---
title: First Nations Procurement Inc.
org: First Nations Procurement Inc.
site_url: https://fnprocure.ca
summary: fnprocure.ca and the FNPI internal workspace run on Waaseyaa in production.
---

First Nations Procurement Inc. runs its public site and its internal
workspace on Waaseyaa. It is the framework's first production
deployment and the origin of several framework subsystems, including
the workspace chat surface this site reuses for its docs assistant.
```

`content/case-studies/oiatc.md`:

```markdown
---
title: oiatc.ca
org: Ontario Indigenous Agriculture Technical Committee
site_url: https://oiatc.ca
summary: oiatc.ca is served by a Waaseyaa application from a Raspberry Pi.
---

oiatc.ca runs as a Waaseyaa application on the same Raspberry Pi stack
that serves this site: one PHP process, a SQLite file, Caddy in front,
deployed over Tailscale by a GitHub Action.
```

`content/case-studies/waaseyaa-org.md`:

```markdown
---
title: waaseyaa.org
org: Waaseyaa
site_url: https://waaseyaa.org
summary: This site is a Waaseyaa application; the page you are reading is served by the framework it documents.
---

waaseyaa.org dogfoods the framework: the spec corpus is served as HTML,
Markdown, and MCP from one source; the docs chat is grounded on the same
index the MCP search tool uses; and the releases, roadmap, and case
studies (including this one) are revisionable entities synced from
markdown files in git. There is no admin surface and there are no
accounts; publishing is a git push.
```

- [ ] **Step 4: Write the scaffold helper**

`bin/scaffold-release.php`:

```php
<?php

declare(strict_types=1);

/**
 * Scaffold a release note: php bin/scaffold-release.php v0.1.0-alpha.277
 * Prefills front matter; the summary and body are then written by hand.
 * If the scaffolded version matches the locked framework version, the
 * release date is prefilled from the corpus manifest.
 */

$version = $argv[1] ?? '';
if (preg_match('/^v\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Usage: php bin/scaffold-release.php vX.Y.Z[-suffix]\n");
    exit(2);
}

$root = dirname(__DIR__);
$target = $root . '/content/releases/' . $version . '.md';
if (is_file($target)) {
    fwrite(STDERR, "Already exists: {$target}\n");
    exit(1);
}

$date = date('Y-m-d');
$manifest = json_decode((string) @file_get_contents($root . '/resources/specs/manifest.json'), true);
if (is_array($manifest) && ($manifest['framework_version'] ?? null) === $version && is_string($manifest['source_released_at'] ?? null)) {
    $date = substr($manifest['source_released_at'], 0, 10);
}

file_put_contents($target, <<<MD
---
title: Framework {$version}
version: {$version}
released_at: "{$date}"
summary: One-sentence summary of the release.
breaking: false
tag_url: https://github.com/waaseyaa/framework/releases/tag/{$version}
---

Write the highlights here, honestly. Delete this line.
MD . "\n");

echo "Wrote {$target}\n";
```

- [ ] **Step 5: Verify the corpus syncs cleanly**

```bash
rm -f content/releases/.gitkeep content/roadmap/.gitkeep content/case-studies/.gitkeep
DB=$(mktemp -u).sqlite; WAASEYAA_DB=$DB ./vendor/bin/waaseyaa db:init && WAASEYAA_DB=$DB ./vendor/bin/waaseyaa content:sync
```

Expected: `content:sync created 9, updated 0, unpublished 0, unchanged 0`, exit 0.

- [ ] **Step 6: Full suite, commit**

```bash
./vendor/bin/phpunit
git add content bin/scaffold-release.php
git commit -m "feat(proof-engine): initial release, roadmap, and case-study corpus + release scaffold"
```

---

### Task 6: ContentReader and shared Markdown support

**Files:**
- Create: `src/Content/ContentReader.php`, `src/Support/Markdown.php`
- Modify: `src/Controller/DocsController.php` (use the shared converter; delete its private one)
- Test: extend `tests/Integration/ContentSyncTest.php` with reader coverage (same harness)

**Interfaces:**
- Consumes: harness, ContentSync.
- Produces: `App\Content\ContentReader::__construct(?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager)` with:
  - `releases(): list<\Waaseyaa\Entity\EntityInterface>` published only, `released_at` descending
  - `release(string $version): ?\Waaseyaa\Entity\EntityInterface`
  - `roadmap(): array{now: list<\Waaseyaa\Entity\EntityInterface>, next: list<...>, later: list<...>}` weight ascending then title
  - `caseStudies(): list<\Waaseyaa\Entity\EntityInterface>` title ascending
  - `caseStudy(string $slug): ?\Waaseyaa\Entity\EntityInterface`
  - `revisionCount(string $entityTypeId, string $entityId): ?int` (null when unavailable)
  - All methods return empty/null (never throw) when the manager is null or the read fails; failures log via `\App\Support\OperationalLog::warning('content_read_failed', $e)`.
- Produces: `App\Support\Markdown::toHtml(string $markdown): string` (static; CommonMark core + tables + autolink, `html_input: allow`, `allow_unsafe_links: false`, identical to the current DocsController converter config).

- [ ] **Step 1: Write the failing reader tests** (append to `ContentSyncTest` or a new `ContentReaderTest` in the same style; new file preferred)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

final class ContentReaderTest extends TestCase
{
    private EntityTypeManager $manager;
    private string $root;

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        $this->root = sys_get_temp_dir() . '/content-reader-' . bin2hex(random_bytes(6));
        foreach (['releases', 'roadmap', 'case-studies'] as $dir) {
            mkdir($this->root . '/' . $dir, recursive: true);
        }
    }

    private function release(string $version, string $date): void
    {
        file_put_contents(
            $this->root . '/releases/' . $version . '.md',
            "---\ntitle: {$version}\nversion: {$version}\nreleased_at: \"{$date}\"\nsummary: s\n---\nb\n",
        );
    }

    #[Test]
    public function releases_are_published_only_newest_first(): void
    {
        $this->release('v0.1.0-alpha.900', '2026-01-01');
        $this->release('v0.1.0-alpha.901', '2026-02-01');
        new ContentSync($this->manager, $this->root)->sync();
        unlink($this->root . '/releases/v0.1.0-alpha.900.md');
        new ContentSync($this->manager, $this->root)->sync(); // unpublishes .900

        $reader = new ContentReader($this->manager);
        $versions = array_map(fn ($e) => $e->get('version'), $reader->releases());
        self::assertSame(['v0.1.0-alpha.901'], $versions);
        self::assertNull($reader->release('v0.1.0-alpha.900'), 'unpublished must not resolve');
        self::assertNotNull($reader->release('v0.1.0-alpha.901'));
    }

    #[Test]
    public function roadmap_groups_by_horizon(): void
    {
        file_put_contents($this->root . '/roadmap/a.md', "---\ntitle: A\nhorizon: now\nstatus_note: open\nweight: 1\n---\n");
        file_put_contents($this->root . '/roadmap/b.md', "---\ntitle: B\nhorizon: now\nstatus_note: open\nweight: 0\n---\n");
        file_put_contents($this->root . '/roadmap/c.md', "---\ntitle: C\nhorizon: later\nstatus_note: open\n---\n");
        new ContentSync($this->manager, $this->root)->sync();

        $grouped = new ContentReader($this->manager)->roadmap();
        self::assertSame(['B', 'A'], array_map(fn ($e) => $e->get('title'), $grouped['now']));
        self::assertSame(['C'], array_map(fn ($e) => $e->get('title'), $grouped['later']));
        self::assertSame([], $grouped['next']);
    }

    #[Test]
    public function null_manager_yields_empty_never_throws(): void
    {
        $reader = new ContentReader(null);
        self::assertSame([], $reader->releases());
        self::assertSame(['now' => [], 'next' => [], 'later' => []], $reader->roadmap());
        self::assertSame([], $reader->caseStudies());
        self::assertNull($reader->release('v1'));
        self::assertNull($reader->caseStudy('x'));
        self::assertNull($reader->revisionCount('release', '1'));
    }
}
```

- [ ] **Step 2: Run to verify failure, then implement**

`src/Support/Markdown.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The one CommonMark configuration this site renders with (docs pages
 * and content pages alike).
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        if (self::$converter === null) {
            $environment = new Environment([
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            $environment->addExtension(new AutolinkExtension());
            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter->convert($markdown)->getContent();
    }
}
```

`src/Content/ContentReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content;

use App\Support\OperationalLog;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\EntityStorage\EntityRepository;

/**
 * Read-side gateway over the synced content entities. Never throws:
 * a public page must degrade to an honest empty state, not a 500,
 * when the database has no synced content yet.
 */
final class ContentReader
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
    ) {
    }

    /** @return list<EntityInterface> */
    public function releases(): array
    {
        $releases = $this->published('release');
        usort($releases, fn (EntityInterface $a, EntityInterface $b): int => strcmp((string) $b->get('released_at'), (string) $a->get('released_at')));

        return $releases;
    }

    public function release(string $version): ?EntityInterface
    {
        foreach ($this->published('release') as $entity) {
            if ((string) $entity->get('version') === $version) {
                return $entity;
            }
        }

        return null;
    }

    /** @return array{now: list<EntityInterface>, next: list<EntityInterface>, later: list<EntityInterface>} */
    public function roadmap(): array
    {
        $grouped = ['now' => [], 'next' => [], 'later' => []];
        foreach ($this->published('roadmap_item') as $entity) {
            $horizon = (string) $entity->get('horizon');
            if (isset($grouped[$horizon])) {
                $grouped[$horizon][] = $entity;
            }
        }
        foreach ($grouped as &$items) {
            usort($items, fn (EntityInterface $a, EntityInterface $b): int => [(int) $a->get('weight'), (string) $a->get('title')] <=> [(int) $b->get('weight'), (string) $b->get('title')]);
        }

        return $grouped;
    }

    /** @return list<EntityInterface> */
    public function caseStudies(): array
    {
        $studies = $this->published('case_study');
        usort($studies, fn (EntityInterface $a, EntityInterface $b): int => strcmp((string) $a->get('title'), (string) $b->get('title')));

        return $studies;
    }

    public function caseStudy(string $slug): ?EntityInterface
    {
        foreach ($this->published('case_study') as $entity) {
            if ((string) $entity->get('slug') === $slug) {
                return $entity;
            }
        }

        return null;
    }

    public function revisionCount(string $entityTypeId, string $entityId): ?int
    {
        try {
            $repository = $this->entityTypeManager?->getRepository($entityTypeId);
            if (!$repository instanceof EntityRepository) {
                return null;
            }

            return count($repository->listRevisions($entityId));
        } catch (\Throwable $e) {
            OperationalLog::warning('content_read_failed', $e);

            return null;
        }
    }

    /** @return list<EntityInterface> */
    private function published(string $entityTypeId): array
    {
        if ($this->entityTypeManager === null) {
            return [];
        }

        try {
            $all = $this->entityTypeManager->getRepository($entityTypeId)->findBy([]);
        } catch (\Throwable $e) {
            OperationalLog::warning('content_read_failed', $e);

            return [];
        }

        return array_values(array_filter(
            $all,
            fn (EntityInterface $entity): bool => EntityValues::statusToInt($entity->get('status')) === 1,
        ));
    }
}
```

Check `src/Support/OperationalLog.php` for the exact `warning()` signature before use (it is already called as `OperationalLog::warning('chat_schema_ensure_failed', $e)` in DocsServiceProvider, so `(string, \Throwable)` is the shape).

- [ ] **Step 3: Refactor DocsController to the shared converter**

In `src/Controller/DocsController.php`: delete the private `$converter` property, the `converter()` method, and the League imports; replace the one call site `$this->converter()->convert($markdown)->getContent()` with `\App\Support\Markdown::toHtml($markdown)`. Existing `DocsRenderingTest` protects this refactor.

- [ ] **Step 4: Run tests, commit**

```bash
./vendor/bin/phpunit
git add src/Content/ContentReader.php src/Support/Markdown.php src/Controller/DocsController.php tests/Integration/ContentReaderTest.php
git commit -m "feat(proof-engine): ContentReader gateway + shared markdown converter"
```

---

### Task 7: /releases pages

**Files:**
- Create: `src/Controller/ReleasesController.php`, `templates/releases-index.html.twig`, `templates/release.html.twig`
- Modify: `src/Provider/ContentServiceProvider.php` (add `routes()`)
- Test: `tests/Integration/ContentPagesTest.php` (new)

**Interfaces:**
- Consumes: `ContentReader`, `SpecCorpus` (framework version), `SiteUrl`, `Markdown`, `MarkdownNegotiation` (existing: `App\Docs\MarkdownNegotiation::wantsMarkdown(Request): bool`).
- Produces: routes `releases.index` GET `/releases`, `releases.show` GET `/releases/{version}` (both `allowAll()`); `App\Controller\ReleasesController::__construct(ContentReader $reader, SpecCorpus $corpus, SiteUrl $urls)`, `index(Request): Response`, `show(Request, string $version): Response`.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/ContentPagesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Controller\ReleasesController;
use App\Docs\SpecCorpus;
use App\Provider\ContentServiceProvider;
use App\Support\SiteUrl;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

final class ContentPagesTest extends TestCase
{
    private EntityTypeManager $manager;

    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();
    }

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        // Sync the REAL repo corpus so tests cover the shipped content.
        new ContentSync($this->manager, dirname(__DIR__, 2) . '/content')->sync();
    }

    private function releases(): ReleasesController
    {
        return new ReleasesController(
            new ContentReader($this->manager),
            SpecCorpus::default(),
            new SiteUrl('https://waaseyaa.org'),
        );
    }

    #[Test]
    public function content_routes_are_registered(): void
    {
        $router = new WaaseyaaRouter();
        new ContentServiceProvider()->routes($router, $this->manager);

        self::assertSame('releases.index', $router->match('/releases')['_route'] ?? null);
        self::assertSame('releases.show', $router->match('/releases/v0.1.0-alpha.276')['_route'] ?? null);
        self::assertSame('roadmap', $router->match('/roadmap')['_route'] ?? null);
        self::assertSame('production.index', $router->match('/production')['_route'] ?? null);
        self::assertSame('production.show', $router->match('/production/fnpi')['_route'] ?? null);
    }

    #[Test]
    public function releases_index_renders_the_locked_version(): void
    {
        $response = $this->releases()->index(Request::create('/releases'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('v0.1.0-alpha.276', (string) $response->getContent());
    }

    #[Test]
    public function releases_index_negotiates_markdown(): void
    {
        $response = $this->releases()->index(Request::create('/releases', server: ['HTTP_ACCEPT' => 'text/markdown']));
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('v0.1.0-alpha.276', (string) $response->getContent());
    }

    #[Test]
    public function release_page_serves_html_markdown_and_404(): void
    {
        $html = $this->releases()->show(Request::create('/releases/v0.1.0-alpha.276'), 'v0.1.0-alpha.276');
        self::assertSame(200, $html->getStatusCode());
        self::assertStringContainsString('alpha.276', (string) $html->getContent());

        $md = $this->releases()->show(Request::create('/releases/v0.1.0-alpha.276.md'), 'v0.1.0-alpha.276.md');
        self::assertStringStartsWith('text/markdown', (string) $md->headers->get('Content-Type'));

        $missing = $this->releases()->show(Request::create('/releases/v9.9.9'), 'v9.9.9');
        self::assertSame(404, $missing->getStatusCode());
    }

    #[Test]
    public function empty_database_renders_an_honest_empty_state(): void
    {
        $empty = new ReleasesController(
            new ContentReader(ContentEntityHarness::entityTypeManager()),
            SpecCorpus::default(),
            new SiteUrl('https://waaseyaa.org'),
        );
        $response = $empty->index(Request::create('/releases'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No releases have been synced yet', (string) $response->getContent());
    }
}
```

(The roadmap/production route assertions fail until Tasks 8 and 9 add those routes; implement the routes for all five paths in this task's provider change, with the controllers arriving in their own tasks. To keep this task self-contained, register all five routes now and let `RoadmapController` / `ProductionController` be created in Tasks 8 and 9 BEFORE this test file is added; alternatively move the `content_routes_are_registered` test into Task 9. Decision: register only the two release routes in this task, and move the combined route assertion test to Task 9. Adjust the test file accordingly: in this task it contains only the four release tests.)

- [ ] **Step 2: Implement the controller**

`src/Controller/ReleasesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\ContentReader;
use App\Docs\MarkdownNegotiation;
use App\Docs\SpecCorpus;
use App\Support\Markdown;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * /releases: the changelog, served from release entities. Same
 * three-rendering contract as the docs pages: HTML, Markdown on the
 * same URL, and the MCP release_list tool reads the same entities.
 */
final class ReleasesController
{
    public function __construct(
        private readonly ContentReader $reader,
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        $releases = $this->reader->releases();

        if (MarkdownNegotiation::wantsMarkdown($request)) {
            $lines = ['# Waaseyaa releases', ''];
            foreach ($releases as $release) {
                $lines[] = sprintf(
                    '- [%s](%s) (%s)%s: %s',
                    $release->get('version'),
                    $this->urls->to('/releases/' . $release->get('version') . '.md'),
                    $release->get('released_at'),
                    ((bool) $release->get('breaking')) ? ' [breaking]' : '',
                    $release->get('summary'),
                );
            }

            return $this->markdownResponse(implode("\n", $lines) . "\n", $this->urls->to('/releases'));
        }

        return $this->render('releases-index.html.twig', [
            'releases' => array_map($this->view(...), $releases),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    public function show(Request $request, string $version): Response
    {
        $explicitMarkdown = str_ends_with($version, '.md');
        if ($explicitMarkdown) {
            $version = substr($version, 0, -3);
        }

        $release = $this->reader->release($version);
        if ($release === null) {
            return new Response('Release not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($explicitMarkdown || MarkdownNegotiation::wantsMarkdown($request)) {
            $markdown = sprintf(
                "# %s\n\nVersion: %s\nReleased: %s\nBreaking changes: %s\n%s\n%s\n\n%s",
                $release->get('title'),
                $release->get('version'),
                $release->get('released_at'),
                ((bool) $release->get('breaking')) ? 'yes' : 'no',
                (string) $release->get('tag_url') !== '' ? 'Tag: ' . $release->get('tag_url') . "\n" : '',
                $release->get('summary'),
                $release->get('body'),
            );

            return $this->markdownResponse($markdown, $this->urls->to('/releases/' . $version));
        }

        return $this->render('release.html.twig', [
            'release' => $this->view($release),
            'revisions' => $this->reader->revisionCount('release', (string) $release->id()),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(EntityInterface $release): array
    {
        return [
            'version' => (string) $release->get('version'),
            'title' => (string) $release->get('title'),
            'released_at' => (string) $release->get('released_at'),
            'summary' => (string) $release->get('summary'),
            'breaking' => (bool) $release->get('breaking'),
            'tag_url' => (string) $release->get('tag_url'),
            'body_html' => Markdown::toHtml((string) $release->get('body')),
        ];
    }

    private function markdownResponse(string $markdown, string $canonical): Response
    {
        return new Response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Link' => sprintf('<%s>; rel="canonical"', $canonical),
            'Vary' => 'Accept',
            'X-Waaseyaa-Framework-Version' => $this->corpus->frameworkVersion() ?? 'unknown',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Template engine unavailable.', 500);
        }

        return new Response($twig->render($template, $context), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'Accept',
        ]);
    }
}
```

- [ ] **Step 3: Write the templates**

`templates/releases-index.html.twig` (follow the prose-page pattern of `docs-index.html.twig`: `{% extends "base.html.twig" %}`, nav block with `aria-current` on a new Releases link, canonical link + TechArticle-style JSON-LD in `{% block head %}`):

```twig
{% extends "base.html.twig" %}

{% block title %}Waaseyaa releases: the changelog, served from entities{% endblock %}
{% block description %}Every tracked waaseyaa/framework release with honest notes. Served from revisionable entities synced from git, also available as Markdown and over MCP.{% endblock %}

{% block nav %}
  <a href="/start">Start</a>
  <a href="/docs">Docs</a>
  <a href="/releases" aria-current="page">Releases</a>
  <a href="/roadmap">Roadmap</a>
  <a href="/production">Production</a>
  <a href="/why">Why</a>
  <a href="/compare">Compare</a>
  <a href="https://github.com/waaseyaa/framework">GitHub</a>
{% endblock %}

{% block head %}
<link rel="canonical" href="{{ canonical_base }}/releases">
{% endblock %}

{% block content %}
<main class="page">
  <h1>Releases</h1>
  <p>Framework {{ framework_version|default('alpha') }}. Each entry below is a revisionable entity synced from a markdown file in git; the same data is available as Markdown on this URL (Accept: text/markdown), per release at its .md URL, over JSON:API at <code>/api/release</code>, and over MCP via <code>release_list</code>.</p>

  {% if releases is empty %}
  <p>No releases have been synced yet. The corpus lives in <code>content/releases/</code> and is synced at deploy by <code>content:sync</code>.</p>
  {% endif %}

  <ul class="spec-list">
    {% for release in releases %}
    <li>
      <a href="/releases/{{ release.version }}">{{ release.version }}</a>
      <span class="spec-name">{{ release.released_at }}{% if release.breaking %} &middot; breaking{% endif %}</span>
      <p>{{ release.summary }}</p>
    </li>
    {% endfor %}
  </ul>
</main>
{% endblock %}
```

`templates/release.html.twig`:

```twig
{% extends "base.html.twig" %}

{% block title %}{{ release.title }}{% endblock %}
{% block description %}{{ release.summary }}{% endblock %}

{% block nav %}
  <a href="/start">Start</a>
  <a href="/docs">Docs</a>
  <a href="/releases" aria-current="page">Releases</a>
  <a href="/roadmap">Roadmap</a>
  <a href="/production">Production</a>
  <a href="/why">Why</a>
  <a href="/compare">Compare</a>
  <a href="https://github.com/waaseyaa/framework">GitHub</a>
{% endblock %}

{% block head %}
<link rel="canonical" href="{{ canonical_base }}/releases/{{ release.version }}">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "TechArticle",
  "headline": {{ release.title|json_encode|raw }},
  "datePublished": "{{ release.released_at }}",
  "url": "{{ canonical_base }}/releases/{{ release.version }}",
  "isPartOf": { "@type": "WebSite", "name": "Waaseyaa", "url": "{{ canonical_base }}/" }
}
</script>
{% endblock %}

{% block content %}
<main class="page page-spec spec-body">
  <p class="provenance">content/releases/{{ release.version }}.md &middot; released {{ release.released_at }}{% if revisions %} &middot; revision {{ revisions }}{% endif %} &middot; <a href="/releases/{{ release.version }}.md">markdown</a></p>
  <h1>{{ release.title }}</h1>
  {% if release.breaking %}<p><strong>Contains breaking changes.</strong></p>{% endif %}
  <p>{{ release.summary }}</p>
  {{ release.body_html|raw }}
  {% if release.tag_url %}<p><a href="{{ release.tag_url }}">Release tag on GitHub</a></p>{% endif %}
</main>
{% endblock %}
```

- [ ] **Step 4: Register the two release routes**

Add to `ContentServiceProvider`:

```php
public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
{
    $reader = new \App\Content\ContentReader($entityTypeManager);
    $corpus = \App\Docs\SpecCorpus::default();
    $urls = \App\Support\SiteUrl::fromEnvironment();

    $releases = new \App\Controller\ReleasesController($reader, $corpus, $urls);

    $router->addRoute(
        'releases.index',
        \Waaseyaa\Routing\RouteBuilder::create('/releases')
            ->controller(fn (\Symfony\Component\HttpFoundation\Request $request) => $releases->index($request))
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    $router->addRoute(
        'releases.show',
        \Waaseyaa\Routing\RouteBuilder::create('/releases/{version}')
            ->controller(fn (\Symfony\Component\HttpFoundation\Request $request, string $version) => $releases->show($request, $version))
            ->allowAll()
            ->methods('GET')
            ->build(),
    );
}
```

(Use `use` imports rather than inline FQCNs to match house style; shown inline here for compactness only.)

- [ ] **Step 5: Run tests, commit**

```bash
./vendor/bin/phpunit
git add src/Controller/ReleasesController.php src/Provider/ContentServiceProvider.php templates/releases-index.html.twig templates/release.html.twig tests/Integration/ContentPagesTest.php
git commit -m "feat(proof-engine): /releases pages (HTML + markdown negotiation) from release entities"
```

---

### Task 8: /roadmap page

**Files:**
- Create: `src/Controller/RoadmapController.php`, `templates/roadmap.html.twig`
- Modify: `src/Provider/ContentServiceProvider.php` (route `roadmap` GET `/roadmap`)
- Test: extend `tests/Integration/ContentPagesTest.php`

**Interfaces:**
- Consumes: `ContentReader::roadmap()`, `MarkdownNegotiation`, `Markdown`, `SpecCorpus`, `SiteUrl`.
- Produces: `App\Controller\RoadmapController::__construct(ContentReader $reader, SpecCorpus $corpus, SiteUrl $urls)`, `page(Request): Response`; route `roadmap`.

- [ ] **Step 1: Add failing tests to ContentPagesTest**

```php
#[Test]
public function roadmap_renders_grouped_horizons(): void
{
    $controller = new \App\Controller\RoadmapController(
        new ContentReader($this->manager), SpecCorpus::default(), new SiteUrl('https://waaseyaa.org'));
    $html = (string) $controller->page(Request::create('/roadmap'))->getContent();

    self::assertStringContainsString('Now', $html);
    self::assertStringContainsString('Curated guides tier on waaseyaa.org', $html);
    self::assertStringContainsString('stage-based', $html);

    $md = $controller->page(Request::create('/roadmap', server: ['HTTP_ACCEPT' => 'text/markdown']));
    self::assertStringStartsWith('text/markdown', (string) $md->headers->get('Content-Type'));
}
```

- [ ] **Step 2: Implement controller and template**

`RoadmapController` follows `ReleasesController` exactly (same constructor, same `markdownResponse`/`render` helpers): `page(Request)` fetches `$grouped = $this->reader->roadmap()`, maps each entity to `['title', 'status_note', 'body_html' => Markdown::toHtml(body), 'specs' => array of trimmed spec names split from related_specs on ',']`, and renders `roadmap.html.twig` (or a markdown listing under `# Waaseyaa roadmap` with `## Now / ## Next / ## Later` sections listing `- title: status_note`).

`templates/roadmap.html.twig` (complete file skeleton, then the content block below):

```twig
{% extends "base.html.twig" %}

{% block title %}Waaseyaa roadmap: stage-based horizons{% endblock %}
{% block description %}The Waaseyaa framework roadmap grouped by stage-based horizons (now, next, later), with honest status notes and links into the specs each item depends on.{% endblock %}

{% block nav %}
  <a href="/start">Start</a>
  <a href="/docs">Docs</a>
  <a href="/releases">Releases</a>
  <a href="/roadmap" aria-current="page">Roadmap</a>
  <a href="/production">Production</a>
  <a href="/why">Why</a>
  <a href="/compare">Compare</a>
  <a href="https://github.com/waaseyaa/framework">GitHub</a>
{% endblock %}

{% block head %}
<link rel="canonical" href="{{ canonical_base }}/roadmap">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "TechArticle",
  "headline": "Waaseyaa roadmap: stage-based horizons",
  "url": "{{ canonical_base }}/roadmap",
  "isPartOf": { "@type": "WebSite", "name": "Waaseyaa", "url": "{{ canonical_base }}/" }
}
</script>
{% endblock %}

{% block content %}
<main class="page">
  <h1>Roadmap</h1>
  <p>Horizons are stage-based, not dated: Waaseyaa is alpha, and honest sequencing beats invented deadlines. Every item links to the specs it depends on. Items are roadmap_item entities synced from git; the same data is served over MCP via <code>roadmap_read</code>.</p>

  {% for horizon, label in {'now': 'Now', 'next': 'Next', 'later': 'Later'} %}
  <h2>{{ label }}</h2>
  {% if roadmap[horizon] is empty %}<p>Nothing in this horizon.</p>{% endif %}
  <ul class="why-list">
    {% for item in roadmap[horizon] %}
    <li>
      <strong>{{ item.title }}.</strong> {{ item.status_note }}.
      {{ item.body_html|raw }}
      {% for spec in item.specs %}<a href="/docs/specs/{{ spec }}">{{ spec }}.md</a>{% if not loop.last %} &middot; {% endif %}{% endfor %}
    </li>
    {% endfor %}
  </ul>
  {% endfor %}
</main>
{% endblock %}
```

- [ ] **Step 3: Register route `roadmap` at `/roadmap` in ContentServiceProvider (same builder pattern), run tests, commit**

```bash
./vendor/bin/phpunit
git add src/Controller/RoadmapController.php templates/roadmap.html.twig src/Provider/ContentServiceProvider.php tests/Integration/ContentPagesTest.php
git commit -m "feat(proof-engine): /roadmap page grouped by stage-based horizons"
```

---

### Task 9: /production pages + telemetry passthrough

**Files:**
- Create: `src/Controller/ProductionController.php`, `templates/production.html.twig`, `templates/case-study.html.twig`
- Modify: `src/Support/PiTelemetry.php` (optional `response_ms` passthrough), `src/Provider/ContentServiceProvider.php` (routes `production.index`, `production.show`), `tests/Unit/PiTelemetryTest.php`
- Test: extend `tests/Integration/ContentPagesTest.php` (including the five-route registration test from Task 7's note)

**Interfaces:**
- Consumes: `ContentReader::caseStudies()/caseStudy()`, `PiTelemetry`, `SpecCorpus`, `SiteUrl`.
- Produces: `App\Controller\ProductionController::__construct(ContentReader $reader, PiTelemetry $telemetry, SpecCorpus $corpus, SiteUrl $urls)`, `index(Request): Response`, `show(Request, string $slug): Response`; `PiTelemetry::read()` return gains optional key `response_ms: ?float`.

- [ ] **Step 1: Extend PiTelemetry (TDD)**

Add to `tests/Unit/PiTelemetryTest.php` (match its existing fixture style):

```php
#[Test]
public function response_ms_passes_through_when_present(): void
{
    $file = tempnam(sys_get_temp_dir(), 'pi');
    file_put_contents($file, json_encode(['uptime_days' => 3, 'temp_c' => 51.2, 'generated_at' => 1000, 'response_ms' => 41.5]));
    $data = new \App\Support\PiTelemetry($file, now: 1100)->read();
    self::assertSame(41.5, $data['response_ms']);
}

#[Test]
public function response_ms_is_null_when_absent(): void
{
    $file = tempnam(sys_get_temp_dir(), 'pi');
    file_put_contents($file, json_encode(['uptime_days' => 3, 'temp_c' => 51.2, 'generated_at' => 1000]));
    self::assertNull(new \App\Support\PiTelemetry($file, now: 1100)->read()['response_ms']);
}
```

Implementation in `PiTelemetry::read()`: before the return, `$responseMs = $data['response_ms'] ?? null;` and add `'response_ms' => is_numeric($responseMs) ? round((float) $responseMs, 1) : null` to the returned array; widen the docblock return shape accordingly. Run the PiTelemetry tests red then green.

- [ ] **Step 2: Implement ProductionController + templates**

`ProductionController` follows the Task 7 controller pattern: `index()` renders `production.html.twig` with `'studies' => array_map(view, caseStudies())`, `'pi' => $this->telemetry->read()`, `'framework_version'`; markdown negotiation returns a `# Waaseyaa in production` listing. `show()` resolves `caseStudy($slug)` (strip `.md` first), 404s in plain text when null, renders `case-study.html.twig` with `body_html`, org, site_url, revision count, or the markdown reconstruction `# {title}\n\n{summary}\n\n{body}`.

Both new templates use the same full-file skeleton as `templates/roadmap.html.twig` (created in Task 8; open it and copy the `extends`/`title`/`description`/`nav`/`head` structure), with `aria-current="page"` on the Production nav link, canonical `{{ canonical_base }}/production` (or `/production/{{ study.slug }}`), and the JSON-LD headline/description adjusted per page. `templates/production.html.twig` content block:

```twig
{% block content %}
<main class="page">
  <h1>In production</h1>
  <p>Where Waaseyaa runs today, stated factually. Each write-up is a case_study entity synced from git.</p>

  {% if pi %}
  <div class="pi-block">
    <p><strong>Live from the Raspberry Pi serving this page:</strong>
      up {{ pi.uptime_days }} days &middot; {{ pi.temp_c }}&deg;C
      {%- if pi.response_ms %} &middot; {{ pi.response_ms }} ms median response{% endif %}
      &middot; framework {{ framework_version }}</p>
  </div>
  {% endif %}

  {% if studies is empty %}
  <p>No case studies have been synced yet. The corpus lives in <code>content/case-studies/</code>.</p>
  {% endif %}

  <ul class="spec-list">
    {% for study in studies %}
    <li>
      <a href="/production/{{ study.slug }}">{{ study.title }}</a>
      <span class="spec-name">{{ study.org }}</span>
      <p>{{ study.summary }}</p>
    </li>
    {% endfor %}
  </ul>
</main>
{% endblock %}
```

Add a small `.pi-block` rule to `public/css/site.css` (bordered card using existing tokens: `border: 1.5px solid var(--card-line); border-radius: 14px; padding: 14px 18px; background: var(--wash); font-size: 13.5px;`). `case-study.html.twig` mirrors `release.html.twig` (provenance line with `content/case-studies/{{ study.slug }}.md`, title, summary, body_html, link out to `site_url`).

- [ ] **Step 3: Register `production.index` (`/production`) and `production.show` (`/production/{slug}`) routes; move in the five-route registration test**

Add the `content_routes_are_registered` test (from Task 7's note) asserting all five route names now match.

- [ ] **Step 4: Tests + commit**

```bash
./vendor/bin/phpunit
git add src/Controller/ProductionController.php templates/production.html.twig templates/case-study.html.twig src/Support/PiTelemetry.php src/Provider/ContentServiceProvider.php public/css/site.css tests
git commit -m "feat(proof-engine): /production case studies with live Pi telemetry block"
```

---

### Task 10: Sitemap, llms.txt, server card, and nav coherence

**Files:**
- Modify: `src/Controller/SitemapController.php`, `src/Controller/LlmsTxtController.php`, `src/Mcp/PublicServerCard.php`, `src/Provider/DocsServiceProvider.php`, `templates/base.html.twig`, and the `{% block nav %}` overrides in `templates/{start,why,compare,docs-index,docs-spec}.html.twig`
- Test: extend `tests/Integration/LlmsTxtTest.php` and the sitemap assertions in `tests/Integration/PagesAndSchemaTest.php` (locate the existing sitemap test with `grep -rn "sitemap" tests/`)

**Interfaces:**
- Consumes: `ContentReader`.
- Produces: `SitemapController::__construct(SpecCorpus $corpus, SiteUrl $urls, ?ContentReader $content = null)`; `LlmsTxtController::__construct(SpecCorpus $corpus, SiteUrl $urls, ?ContentReader $content = null)`.

- [ ] **Step 1: Failing tests first**

Extend the llms.txt test: response must contain `## Releases`, `/releases/v0.1.0-alpha.276.md`, `## Roadmap`, `## Production`. Extend the sitemap test: urlset must contain `/releases`, `/releases/v0.1.0-alpha.276`, `/roadmap`, `/production`, `/production/fnpi`. Construct both controllers in tests with `new ContentReader($manager)` where `$manager` is a harness manager with the repo corpus synced (same `setUp` pattern as `ContentPagesTest`).

- [ ] **Step 2: Implement**

- `SitemapController::serve()`: after the spec loop, when `$this->content !== null`: append `/releases`, `/roadmap`, `/production`, then `'/releases/' . $release->get('version')` for each of `$this->content->releases()`, then `'/production/' . $study->get('slug')` for each case study.
- `LlmsTxtController::serve()`: after the Specs section append:

```php
$lines[] = '';
$lines[] = '## Releases';
$lines[] = '';
$lines[] = sprintf('- [Releases index](%s): every tracked framework release, negotiable as Markdown', $this->urls->to('/releases'));
foreach ($this->content?->releases() ?? [] as $release) {
    $lines[] = sprintf('- [%s](%s): %s', $release->get('version'), $this->urls->to('/releases/' . $release->get('version') . '.md'), $release->get('summary'));
}
$lines[] = '';
$lines[] = '## Roadmap';
$lines[] = '';
$lines[] = sprintf('- [Roadmap](%s): stage-based horizons, negotiable as Markdown', $this->urls->to('/roadmap'));
$lines[] = '';
$lines[] = '## Production';
$lines[] = '';
foreach ($this->content?->caseStudies() ?? [] as $study) {
    $lines[] = sprintf('- [%s](%s): %s', $study->get('title'), $this->urls->to('/production/' . $study->get('slug')), $study->get('summary'));
}
```

- `DocsServiceProvider::routes()`: construct `$content = new \App\Content\ContentReader($entityTypeManager);` and pass it to both controllers. Update the MCP tools description sentence in `PublicServerCard` to `'... Tools: spec_list, spec_search, spec_read, release_list, roadmap_read. No authentication required.'` (the tools themselves arrive in Task 11; the card text and Task 11 land before any deploy).
- Nav: update `base.html.twig` default nav block and every per-template override to the ordered list: Start, Docs, Releases, Roadmap, Production, Why, Compare, GitHub (drop Packagist from the nav; the footer keeps it). Keep each page's `aria-current` on its own link.

- [ ] **Step 3: Tests + commit**

```bash
./vendor/bin/phpunit
git add src/Controller/SitemapController.php src/Controller/LlmsTxtController.php src/Mcp/PublicServerCard.php src/Provider/DocsServiceProvider.php templates tests
git commit -m "feat(proof-engine): sitemap, llms.txt, server card, and nav cover the content surfaces"
```

---

### Task 11: MCP tools release_list and roadmap_read

**Files:**
- Create: `src/Mcp/Tool/ReleaseListTool.php`, `src/Mcp/Tool/RoadmapReadTool.php`
- Modify: `src/Mcp/SpecToolRegistry.php` (`toolClasses()`), `src/Provider/DocsServiceProvider.php` (registry construction)
- Test: `tests/Integration/ContentMcpToolsTest.php`; update the tool-name assertion in `tests/Integration/McpEndpointTest.php`

**Interfaces:**
- Consumes: `ContentReader`, `SpecCorpus`, `SiteUrl`, the `#[AsAgentTool]`/`AbstractAgentTool` pattern from `SpecListTool`.
- Produces: MCP tools named `release_list` and `roadmap_read`, capability `SpecReaderAccount::CAPABILITY`, non-destructive, empty-object input schemas.

- [ ] **Step 1: Failing test**

`tests/Integration/ContentMcpToolsTest.php` (mirror `McpEndpointTest`'s rpc helper, but build the registry with all five tools; harness manager with repo corpus synced as in `ContentPagesTest::setUp`):

```php
#[Test]
public function release_list_returns_releases_with_urls(): void
{
    $result = $this->rpc('tools/call', ['name' => 'release_list', 'arguments' => new \stdClass()]);
    $payload = json_decode($result['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);

    self::assertGreaterThanOrEqual(1, $payload['count']);
    self::assertSame('v0.1.0-alpha.276', $payload['releases'][0]['version']);
    self::assertSame('https://waaseyaa.org/releases/v0.1.0-alpha.276', $payload['releases'][0]['canonical_url']);
    self::assertSame('https://waaseyaa.org/releases/v0.1.0-alpha.276.md', $payload['releases'][0]['markdown_url']);
}

#[Test]
public function roadmap_read_returns_grouped_horizons(): void
{
    $result = $this->rpc('tools/call', ['name' => 'roadmap_read', 'arguments' => new \stdClass()]);
    $payload = json_decode($result['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);

    self::assertArrayHasKey('now', $payload['horizons']);
    $titles = array_column($payload['horizons']['now'], 'title');
    self::assertContains('Curated guides tier on waaseyaa.org', $titles);
}
```

Also update `McpEndpointTest::tools_list_exposes_exactly_the_three_read_only_spec_tools`: the expected sorted name list becomes `['release_list', 'roadmap_read', 'spec_list', 'spec_read', 'spec_search']` (rename the test method to match, e.g. `tools_list_exposes_exactly_the_five_read_only_tools`), and its registry construction gains the two new tools with a `new ContentReader(null)` reader (empty content is fine for the list assertion).

- [ ] **Step 2: Implement the tools**

`ReleaseListTool` copies `SpecListTool`'s structure exactly (attribute, `requireCapability` guard, `dryRun` delegating to `execute`, empty-object `inputSchema`), with:

```php
#[AsAgentTool(name: 'release_list', capability: SpecReaderAccount::CAPABILITY, destructive: false, dryRunSupported: true, category: 'releases')]
```

constructor `(ContentReader $reader, SpecCorpus $corpus, SiteUrl $urls)`, and payload:

```php
$releases = [];
foreach ($this->reader->releases() as $release) {
    $releases[] = [
        'version' => (string) $release->get('version'),
        'released_at' => (string) $release->get('released_at'),
        'summary' => (string) $release->get('summary'),
        'breaking' => (bool) $release->get('breaking'),
        'canonical_url' => $this->urls->to('/releases/' . $release->get('version')),
        'markdown_url' => $this->urls->to('/releases/' . $release->get('version') . '.md'),
    ];
}
$payload = [
    'framework_version' => $this->corpus->frameworkVersion(),
    'count' => count($releases),
    'releases' => $releases,
];
```

description: `'List every tracked waaseyaa/framework release with date, summary, breaking flag, and canonical URLs (HTML and Markdown).'`

`RoadmapReadTool` likewise (`name: 'roadmap_read'`, `category: 'roadmap'`), payload:

```php
$horizons = [];
foreach ($this->reader->roadmap() as $horizon => $items) {
    $horizons[$horizon] = array_map(fn ($item) => [
        'title' => (string) $item->get('title'),
        'status_note' => (string) $item->get('status_note'),
        'related_specs' => array_values(array_filter(array_map('trim', explode(',', (string) $item->get('related_specs'))))),
    ], $items);
}
$payload = ['canonical_url' => $this->urls->to('/roadmap'), 'horizons' => $horizons];
```

description: `'Read the Waaseyaa roadmap grouped by stage-based horizon (now, next, later) with per-item status notes and related specs.'`

- [ ] **Step 3: Wire the registry**

`SpecToolRegistry::toolClasses()` returns the five classes. In `DocsServiceProvider::routes()`, the registry construction becomes:

```php
$content = new \App\Content\ContentReader($entityTypeManager); // already created in Task 10
$registry = new SpecToolRegistry([
    new SpecListTool($corpus, $urls),
    new SpecSearchTool($corpus, $search, $urls),
    new SpecReadTool($corpus, $urls),
    new \App\Mcp\Tool\ReleaseListTool($content, $corpus, $urls),
    new \App\Mcp\Tool\RoadmapReadTool($content, $urls),
]);
```

(Note `RoadmapReadTool` needs no `SpecCorpus`; constructor is `(ContentReader $reader, SiteUrl $urls)`.)

- [ ] **Step 4: Tests + commit**

```bash
./vendor/bin/phpunit
git add src/Mcp tests/Integration/ContentMcpToolsTest.php tests/Integration/McpEndpointTest.php src/Provider/DocsServiceProvider.php
git commit -m "feat(proof-engine): release_list and roadmap_read MCP tools on the public endpoint"
```

---

### Task 12: JSON:API exposure, allowlist, and access posture tests

> Superseded by the addendum at the top of this document
> (waaseyaa/framework#2159): the allowlist this task added was withdrawn
> before merge, and `ApiExposureTest.php` was reworked into
> `tests/Integration/ApiAbsenceTest.php`, an absence proof. Kept below as
> executed history, not current state.

**Files:**
- Modify: `config/waaseyaa.php` (add `api.entity_type_allowlist`)
- Test: `tests/Integration/ApiExposureTest.php`

**Interfaces:**
- Consumes: harness, `Waaseyaa\Api\JsonApiRouteProvider`, `Waaseyaa\Api\EntityTypeApiExposurePolicy`, `Waaseyaa\Access\Policy\PublishedContentAccessPolicy`.
- Produces: a closed-world API allowlist and executable proof of the anonymous read/write posture. (Withdrawn before merge; see addendum.)

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Release;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        self::assertNotNull($policy); // fromConfig throws on any invalid id; reaching here is the assertion
    }

    #[Test]
    public function read_routes_are_public_and_write_routes_require_authentication(): void
    {
        $manager = ContentEntityHarness::entityTypeManager();

        $context = new \Symfony\Component\Routing\RequestContext();
        $context->setMethod('GET');
        $getRouter = new WaaseyaaRouter($context);
        new JsonApiRouteProvider($manager)->registerRoutes($getRouter);
        self::assertSame('api.release.index', $getRouter->match('/api/release')['_route']);
        self::assertSame('api.release.show', $getRouter->match('/api/release/1')['_route']);

        $post = new \Symfony\Component\Routing\RequestContext();
        $post->setMethod('POST');
        $postRouter = new WaaseyaaRouter($post);
        new JsonApiRouteProvider($manager)->registerRoutes($postRouter);
        self::assertSame('api.release.store', $postRouter->match('/api/release')['_route']);
    }

    #[Test]
    public function anonymous_view_is_allowed_only_for_published_entities(): void
    {
        $manager = ContentEntityHarness::entityTypeManager();
        $policy = new PublishedContentAccessPolicy($manager);
        $anonymous = new \Waaseyaa\Access\AuthorizationPrincipal(0, false, [], [], 'anonymous');

        $published = new Release(['title' => 'x', 'status' => true]);
        $unpublished = new Release(['title' => 'x', 'status' => false]);

        self::assertTrue($policy->access($published, 'view', $anonymous)->isAllowed());
        self::assertFalse($policy->access($unpublished, 'view', $anonymous)->isAllowed());
        self::assertFalse($policy->createAccess('release', 'release', $anonymous)->isAllowed(), 'anonymous must never gain create');
    }
}
```

Adjustment notes for the executor: (a) `AuthorizationPrincipal`'s exact namespace/constructor is in `vendor/waaseyaa/access/src/` (grep for `class AuthorizationPrincipal`); match its real signature. (b) `PublishedContentAccessPolicy::appliesTo` needs the type registered in the manager it receives; if `access()` internally consults `appliesTo` semantics differently, follow `vendor/waaseyaa/access/src/Policy/PublishedContentAccessPolicy.php`. (c) The write-route assertion documents the framework's own posture: `store` carries `requireAuthentication()`, so anonymous POSTs are rejected with 401 by `AccessChecker` before any policy runs (`access-control.md:462`).

- [ ] **Step 2: Add the allowlist to config/waaseyaa.php**

After the `'cors_origins'` entry:

```php
// JSON:API closed world: ONLY these entity types are exposed under /api,
// read-only in practice because the site has no accounts (write routes
// 401 for anonymous). Any other api-capable type stays dark.
'api' => [
    'entity_type_allowlist' => ['release', 'roadmap_item', 'case_study'],
],
```

- [ ] **Step 3: Tests + commit**

```bash
./vendor/bin/phpunit
git add config/waaseyaa.php tests/Integration/ApiExposureTest.php
git commit -m "feat(proof-engine): JSON:API allowlist + executable anonymous read/write posture proof"
```

---

### Task 13: Home page becomes the demo

**Files:**
- Modify: `templates/home.html.twig`, `src/Controller/HomeController.php`, `src/Docs/SpecCorpus.php` (add `sourceReleasedAt(): ?string`), `public/css/site.css`, `templates/base.html.twig` (footer only if needed)
- Test: extend `tests/Integration/HomepageTest.php`

**Interfaces:**
- Consumes: `SpecCorpus`, existing home rendering.
- Produces: `SpecCorpus::sourceReleasedAt(): ?string` (the manifest's `source_released_at`, date part).

- [ ] **Step 1: Failing tests in HomepageTest**

```php
#[Test]
public function hero_carries_the_release_date_and_links_to_releases(): void
{
    $html = /* render home via the test's existing pattern */;
    self::assertStringContainsString('released 2026-07-27', $html);
    self::assertStringContainsString('href="/releases"', $html);
}

#[Test]
public function the_demo_section_shows_tested_api_and_mcp_commands(): void
{
    $html = /* same */;
    self::assertStringContainsString('This site is the demo', $html);
    self::assertStringContainsString('curl -s https://waaseyaa.org/api/release', $html);
    self::assertStringContainsString('"name":"release_list"', $html);
}
```

(Open `tests/Integration/HomepageTest.php` first and reuse exactly how it renders the homepage; these assertions slot into that harness. The `/api/release` path is proven real by Task 12's route test and the `release_list` tool name by Task 11's tests, which is what makes these commands unable to rot.)

- [ ] **Step 2: Implement**

- `SpecCorpus::sourceReleasedAt()`: return `is_string($this->manifest()['source_released_at'] ?? null) ? substr($this->manifest()['source_released_at'], 0, 10) : null;` (extract the manifest value once into a variable to keep phpstan happy).
- `HomeController::index()`: add `'release_date' => SpecCorpus::default()->sourceReleasedAt()` to the render context (construct `SpecCorpus::default()` once in the method; the controller has no corpus dependency today and a constructor default keeps tests unchanged: add `private readonly ?SpecCorpus $corpus = null` and use `$this->corpus ?? SpecCorpus::default()`).
- `templates/home.html.twig` hero stage line becomes:

```twig
<p class="stage">{{ framework_version|default('alpha') }} &middot; alpha{% if release_date %} &middot; released {{ release_date }}{% endif %} &middot; <a href="/releases">what changed</a> &middot; in production at First Nations Procurement Inc.</p>
```

- New section between `.proof` and `.surface`:

```twig
<section class="demo">
  <h2>This site is the demo</h2>
  <p>waaseyaa.org is a Waaseyaa application. The releases, roadmap, and case studies are revisionable entities synced from markdown in git at deploy time; the framework serves them over JSON:API and MCP with no extra code. Both commands below are covered by this site's test suite.</p>
  <div class="cmd">$ curl -s https://waaseyaa.org/api/release</div>
  <div class="cmd">$ curl -s -X POST https://waaseyaa.org/mcp -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"release_list","arguments":{}}}'</div>
</section>
```

- CSS: `.demo { max-width: 820px; margin: 6px auto 26px; padding: 0 8%; text-align: center; } .demo h2 { font-size: 18px; margin-bottom: 8px; } .demo p { font-size: 13.5px; color: var(--muted); line-height: 1.6; margin-bottom: 12px; } .demo .cmd { text-align: left; margin-bottom: 8px; }`
- Update home nav (it uses the base default) via Task 10's base.html.twig change (already done); update the proof chips: replace `<div>CI-gated conventions</div>` with `<div>Real entities under this site</div>` and keep the other four.
- Home template's own nav comes from base; verify with the homepage test.

- [ ] **Step 3: Tests + commit**

```bash
./vendor/bin/phpunit
git add templates/home.html.twig src/Controller/HomeController.php src/Docs/SpecCorpus.php public/css/site.css tests/Integration/HomepageTest.php
git commit -m "feat(proof-engine): home page proves the pipeline (release line + tested curl demos)"
```

---

### Task 14: Honesty enforcement, docs, and final gates

**Files:**
- Modify: `tests/Unit/ContentHonestyTest.php` (include `content/**/*.md`), `CLAUDE.md`
- Create: `tests/Unit/ReleaseHonestyTest.php`

**Interfaces:**
- Consumes: everything.
- Produces: honesty invariants over the content corpus; updated agent documentation; green CI gates.

- [ ] **Step 1: Extend ContentHonestyTest**

In `authoredFiles()`, add to the `array_merge`: `glob($root . '/content/*/*.md') ?: []`. Run the suite; fix any em dash or banned phrase the new corpus files carry (there should be none if Task 5 was written as specified).

- [ ] **Step 2: Version-honesty test**

`tests/Unit/ReleaseHonestyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Content\FrontMatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The site must not claim a release it is not running: the newest file
 * in content/releases/ must be the exact framework version locked in
 * the corpus manifest. Adding a newer release note requires bumping the
 * framework first.
 */
final class ReleaseHonestyTest extends TestCase
{
    #[Test]
    public function newest_release_note_matches_the_locked_framework_version(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/resources/specs/manifest.json'), true);
        $locked = $manifest['framework_version'] ?? null;
        self::assertIsString($locked);

        $newest = null;
        $newestDate = '';
        foreach (glob($root . '/content/releases/*.md') ?: [] as $file) {
            $meta = FrontMatter::parse((string) file_get_contents($file))['meta'];
            if ((string) $meta['released_at'] >= $newestDate) {
                $newestDate = (string) $meta['released_at'];
                $newest = (string) $meta['version'];
            }
        }

        self::assertNotNull($newest, 'content/releases/ must contain at least one release note');
        self::assertSame($locked, $newest);
    }
}
```

- [ ] **Step 3: Update CLAUDE.md**

Replace the "This app has NO entities" section with:

```markdown
## Entities: git-sourced, read-only at runtime

This app registers three entity types (`release`, `roadmap_item`,
`case_study` in `src/Entity/`), all revisionable, `group: 'content'`,
`api: true`. They are the proof engine: real entities the site dogfoods.
The ONLY writer is `content:sync` (a one-shot deploy step); there is no
admin surface, no accounts, and no runtime write path. Publishing is a
git push: author frontmatter markdown under `content/{releases,roadmap,
case-studies}/`, and the sync creates entities, saves a new revision on
change, and unpublishes (status=false) when a file is deleted.
`config/waaseyaa.php` closes the JSON:API world to exactly these three
types (`api.entity_type_allowlist`). Chat transcripts remain plain
tables via `DatabaseInterface` (`src/Chat/ChatSchema.php`).
```

Also: add `src/Content/`, `src/Cli/`, `src/Entity/` and the three controllers to the architecture tree; add `/releases`, `/releases/{version}`, `/roadmap`, `/production`, `/production/{slug}` to the routes table (provider `ContentServiceProvider`); add `php bin/scaffold-release.php vX.Y.Z-alpha.N` and `vendor/bin/waaseyaa content:sync` to the Development section; document in the Deploy section that `waaseyaa-infra`'s deploy workflow must run the one-shot `content:sync` right after `db:init` with the same `APP_ENV=local` pattern (a follow-up change in the waaseyaa-infra repo, required before the next deploy); note the release-note-per-framework-bump step next to the existing "rerun bin/sync-specs.php" instruction.

- [ ] **Step 4: Full gates**

```bash
./vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix
git add -A && git status
```

Expected: all three green; cs-fixer may reformat, re-run phpunit after it. Fix any phpstan findings (likely: docblock array shapes on ContentReader/ContentSync).

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "feat(proof-engine): honesty gates over the content corpus + agent docs"
```

---

## Post-plan follow-ups (NOT in this plan's scope)

- `waaseyaa-infra`: add the `content:sync` one-shot to `deploy-waaseyaa-org.yml` (documented in CLAUDE.md by Task 14; must land before the next deploy).
- Pi-side telemetry cron writing the status JSON (`WAASEYAA_ORG_PI_STATUS_FILE`), including optional `response_ms`.
- Mission 2 (guides tier) and Mission 3 (long-form case studies) per the spec.
