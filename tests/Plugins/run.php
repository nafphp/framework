<?php

declare(strict_types=1);

use Tests\Support\HttpHost;

require dirname(__DIR__) . '/Support/HttpHost.php';

$root = sys_get_temp_dir() . '/naf-plugins-' . bin2hex(random_bytes(8));
mkdir($root . '/public', 0777, true);
mkdir($root . '/app');
mkdir($root . '/app/views');
mkdir($root . '/translations');
$manifest = [
    'name'         => 'test/naf-plugin-contracts',
    'repositories' => [['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => ['symlink' => true, 'versions' => ['naf/framework' => '0.2.12']]]],
    'require'      => ['php' => '>=8.3', 'naf/framework' => '*', 'naf/form' => '^0.2.4', 'naf/session' => '^0.2.3', 'naf/view' => '^0.2.3', 'naf/i18n' => '^0.2'],
];
file_put_contents($root . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/.env', "APP_ENV=test\n");
file_put_contents($root . '/public/index.php', '<?php define("BASE_PATH", dirname(__DIR__)); require BASE_PATH . "/vendor/autoload.php"; \Naf\event()->listen(\Naf\Core\Event::RESPONSE_HEADER, static fn($r) => $r->withHeader("X-Application", "present")); \Naf\app()->run();');
file_put_contents($root . '/app/config.php', '<?php return ["language" => "en", "app" => ["translationPath" => "/translations"]];');
file_put_contents($root . '/translations/en.json', '{}');
file_put_contents($root . '/translations/de.json', '{}');
file_put_contents($root . '/app/views/form.phtml', '<form method="post"><input name="_csrf" value="<?= \Naf\View\s(\Naf\Form\csrf()->token()) ?>"></form>');
file_put_contents($root . '/app/routes.php', <<<'PHP'
<?php
use function Naf\route;
route()->add('GET', '/form', static fn() => \Naf\View\render('form'), 'form');
route()->add('POST', '/form', static fn() => \Naf\json(['saved' => true]), 'form.save');
route()->add('GET', '/locale', static fn() => \Naf\json(['language' => \Naf\I18n\lang()]), 'locale');
PHP);
$host = null;

try {
    $host    = new HttpHost($root);
    $process = proc_open(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/composer.log', 'a'], 2 => ['file', $root . '/composer.log', 'a']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Composer.');
    }
    fclose($pipes[0]);
    HttpHost::expect(proc_close($process) === 0, 'Plugin installation: ' . file_get_contents($root . '/composer.log'));
    $host->start();
    $form = $host->request('GET', '/form');
    HttpHost::expect($form['status'] === 200, 'View and session boot: ' . $form['body']);
    preg_match('/name="_csrf" value="([^"]+)"/', $form['body'], $token);
    HttpHost::expect(isset($token[1]) && $token[1] !== '', 'Rendered CSRF token');
    $cookies = [];
    foreach ($form['headers']['set-cookie'] ?? [] as $cookie) {
        $cookies[] = explode(';', $cookie, 2)[0];
    }
    HttpHost::expect($cookies !== [], 'Session cookie');
    $headers = ['Cookie' => implode('; ', $cookies), 'Content-Type' => 'application/x-www-form-urlencoded'];
    HttpHost::expect($host->request('POST', '/form', $headers)['status'] === 400, 'CSRF rejects a missing token');
    $saved = $host->request('POST', '/form', $headers, http_build_query(['_csrf' => $token[1]]));
    HttpHost::expect($saved['status'] === 200 && json_decode($saved['body'], true) === ['saved' => true], 'CSRF accepts the session token');
    $locale = $host->request('GET', '/locale?lang=de');
    HttpHost::expect($locale['status'] === 200 && json_decode($locale['body'], true) === ['language' => 'de'], 'i18n request hook');
    HttpHost::expect(($locale['headers']['x-application'] ?? []) === ['present'], 'Application response header');
    HttpHost::expect(count(array_filter($locale['headers']['set-cookie'] ?? [], static fn(string $cookie) => str_starts_with($cookie, 'lang='))) === 1, 'i18n cookie survives header composition');
    echo "Plugin integration passed (Form, Session, View, i18n and application response headers).\n";
} finally {
    $host?->stop();
    HttpHost::remove($root);
}
