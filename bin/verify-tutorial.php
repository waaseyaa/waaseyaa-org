<?php

declare(strict_types=1);

/**
 * Run after composer create-project into a NEW, disposable directory.
 * Usage: php bin/verify-tutorial.php /absolute/path/to/fresh-project
 * The installed starter is retained for inspection; the server is always stopped.
 */

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$project = realpath($argv[1] ?? '');
if ($project === false || !is_file($project . '/vendor/autoload.php')
    || !is_file($project . '/bin/post-create-setup.php')
    || is_file($project . '/.waaseyaa/site.yaml')
    || is_file($project . '/src/Entity/Todo.php')) {
    throw new RuntimeException('Pass a newly created, disposable Waaseyaa starter directory.');
}
$lock = json_decode(file_get_contents($project . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$packages = array_column($lock['packages'], null, 'name');
$version = $packages['waaseyaa/framework']['version'];
$siteManifest = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (ltrim($version, 'v') !== ltrim($siteManifest['require']['waaseyaa/framework'], 'v')) {
    throw new RuntimeException('The fresh application must resolve the exact framework release used by the guide.');
}
$twig = new Environment(new ChainLoader([
    new ArrayLoader(['base.html.twig' => '{% block content %}{% endblock %}']),
    new FilesystemLoader(dirname(__DIR__) . '/templates'),
]), ['autoescape' => 'html']);
$html = $twig->render('start.html.twig', [
    'framework_version' => $version,
    'canonical_base' => 'https://waaseyaa.org',
]);
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
$files = [];
foreach ((new DOMXPath($document))->query('//code[@data-tutorial-file]') as $node) {
    $name = $node->getAttribute('data-tutorial-file');
    if (!preg_match('~^(?:src/|config/|templates/|site\.seed\.yaml$)[a-zA-Z0-9_./-]*$~', $name)
        || str_contains($name, '..') || isset($files[$name])) {
        throw new RuntimeException('Invalid or duplicate tutorial path: ' . $name);
    }
    $files[$name] = $node->textContent . "\n";
}
if (count($files) !== 7 || !isset($files['site.seed.yaml'])) {
    throw new RuntimeException('The guide must contain all seven complete application files.');
}
$write = static function (string $name, string $content) use ($project): void {
    $path = $project . '/' . $name;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
};
$run = static function (array $command) use ($project): void {
    echo '> ', implode(' ', $command), "\n";
    (new Process($command, $project, timeout: 180))->mustRun(static function ($type, $output): void {
        echo $output;
    });
};
$write('site.seed.yaml', $files['site.seed.yaml']);
$run([PHP_BINARY, 'vendor/bin/waaseyaa', 'site:init', '--preset=minimal', '--answers=site.seed.yaml', '--yes', '--no-interaction']);
unset($files['site.seed.yaml']);
foreach ($files as $name => $content) {
    $write($name, $content);
}
// site:init adds its own provider. Keep it and every original starter entry.
$manifest = json_decode(file_get_contents($project . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest['extra']['waaseyaa']['providers'][] = 'App\\Provider\\TodoServiceProvider';
$write('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$run(['composer', 'dump-autoload', '--no-interaction']);
$run([PHP_BINARY, 'vendor/bin/waaseyaa', 'install:init', '--no-interaction']);
$run([PHP_BINARY, 'vendor/bin/waaseyaa', 'optimize:manifest']);
$run(['composer', 'site-verify']);

// Ask the OS for an unused loopback port; no shared developer server is touched.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
$port = substr($address, strrpos($address, ':') + 1);
fclose($socket);
// Use the same public adapter as `waaseyaa serve`, owning the PHP server
// directly so Unix and Windows cleanup cannot leave a grandchild listening.
$serverCommand = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $project . '/public', $project . '/public/index.php'];
$server = new Process($serverCommand, $project, ['PHP_CLI_SERVER_WORKERS' => false], timeout: 120);
$client = HttpClient::create(['base_uri' => 'http://127.0.0.1:' . $port, 'max_redirects' => 0, 'timeout' => 10]);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ', $message, "\n";
};
$get = static fn () => $client->request('GET', '/todos')->getContent();
$post = static function (string $path, array $body = []) use ($client, $assert): void {
    $response = $client->request('POST', $path, ['body' => $body]);
    if ($response->getStatusCode() !== 302) {
        fwrite(STDERR, 'POST ' . $path . ': HTTP ' . $response->getStatusCode() . ' ' . $response->getContent(false) . "\n");
    }
    $assert($response->getStatusCode() === 302 && ($response->getHeaders(false)['location'][0] ?? '') === '/todos', 'POST ' . $path . ' redirects to the list');
};
try {
    $server->start();
    $ready = false;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        try {
            $response = $client->request('GET', '/todos');
            if ($response->getStatusCode() === 200) {
                $ready = true;
                break;
            }
            fwrite(STDERR, 'GET /todos: HTTP ' . $response->getStatusCode() . ' ' . $response->getContent(false) . "\n");
            break;
        } catch (Throwable) {
            // The server may still be starting. Every request and wait is bounded.
        }
        if (!$server->isRunning()) {
            break;
        }
        usleep(200000);
    }
    $assert($ready, 'Fresh application serves /todos');
    $assert(!str_contains($get(), 'data-todo-id='), 'Fresh list is empty');
    $post('/todos', ['title' => '  ', 'priority' => 'normal']);
    $assert(!str_contains($get(), 'data-todo-id='), 'Blank titles do not create a todo');
    $post('/todos', ['title' => 'Walkthrough <script>alert(1)</script>', 'priority' => 'high']);
    $page = $get();
    $assert(str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page, '<script>'), 'Saved titles are escaped');
    $assert((bool) preg_match('/data-todo-id="([0-9]+)"/', $page, $match), 'Created todo is rendered with an id');
    $id = $match[1];
    $assert(str_contains($page, '(high)') && str_contains($page, '>Complete</button>'), 'New todo is pending with its submitted priority');
    $post('/todos/' . $id . '/toggle');
    $assert(str_contains($get(), '>Mark pending</button>'), 'Typed entity toggle moves the todo to Completed');
    $post('/todos/' . $id . '/toggle');
    $assert(str_contains($get(), '>Complete</button>'), 'Second toggle persists across a fresh request');
    $post('/todos/' . $id . '/delete');
    $assert(!str_contains($get(), 'data-todo-id='), 'Delete persists across a fresh request');
    foreach (['toggle', 'delete'] as $action) {
        $assert($client->request('POST', '/todos/' . $id . '/' . $action)->getStatusCode() === 404, 'Unknown entity ' . $action . ' returns 404');
    }
    $server->stop(3);
    $server = new Process($serverCommand, $project, ['PHP_CLI_SERVER_WORKERS' => false, 'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'false'], timeout: 60);
    $server->start();
    $anonymousStatus = null;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        try {
            $anonymousStatus = $client->request('GET', '/todos')->getStatusCode();
            break;
        } catch (Throwable) {
            usleep(200000);
        }
    }
    $assert(in_array($anonymousStatus, [401, 403], true), 'Disabling development authentication denies anonymous todo access');
    echo 'Fresh-install walkthrough passed for ', $version, ' in ', $project, "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, substr($server->getErrorOutput(), -6000));
    throw $failure;
} finally {
    $server->stop(3);
}
