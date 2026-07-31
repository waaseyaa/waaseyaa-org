<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Access\ContentWriteProtectionPolicy;
use App\Content\ContentSync;
use App\Content\ContentSyncReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The production-shaped companion to ContentWriteProtectionTest.
 *
 * ContentWriteProtectionTest proves policy LOGIC against a hand-built
 * EntityAccessHandler. This class proves the same denial survives the parts a
 * hand-built handler cannot speak for: real kernel boot, real attribute
 * discovery, the real admin-surface route the framework auto-registers for
 * every content type, and the real controller-to-Response conversion. Every
 * moving part below is resolved from one booted ConsoleKernel:
 *
 *   1. boot()                       real ConsoleKernel::bootForCli() over a temp SQLite file
 *   2. discovery                    ContentWriteProtectionPolicy is IN $kernel->getAccessHandler()
 *   3. same-instance proof          the admin-surface host closes over that exact handler object
 *   4. reachable write path         the real `admin_surface.action` route closure, dispatched
 *                                   through the real ControllerDispatcher
 *   5. denial                       create / update / delete are refused with a 403 envelope
 *                                   for an account holding `administer content`
 *   6. no side effect               the corpus is byte-for-byte unchanged after the attempts
 *   7. content:sync still works     create, revise, unpublish and replay through the SAME
 *                                   booted kernel's EntityTypeManager
 *
 * Which layer is proved, and why this one: the task's target was a literal
 * HTTP 403 from the reachable authenticated write path. That path is the
 * admin-surface entity CRUD endpoint (JSON:API write routes do not exist for
 * these types, see ApiAbsenceTest). This test reaches it in full: it takes the
 * `admin_surface.action` Route registered by the REAL
 * AdminSurfaceServiceProvider instance the kernel booted, pulls the controller
 * closure off it, and dispatches through the REAL
 * Waaseyaa\Foundation\Http\ControllerDispatcher that HttpKernel::dispatch()
 * uses. Nothing is reconstructed by hand. The only production step not
 * reproduced is the middleware stack that populates `_account` /
 * `_authorization_principal` on the Request (HttpKernel::handle() reads
 * superglobals and cannot be handed a Request), so those two attributes are
 * set directly, exactly as the auth middleware stack (FieldReadContextMiddleware derives the principal) would.
 * That substitution can only make the test STRICTER: it hands the write path a
 * fully authenticated `administer content` principal, which is precisely the
 * account ContentWriteProtectionPolicy exists to stop.
 *
 * On the literal status code: the admin surface transports its denial as an
 * envelope, not as an HTTP status. The route controller returns
 * AdminSurfaceResultData::toArray() (vendor/waaseyaa/admin-surface/src/Host/AbstractAdminSurfaceHost.php:143),
 * which has an `error.status` key but no `statusCode` key, and
 * ControllerDispatcher::handleCallable() maps an array result with
 * `$result['statusCode'] ?? 200`
 * (vendor/waaseyaa/foundation/src/Http/ControllerDispatcher.php:141). So the
 * wire response is HTTP 200 carrying `{"ok":false,"error":{"status":403,...}}`.
 * The write is refused either way, which is the security property; the
 * transport shape is pinned separately in
 * denial_is_transported_as_an_http_200_with_a_403_envelope() so that a future
 * framework release promoting the envelope status to the HTTP status is caught
 * as a deliberate change rather than silently absorbed.
 *
 * Isolation is mandatory, not defensive. Booting the kernel installs
 * process-global state that outlives this class: SsrServiceProvider::boot()
 * parks the Twig environment in a static and adds FlashTwigExtension to it, so
 * the next test in the same process that boots an SsrServiceProvider dies with
 * "Unable to register extension FlashTwigExtension as it is already
 * registered" (observed in LlmsTxtTest and PagesAndSchemaTest before this class
 * was isolated); EntityReadRuntime::installGuard() is similarly process-wide.
 * Running the class in its own process with global state discarded is the fix.
 * Note that PHPUnit recovers an isolated child's result by parsing the child's
 * stdout, so anything the kernel writes there corrupts the run; LOG_LEVEL is
 * pinned before boot for that reason.
 *
 * Cost: the kernel is booted once per test method under isolation (roughly
 * 0.7s each), which is why the write-attempt / no-side-effect / content:sync
 * concerns are grouped into as few methods as their assertions allow.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class KernelWriteProtectionTest extends TestCase
{
    /** @var list<string> */
    private const array PROTECTED_TYPES = ['release', 'roadmap_item', 'case_study'];

    private static ?ConsoleKernel $kernel = null;

    private static string $databasePath = '';

    private static string $contentRoot = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$kernel !== null) {
            // Same finally-shaped release ContentSyncHandler/DbInitHandler use:
            // never leave a one-shot kernel holding a lock on the SQLite file.
            $database = self::$kernel->getDatabase();
            if ($database instanceof DBALDatabase) {
                $database->getConnection()->close();
            }
            self::$kernel = null;
        }

        if (self::$databasePath !== '') {
            // Guarded: an unguarded glob() on an empty prefix would expand to
            // the whole working directory.
            foreach (glob(self::$databasePath . '*') ?: [] as $file) {
                @unlink($file);
            }
        }
        self::removeDirectory(self::$contentRoot);
        self::$databasePath = '';
        self::$contentRoot = '';
    }

    /**
     * Boot the real application kernel (once per isolated process) and seed a
     * baseline corpus through content:sync, so every write attempt below has a
     * real, stored entity to aim at.
     *
     * APP_ENV=local is required: the production boot gates refuse without an
     * application secret and an auth-token secret, and `local` is also what
     * makes AbstractKernel::compileManifest() fresh-compile the package
     * manifest instead of reading storage/framework/packages.php. That matters
     * here, see the discovery test below.
     */
    private function kernel(): ConsoleKernel
    {
        if (self::$kernel instanceof ConsoleKernel) {
            return self::$kernel;
        }

        $projectRoot = dirname(__DIR__, 2);

        self::$databasePath = sys_get_temp_dir() . '/kernel-write-protection-' . bin2hex(random_bytes(6)) . '.sqlite';
        touch(self::$databasePath);

        // EnvLoader only populates getenv(); $_ENV is never read by the kernel.
        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . self::$databasePath);
        // Boot logs a warning about the absent LLM provider, which is
        // irrelevant here and, under process isolation, lands on the stdout
        // PHPUnit parses to recover this child process's result.
        putenv('LOG_LEVEL=error');

        $kernel = new ConsoleKernel($projectRoot);
        $kernel->bootForCli();
        self::$kernel = $kernel;

        self::$contentRoot = sys_get_temp_dir() . '/kernel-write-protection-content-' . bin2hex(random_bytes(6));
        foreach (['releases', 'roadmap', 'case-studies'] as $directory) {
            mkdir(self::$contentRoot . '/' . $directory, recursive: true);
        }
        self::writeRelease('v0.1.0-alpha.900', 'Baseline release.');
        self::writeRoadmapItem('baseline', 'Baseline roadmap item.');
        self::writeCaseStudy('baseline', 'Baseline case study.');
        $this->sync();

        return $kernel;
    }

    /**
     * Every declared field on Release/RoadmapItem/CaseStudy is non-nullable, so
     * the kernel's validator treats all of them as required (NotBlank/NotNull
     * via FieldDefinitionConstraintBuilder). Fixtures therefore fill the keys
     * ContentSync calls "optional" too; a file omitting them fails validation
     * on save under the real kernel.
     */
    private static function writeRelease(string $version, string $summary): void
    {
        file_put_contents(
            self::$contentRoot . '/releases/' . $version . '.md',
            "---\ntitle: Framework {$version}\nversion: {$version}\nreleased_at: \"2026-07-27\"\n"
            . "summary: {$summary}\nbreaking: false\ntag_url: https://example.test/{$version}\n---\n\nHighlights.\n",
        );
    }

    private static function writeRoadmapItem(string $slug, string $note): void
    {
        file_put_contents(
            self::$contentRoot . '/roadmap/' . $slug . '.md',
            "---\ntitle: Roadmap {$slug}\nhorizon: now\nstatus_note: {$note}\n"
            . "related_specs: entity-system\nweight: 0\n---\n\nDetail.\n",
        );
    }

    private static function writeCaseStudy(string $slug, string $summary): void
    {
        file_put_contents(
            self::$contentRoot . '/case-studies/' . $slug . '.md',
            "---\ntitle: Case {$slug}\norg: Example Org\nsummary: {$summary}\n"
            . "site_url: https://example.test/{$slug}\n---\n\nDetail.\n",
        );
    }

    /** Guarded against an empty path for the same reason the glob() above is. */
    private static function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeDirectory($entry) : @unlink($entry);
        }
        @rmdir($directory);
    }

    private function sync(): ContentSyncReport
    {
        return new ContentSync($this->kernel()->getEntityTypeManager(), self::$contentRoot)->sync();
    }

    /** The account the policy exists to stop: authenticated, holding `administer content`. */
    private function contentAdmin(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(1, true, [], [ContentAdminAccessPolicy::ADMIN_PERMISSION], 'admin');
    }

    /**
     * The real `admin_surface.action` controller: the closure the REAL booted
     * AdminSurfaceServiceProvider instance registers on the router. Not a
     * reconstruction; the provider is pulled out of $kernel->getProviders(),
     * so its kernel-services bus (and therefore the access handler it hands
     * the host) is the one the kernel wired at boot.
     */
    private function adminSurfaceActionController(): \Closure
    {
        $provider = null;
        foreach ($this->kernel()->getProviders() as $candidate) {
            if ($candidate instanceof AdminSurfaceServiceProvider) {
                $provider = $candidate;
                break;
            }
        }

        self::assertInstanceOf(
            AdminSurfaceServiceProvider::class,
            $provider,
            'the booted kernel must register AdminSurfaceServiceProvider; without it there is no reachable admin write path to prove anything about.',
        );

        $router = new WaaseyaaRouter(new RequestContext('', 'POST'));
        $provider->routes($router, $this->kernel()->getEntityTypeManager());

        $route = $router->getRouteCollection()->get('admin_surface.action');
        self::assertNotNull($route, 'admin-surface must register the `admin_surface.action` write route.');

        $controller = $route->getDefault('_controller');
        self::assertInstanceOf(\Closure::class, $controller, 'the admin_surface.action controller must be the provider closure.');

        return $controller;
    }

    /**
     * Dispatch one admin-surface write through the real controller closure and
     * the real ControllerDispatcher, as an authenticated `administer content`
     * holder.
     *
     * `_account` / `_authorization_principal` are the two attributes
     * the auth middleware stack (FieldReadContextMiddleware) sets on a real request; the
     * admin-surface host reads exactly these via
     * DecisionAccountResolver::resolve() in
     * GenericAdminSurfaceHost::resolveSession().
     *
     * @param array<string, mixed> $payload
     */
    private function dispatchWrite(string $type, string $action, array $payload): Response
    {
        $principal = $this->contentAdmin();
        $request = Request::create(
            '/admin/_surface/' . $type . '/action/' . $action,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_controller', $this->adminSurfaceActionController());
        $request->attributes->set('_route', 'admin_surface.action');
        // Order matters: ControllerDispatcher::handleCallable() spreads the
        // non-underscore attributes positionally into fn($request, $type, $action).
        $request->attributes->set('type', $type);
        $request->attributes->set('action', $action);
        $request->attributes->set('_account', $principal);
        $request->attributes->set('_authorization_principal', $principal);

        return new ControllerDispatcher([])->dispatch($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = (string) $response->getContent();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'admin surface responses must be JSON objects.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Assert one admin-surface write was refused with a 403, naming the entity
     * type and operation in every failure message.
     */
    private function assertWriteRefused(string $type, string $action, Response $response): void
    {
        $envelope = $this->decode($response);
        $context = sprintf('%s: admin-surface %s', $type, $action);

        self::assertFalse(
            $envelope['ok'] ?? null,
            $context . ' must be refused (envelope ok must be false) for an "administer content" holder.',
        );

        $error = $envelope['error'] ?? null;
        self::assertIsArray($error, $context . ' must carry an error envelope naming the refusal.');
        self::assertSame(
            403,
            $error['status'] ?? null,
            $context . ' must be refused with status 403 for an "administer content" holder.',
        );
    }

    /**
     * A slug-keyed snapshot of the whole protected corpus: title, published
     * status, and revision count for every entity of every protected type.
     *
     * @return array<string, array{title: string, status: int, revisions: int}>
     */
    private function corpusSnapshot(): array
    {
        $manager = $this->kernel()->getEntityTypeManager();
        $snapshot = [];
        foreach (self::PROTECTED_TYPES as $type) {
            $repository = $manager->getRepository($type);
            foreach ($repository->findBy([]) as $entity) {
                $snapshot[$type . '/' . (string) $entity->get('slug')] = [
                    'title' => (string) $entity->get('title'),
                    'status' => EntityValues::statusToInt($entity->get('status')),
                    'revisions' => count($repository->listRevisions((string) $entity->id())),
                ];
            }
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedTypes(): array
    {
        return [
            'release' => ['release'],
            'roadmap_item' => ['roadmap_item'],
            'case_study' => ['case_study'],
        ];
    }

    private function existingIdFor(string $type): string
    {
        $entities = $this->kernel()->getEntityTypeManager()->getRepository($type)->findBy([]);
        self::assertNotEmpty($entities, sprintf('%s: the fixture corpus must contain an entity to attempt a write against.', $type));
        $uuid = $entities[0]->get('uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : (string) $entities[0]->id();
    }

    /**
     * Create payloads are complete and well-formed for each type: every
     * declared field is present with a value of the right shape, so the
     * refusal below cannot be mistaken for input validation. An incomplete
     * payload is rejected 422 by the admin surface BEFORE the access check
     * (GenericAdminSurfaceHost::handleCreate() validates attributes first),
     * which would prove nothing about the policy.
     *
     * @return array<string, mixed>
     */
    private function createAttributes(string $type): array
    {
        return match ($type) {
            'release' => [
                'title' => 'Runtime injected release',
                'slug' => 'runtime-injected-release',
                'version' => 'v0.0.0-runtime',
                'released_at' => '2026-07-31',
                'summary' => 'Injected at runtime through the admin surface.',
                'body' => 'Injected body.',
                'breaking' => false,
                'tag_url' => 'https://example.test/runtime',
                'status' => true,
                'source_sha1' => 'runtime',
            ],
            'roadmap_item' => [
                'title' => 'Runtime injected roadmap item',
                'slug' => 'runtime-injected-roadmap-item',
                'horizon' => 'now',
                'status_note' => 'Injected at runtime through the admin surface.',
                'related_specs' => 'entity-system',
                'weight' => 0,
                'body' => 'Injected body.',
                'status' => true,
                'source_sha1' => 'runtime',
            ],
            default => [
                'title' => 'Runtime injected case study',
                'slug' => 'runtime-injected-case-study',
                'org' => 'Example Org',
                'site_url' => 'https://example.test/runtime',
                'summary' => 'Injected at runtime through the admin surface.',
                'body' => 'Injected body.',
                'status' => true,
                'source_sha1' => 'runtime',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $type, string $action): array
    {
        return match ($action) {
            'create' => ['attributes' => $this->createAttributes($type)],
            default => ['id' => $this->existingIdFor($type), 'attributes' => ['title' => 'runtime-tampered']],
        };
    }

    /**
     * Discovery, not construction: the policy must be present in the handler
     * the KERNEL composed, alongside the two unconditional framework defaults.
     * Nothing in this test adds it.
     *
     * Discovery mechanics worth knowing: AbstractKernel::discoverAccessPolicies()
     * builds the handler from AccessPolicyRegistry->discover($this->manifest),
     * and the manifest comes from ManifestBootstrapper::boot($root, freshCompile:
     * $this->isDevelopmentMode()). In dev/local/testing the manifest is compiled
     * fresh on every boot (a real PSR-4 attribute scan), which is why this
     * assertion is a genuine discovery assertion here. In production the kernel
     * reads the storage/framework/packages.php cache instead, and that cache's
     * staleness fingerprint covers composer.json / installed.json / the autoload
     * maps only, NOT app source files, so a pre-existing cache written before
     * this policy was added would not name it. The deploy path is safe because
     * storage/ is gitignored and the image is built from a fresh clone, so the
     * cache is always compiled after the policy exists; `optimize:manifest`
     * is the explicit lever if a cache ever needs rebuilding in place.
     */
    #[Test]
    public function the_booted_kernel_discovers_the_content_write_protection_policy(): void
    {
        $policies = $this->policiesOf($this->kernel()->getAccessHandler());

        self::assertContains(
            ContentWriteProtectionPolicy::class,
            $policies,
            'ContentWriteProtectionPolicy must be discovered by the real kernel boot, not just constructible by hand.',
        );
        self::assertContains(
            PublishedContentAccessPolicy::class,
            $policies,
            'sanity: the kernel-composed handler must carry the framework default read policy.',
        );
        self::assertContains(
            ContentAdminAccessPolicy::class,
            $policies,
            'sanity: the kernel-composed handler must carry the framework default `administer content` policy this test overrides.',
        );
    }

    /**
     * The reachable admin write path must consult THAT handler, not another
     * one. AdminSurfaceServiceProvider::discoverAccessHandler() pulls
     * EntityAccessHandler out of the kernel-services bus and closes the host
     * over it; this asserts object identity with $kernel->getAccessHandler(),
     * which is what makes the denial below a property of the booted kernel
     * rather than of a handler this test happened to build.
     */
    #[Test]
    public function the_admin_surface_host_uses_the_kernel_composed_access_handler(): void
    {
        $used = new \ReflectionFunction($this->adminSurfaceActionController())->getClosureUsedVariables();
        $host = $used['host'] ?? null;
        self::assertIsObject($host, 'the admin_surface.action closure must capture the surface host.');

        $handler = new \ReflectionProperty($host, 'accessHandler')->getValue($host);

        self::assertSame(
            $this->kernel()->getAccessHandler(),
            $handler,
            'the admin-surface write path must use the kernel-composed access handler; a different handler would make every denial below meaningless.',
        );
    }

    #[Test]
    #[DataProvider('protectedTypes')]
    public function admin_surface_create_is_refused_for_an_administer_content_holder(string $type): void
    {
        $this->assertWriteRefused($type, 'create', $this->dispatchWrite($type, 'create', $this->payloadFor($type, 'create')));
    }

    #[Test]
    #[DataProvider('protectedTypes')]
    public function admin_surface_update_is_refused_for_an_administer_content_holder(string $type): void
    {
        $this->assertWriteRefused($type, 'update', $this->dispatchWrite($type, 'update', $this->payloadFor($type, 'update')));
    }

    /**
     * Delete additionally proves ATTRIBUTION. GenericAdminSurfaceHost::handleDelete()
     * surfaces the AccessResult's own reason string verbatim, so asserting it
     * ties the refusal to ContentWriteProtectionPolicy specifically rather than
     * to some unrelated denial that happens to also produce a 403. (Create and
     * update route through JsonApiController, which replaces the reason with a
     * generic message, so attribution is only observable here.)
     */
    #[Test]
    #[DataProvider('protectedTypes')]
    public function admin_surface_delete_is_refused_for_an_administer_content_holder(string $type): void
    {
        $response = $this->dispatchWrite($type, 'delete', $this->payloadFor($type, 'delete'));
        $this->assertWriteRefused($type, 'delete', $response);

        $envelope = $this->decode($response);
        $detail = $envelope['error']['detail'] ?? null;
        self::assertIsString($detail, sprintf('%s: the delete refusal must explain itself.', $type));
        self::assertStringContainsString(
            'content:sync is the sole writer',
            $detail,
            sprintf('%s: the delete must be refused BY ContentWriteProtectionPolicy (its own reason string), not by an unrelated denial.', $type),
        );
    }

    /**
     * The refusal is not cosmetic: after create, update and delete have all
     * been attempted against every protected type, the stored corpus is
     * identical, revision counts included (an accepted update would add one).
     */
    #[Test]
    public function refused_writes_change_nothing_in_storage(): void
    {
        $before = $this->corpusSnapshot();

        foreach (self::PROTECTED_TYPES as $type) {
            foreach (['create', 'update', 'delete'] as $action) {
                $this->assertWriteRefused($type, $action, $this->dispatchWrite($type, $action, $this->payloadFor($type, $action)));
            }
        }

        self::assertSame(
            $before,
            $this->corpusSnapshot(),
            'refused admin-surface writes must leave the git-authored corpus byte-identical: no created row, no new revision, no deletion.',
        );
    }

    /**
     * Pins the transport shape described in the class docblock: the admin
     * surface reports its 403 inside the result envelope, and
     * ControllerDispatcher::handleCallable() emits HTTP 200 because
     * AdminSurfaceResultData::toArray() has no `statusCode` key. If a framework
     * release ever promotes `error.status` to the HTTP status, this test fails
     * and the change gets reviewed instead of silently landing.
     */
    #[Test]
    public function denial_is_transported_as_an_http_200_with_a_403_envelope(): void
    {
        $response = $this->dispatchWrite('release', 'update', $this->payloadFor('release', 'update'));
        $envelope = $this->decode($response);

        self::assertSame(
            403,
            $envelope['error']['status'] ?? null,
            'release: the admin-surface envelope must carry the 403 refusal.',
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            'release: admin-surface denials currently ride an HTTP 200 envelope (ControllerDispatcher::handleCallable defaults the status when the array has no `statusCode` key). Update this test deliberately if the framework changes that.',
        );
    }

    /**
     * content:sync must remain the sole writer AND remain fully functional in
     * the same booted kernel that just refused three admin writes. This is the
     * other half of the proof: the policy narrows the request path only, and
     * never touches EntityRepository::save(), which does not consult access
     * policies.
     *
     * One method covers create, revise, unpublish and replay in sequence
     * because each step's assertion depends on the previous step's state.
     */
    #[Test]
    public function content_sync_still_creates_revises_unpublishes_and_replays(): void
    {
        $manager = $this->kernel()->getEntityTypeManager();
        $repository = $manager->getRepository('release');
        $slug = 'v0.1.0-alpha.901';

        // create
        self::writeRelease($slug, 'First cut.');
        self::assertSame(1, $this->sync()->created, 'content:sync must create a new release in the booted kernel.');
        $entity = $this->releaseBySlug($slug);
        self::assertSame('First cut.', (string) $entity->get('summary'));
        self::assertSame(1, EntityValues::statusToInt($entity->get('status')));

        // revise
        self::writeRelease($slug, 'Amended cut.');
        self::assertSame(1, $this->sync()->updated, 'content:sync must record an update when the file changes.');
        $entity = $this->releaseBySlug($slug);
        self::assertSame('Amended cut.', (string) $entity->get('summary'));
        self::assertGreaterThanOrEqual(
            2,
            count($repository->listRevisions((string) $entity->id())),
            'a changed file must save a new revision, not overwrite history.',
        );

        // unpublish
        unlink(self::$contentRoot . '/releases/' . $slug . '.md');
        self::assertSame(1, $this->sync()->unpublished, 'a removed file must unpublish rather than delete.');
        $entity = $this->releaseBySlug($slug);
        self::assertSame(0, EntityValues::statusToInt($entity->get('status')));

        // replay: rerunning against the same tree must be a total no-op
        $replay = $this->sync();
        self::assertSame(
            0,
            $replay->created + $replay->updated + $replay->unpublished,
            'content:sync must be idempotent: a replay against an unchanged tree must write nothing.',
        );
        self::assertGreaterThan(0, $replay->unchanged, 'the replay must have seen the corpus and classified it unchanged.');
    }

    private function releaseBySlug(string $slug): \Waaseyaa\Entity\EntityInterface
    {
        foreach ($this->kernel()->getEntityTypeManager()->getRepository('release')->findBy([]) as $entity) {
            if ((string) $entity->get('slug') === $slug) {
                return $entity;
            }
        }

        self::fail(sprintf('release: no entity with slug "%s" was found after content:sync.', $slug));
    }

    /**
     * EntityAccessHandler exposes no policy accessor, so read the composed list
     * reflectively. This inspects the kernel's own handler; it never builds one.
     *
     * @return list<string>
     */
    private function policiesOf(EntityAccessHandler $handler): array
    {
        $policies = new \ReflectionProperty($handler, 'policies')->getValue($handler);
        self::assertIsArray($policies, 'EntityAccessHandler must hold a policy list.');

        return array_values(array_map(static fn (object $policy): string => $policy::class, $policies));
    }
}
