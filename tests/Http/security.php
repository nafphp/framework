<?php

declare(strict_types=1);

use Tests\Support\HttpHost;

require_once dirname(__DIR__) . '/Support/HttpHost.php';

$root = sys_get_temp_dir() . '/naf-security-' . bin2hex(random_bytes(8));
mkdir($root . '/public', 0777, true);
mkdir($root . '/app');
$autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
$previous = getenv('APP_ENV');
$host = null;

try {
    // Exercise the same environment from the process and from each supported file.
    foreach (['process', '.env', '.env.local'] as $source) {
        foreach (['dev', 'test', 'prod', 'unknown', '', null] as $environment) {
            $environment === null || $source !== 'process' ? putenv('APP_ENV') : putenv('APP_ENV=' . $environment);
            foreach (['.env', '.env.local'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            if ($source !== 'process' && $environment !== null) {
                file_put_contents($root . '/' . $source, 'APP_ENV=' . $environment . "\n");
            }
            file_put_contents($root . '/app/routes.php', <<<'ROUTES'
<?php
use function Naf\{json, route};
if (isset($_GET['boot'])) {
    ob_start();
    echo 'buffered-secret';
    throw new RuntimeException('bootstrap-secret');
}
route()->add('GET', '/failure', static fn() => throw new RuntimeException('controller-secret'), 'failure');
route()->add('GET', '/forbidden', static fn() => \Naf\abort(403, 'authorization-secret'), 'forbidden');
route()->add('GET', '/segment/{value}', static fn(string $value) => json(['value' => $value]), 'segment');
ROUTES);
            file_put_contents($root . '/public/index.php', '<?php define("BASE_PATH", dirname(__DIR__)); require ' . $autoload . '; \Naf\app()->run();');
            $host = new HttpHost($root);
            $host->start();
            foreach (['/failure' => 500, '/?boot=1' => 500, '/forbidden' => 403] as $path => $status) {
                $result = $host->request('GET', $path);
                HttpHost::expect($result['status'] === $status, "$source / $environment / $path status");
                HttpHost::expect(!str_starts_with($result['body'], 'buffered-secret'), 'Discard partial output');
                if ($environment !== 'dev') {
                    foreach (['controller-secret', 'bootstrap-secret', 'authorization-secret', $root, 'Stack trace', 'app/routes.php'] as $secret) {
                        HttpHost::expect(!str_contains($result['body'], $secret), "$source / $environment leaked $secret");
                    }
                } else {
                    HttpHost::expect(str_contains($result['body'], '-secret'), 'Dev diagnostics');
                }
            }
            $segment = $host->request('GET', '/segment/a%2Fb%3Fc%23d%25%20e');
            HttpHost::expect($segment['status'] === 200 && json_decode($segment['body'], true) === ['value' => 'a/b?c#d% e'], 'Encoded path segment reaches the handler intact');
            $host->stop();
            $host = null;
        }
    }

    // Even before app() and .env loading, the handler must fail closed.
    foreach (['test', 'prod', 'unknown', '', null] as $environment) {
        $environment === null ? putenv('APP_ENV') : putenv('APP_ENV=' . $environment);
        file_put_contents($root . '/public/index.php', '<?php require ' . $autoload . '; throw new RuntimeException("autoload-secret");');
        $host = new HttpHost($root);
        $host->start();
        $result = $host->request('GET', '/');
        HttpHost::expect($result['status'] === 500 && !str_contains($result['body'], 'autoload-secret') && !str_contains($result['body'], $root), 'Pre-boot exception is sanitized');
        HttpHost::expect(str_contains(file_get_contents($root . '/server.log'), 'autoload-secret'), 'Pre-boot exceptions remain logged');
        $host->stop();
        $host = null;
    }

    // CLI keeps diagnostics on STDERR and returns a non-zero status.
    putenv('APP_ENV=test');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) {
        $command[] = '-n';
    }
    array_push($command, '-r', 'require ' . $autoload . '; throw new RuntimeException("cli-secret");');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    HttpHost::expect(proc_close($process) === 1 && $stdout === '' && str_contains($stderr, 'cli-secret'), 'CLI reports failures to the operator');
    echo "Security HTTP/CLI contracts passed (process/.env/.env.local, dev/test/prod/unknown/missing, boot, status, logging, route segments).\n";
} finally {
    $host?->stop();
    $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
    HttpHost::remove($root);
}
