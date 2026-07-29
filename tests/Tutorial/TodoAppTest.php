<?php

declare(strict_types=1);

namespace App\Tests\Tutorial;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Validates that every code pattern shown on the /start getting-started
 * page (templates/start.html.twig) compiles and works against the locked
 * framework version. The page and this test state the same code; when the
 * framework breaks this test, the page is wrong and must change with it.
 *
 * @see https://waaseyaa.org/start
 */
#[CoversNothing]
final class TodoAppTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        // /start step 2: derive the entity type from the class attributes.
        $todoType = EntityType::fromClass(TodoEntity::class);

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            repositoryFactory: function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher) {
                // Create schema (table) for the entity type. Fields live in the
                // entity's data blob under the default sql-blob backend, so the
                // base table is all we need.
                $schemaHandler = new SqlSchemaHandler($definition, $database);
                $schemaHandler->ensureTable();

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
        $this->entityTypeManager->registerEntityType($todoType);
    }

    // -- /start step 1: entity class with convenience methods --

    #[Test]
    public function todo_entity_class_works(): void
    {
        $todo = new TodoEntity([
            'title' => 'Learn Waaseyaa',
            'completed' => false,
            'priority' => 'high',
        ]);

        self::assertSame('Learn Waaseyaa', $todo->label());
        self::assertFalse($todo->isCompleted());
        self::assertSame('high', $todo->get('priority'));
    }

    #[Test]
    public function todo_toggle_completed(): void
    {
        $todo = new TodoEntity(['title' => 'Test toggle', 'completed' => false]);

        $todo->toggleCompleted();
        self::assertTrue($todo->isCompleted());

        $todo->toggleCompleted();
        self::assertFalse($todo->isCompleted());
    }

    // -- /start step 2: entity type registration and repository CRUD --

    #[Test]
    public function create_and_load_todo(): void
    {
        $repository = $this->entityTypeManager->getRepository('todo');

        $todo = $repository->create([
            'title' => 'Call the plumber',
            'completed' => false,
            'priority' => 'high',
        ]);
        $repository->save($todo);

        $id = $todo->id();
        self::assertNotNull($id);

        $loaded = $repository->find((string) $id);
        self::assertInstanceOf(TodoEntity::class, $loaded);
        self::assertSame('Call the plumber', $loaded->label());
        self::assertSame('high', $loaded->get('priority'));
    }

    #[Test]
    public function toggle_persisted_todo(): void
    {
        $repository = $this->entityTypeManager->getRepository('todo');

        $todo = $repository->create([
            'title' => 'Buy groceries',
            'completed' => false,
            'priority' => 'normal',
        ]);
        $repository->save($todo);

        // Toggle to completed (mirrors TodoController::toggle on /start).
        $todo->toggleCompleted();
        $repository->save($todo);

        $loaded = $repository->find((string) $todo->id());
        self::assertTrue($loaded->isCompleted());

        // Toggle back.
        $loaded->toggleCompleted();
        $repository->save($loaded);

        $reloaded = $repository->find((string) $todo->id());
        self::assertFalse($reloaded->isCompleted());
    }

    #[Test]
    public function delete_todo(): void
    {
        $repository = $this->entityTypeManager->getRepository('todo');

        $todo = $repository->create(['title' => 'Temporary task', 'completed' => false]);
        $repository->save($todo);

        $id = $todo->id();
        $repository->delete($todo);

        self::assertNull($repository->find((string) $id));
    }

    #[Test]
    public function load_multiple_todos(): void
    {
        $repository = $this->entityTypeManager->getRepository('todo');

        foreach (['First', 'Second', 'Third'] as $title) {
            $todo = $repository->create(['title' => $title, 'completed' => false, 'priority' => 'normal']);
            $repository->save($todo);
        }

        $all = $repository->findBy([]);
        self::assertCount(3, $all);
    }

    // -- /start step 3: route definitions --

    #[Test]
    public function tutorial_routes_match_get_requests(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');
        $router = new WaaseyaaRouter($context);
        $this->registerTutorialRoutes($router);

        $match = $router->match('/todos');
        self::assertSame('todo.list', $match['_route']);
    }

    #[Test]
    public function tutorial_routes_match_post_requests(): void
    {
        $context = new RequestContext();
        $context->setMethod('POST');
        $router = new WaaseyaaRouter($context);
        $this->registerTutorialRoutes($router);

        $match = $router->match('/todos');
        self::assertSame('todo.create', $match['_route']);

        $match = $router->match('/todos/1/toggle');
        self::assertSame('todo.toggle', $match['_route']);
        self::assertSame('1', $match['todo']);

        $match = $router->match('/todos/42/delete');
        self::assertSame('todo.delete', $match['_route']);
        self::assertSame('42', $match['todo']);
    }

    /**
     * Registers the exact routes from TodoServiceProvider::routes() on /start.
     */
    private function registerTutorialRoutes(WaaseyaaRouter $router): void
    {
        $router->addRoute('todo.list', RouteBuilder::create('/todos')
            ->controller('App\Controller\TodoController::list')
            ->methods('GET')
            ->allowAll()
            ->build());

        $router->addRoute('todo.create', RouteBuilder::create('/todos')
            ->controller('App\Controller\TodoController::create')
            ->methods('POST')
            ->allowAll()
            ->csrfExempt()
            ->build());

        $router->addRoute('todo.toggle', RouteBuilder::create('/todos/{todo}/toggle')
            ->controller('App\Controller\TodoController::toggle')
            ->entityParameter('todo', 'todo')
            ->methods('POST')
            ->allowAll()
            ->csrfExempt()
            ->build());

        $router->addRoute('todo.delete', RouteBuilder::create('/todos/{todo}/delete')
            ->controller('App\Controller\TodoController::delete')
            ->entityParameter('todo', 'todo')
            ->methods('POST')
            ->allowAll()
            ->csrfExempt()
            ->build());
    }
}

/**
 * The Todo entity from /start step 1: the same attributes, fields, and
 * methods the page shows. It is named TodoEntity here so it stays a test
 * fixture; this application itself deliberately registers no entities.
 */
#[ContentEntityType(id: 'todo', label: 'Todo', api: true)]
#[ContentEntityKeys(label: 'title')]
class TodoEntity extends ContentEntityBase
{
    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(label: 'Completed', read: FieldReadLevel::Public)]
    public bool $completed = false;

    #[Field(label: 'Priority', read: FieldReadLevel::Public)]
    public string $priority = 'normal';

    public function isCompleted(): bool
    {
        return (bool) $this->get('completed');
    }

    public function toggleCompleted(): static
    {
        return $this->set('completed', !$this->isCompleted());
    }
}
