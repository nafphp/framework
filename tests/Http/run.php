<?php

declare(strict_types=1);

use Tests\Support\HttpHost;

require dirname(__DIR__) . '/Support/HttpHost.php';

$root = sys_get_temp_dir() . '/naf-http-' . bin2hex(random_bytes(8));
mkdir($root . '/public', 0777, true);
mkdir($root . '/app');
$autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
file_put_contents($root . '/public/index.php', '<?php define("BASE_PATH", dirname(__DIR__)); require ' . $autoload . '; \Naf\app()->run();');
file_put_contents($root . '/.env', "APP_ENV=test\n");
file_put_contents($root . '/app/routes.php', <<<'PHP'
<?php
use Naf\Core\Event;
use Naf\Exceptions\MethodNotAllowedException;
use function Naf\{event, json, response, route};

event()->listen(Event::EXCEPTION, static fn(Throwable $e) => $e instanceof MethodNotAllowedException ? json(['error' => 'method'], 405) : null);
event()->listen(Event::RESPONSE_HEADER, static fn($response) => $response->withHeader('X-First', 'one')->withAddedHeader('Set-Cookie', 'one=1'));
event()->listen(Event::RESPONSE_HEADER, static fn($response) => $response->withHeader('X-Second', 'two')->withAddedHeader('Set-Cookie', 'two=2'));
route()->add('GET', '/items', static fn() => response('hello', 200, ['Content-Type' => 'text/plain']), 'items');
route()->add('GET', '/empty', static fn() => response('forbidden', 204, ['Content-Length' => '9', 'Transfer-Encoding' => 'chunked']), 'empty');
route()->add('GET', '/cached', static fn() => response('forbidden', 304, ['ETag' => '"test"', 'Content-Length' => '9']), 'cached');
route()->add('GET', '/reset', static fn() => response('forbidden', 205), 'reset');
route()->add('GET', '/failure', static fn() => throw new RuntimeException('private-secret'), 'failure');
route()->add('GET', '/stream', static fn() => response(fopen('data://text/plain,' . str_repeat('x', 20000), 'r')), 'stream');
PHP);
$host = null;

try {
    $host = new HttpHost($root);
    $host->start();
    $get = $host->request('GET', '/items');
    HttpHost::expect($get['status'] === 200 && $get['body'] === 'hello', 'GET response');
    HttpHost::expect(($get['headers']['x-first'] ?? []) === ['one'] && ($get['headers']['x-second'] ?? []) === ['two'], 'Chained header listeners');
    HttpHost::expect(($get['headers']['set-cookie'] ?? []) === ['one=1', 'two=2'], 'Repeated Set-Cookie headers');
    $head = $host->request('HEAD', '/items');
    HttpHost::expect($head['status'] === 200 && $head['body'] === '' && ($head['headers']['content-length'] ?? []) === ['5'], 'HEAD metadata without content');
    $method = $host->request('POST', '/items');
    HttpHost::expect($method['status'] === 405 && ($method['headers']['allow'] ?? []) === ['GET, HEAD, OPTIONS'], '405 with Allow through a JSON exception listener');
    HttpHost::expect(json_decode($method['body'], true) === ['error' => 'method'], 'JSON error response');
    $options = $host->request('OPTIONS', '/items');
    HttpHost::expect($options['status'] === 204 && $options['body'] === '' && ($options['headers']['allow'] ?? []) === ['GET, HEAD, OPTIONS'], 'Automatic OPTIONS');
    foreach (['/empty' => 204, '/cached' => 304, '/reset' => 205] as $path => $status) {
        $result = $host->request('GET', $path);
        HttpHost::expect($result['status'] === $status && $result['body'] === '', 'No content for ' . $status);
        HttpHost::expect(!isset($result['headers']['transfer-encoding']), 'No content transfer encoding');
        if ($status === 204) {
            HttpHost::expect(!isset($result['headers']['content-length']), 'No Content-Length for 204');
        }
    }
    $error = $host->request('GET', '/failure');
    HttpHost::expect($error['status'] === 500 && !str_contains($error['body'], 'private-secret'), 'Sanitized server error');
    HttpHost::expect($host->request('GET', '/missing')['status'] === 404, 'Unknown path');
    $stream = $host->request('GET', '/stream');
    HttpHost::expect($stream['status'] === 200 && $stream['body'] === str_repeat('x', 20000), 'Bounded stream output');
    echo "HTTP contracts passed (routing, HEAD, OPTIONS, 405, content rules, errors, headers, streams).\n";
} finally {
    $host?->stop();
    HttpHost::remove($root);
}
