<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Naf\Core\App;
use Naf\Core\Container;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/naf-plugin-order-' . bin2hex(random_bytes(8));
mkdir($root . '/src', 0777, true);
define('BASE_PATH', $root);
$GLOBALS['booted'] = [];
$scenario          = $argv[1] ?? 'normal';
$files             = [];
$write             = static function (string $path, string $contents) use (&$files): void {
    file_put_contents($path, $contents);
    $files[] = $path;
};
$versions = [];
foreach (['test/a-consumer', 'test/z-base'] as $package) {
    $path = $root . '/' . basename($package);
    mkdir($path . '/src', 0777, true);
    $versions[$package] = ['type' => 'naf-plugin', 'install_path' => $path, 'version' => '1.0.0.0', 'pretty_version' => '1.0.0'];
    $boot               = $package === 'test/a-consumer' ? ['after' => ['test/z-base']] : [];
    if ($scenario === 'cycle' && $package === 'test/z-base') {
        $boot = ['after' => ['test/a-consumer']];
    }
    if ($scenario === 'invalid') {
        $boot = ['after' => 'test/z-base'];
    }
    $write($path . '/composer.json', $scenario === 'json' ? '{' : json_encode(['name' => $package, 'extra' => ['naf' => ['boot' => $boot]]]));
    $value = $package === 'test/a-consumer' ? 'consumer' : 'base';
    $write($path . '/src/config.php', '<?php return ["boot_value" => "' . $value . '"];');
    $write($path . '/src/routes.php', '<?php \Naf\route()->add("GET", "/", static fn() => "' . $value . '", "home");');
    $write($path . '/bootstrap.php', '<?php $GLOBALS["booted"][] = "' . $package . '";'
        . '$GLOBALS["registered_at_first_boot"] ??= count(\Naf\app()->getPlugins());'
        . '$GLOBALS["seen_config"] = \Naf\config("boot_value");');
}
$write($root . '/src/routes.php', '<?php $GLOBALS["host_routes_saw"] = (\Naf\route()->all()["home"]["action"])();'
    . '\Naf\route()->add("GET", "/", static fn() => "host", "home");');
if ($scenario === 'manual') {
    $write($root . '/src/plugins.php', '<?php return ["test/a-consumer", "test/z-base"];');
}
InstalledVersions::reload(['root' => ['name' => 'test/host'], 'versions' => $versions]);
$status = 0;

try {
    $app = new App(new Container());
    $app->run();
    echo json_encode([
        'booted'                   => $GLOBALS['booted'],
        'config'                   => $GLOBALS['seen_config'],
        'registered_at_first_boot' => $GLOBALS['registered_at_first_boot'],
        'host_routes_saw'          => $GLOBALS['host_routes_saw'],
        'route'                    => (\Naf\route()->all()['home']['action'])(),
    ]);
} catch (Throwable $exception) {
    echo json_encode(['booted' => $GLOBALS['booted'], 'error' => $exception->getMessage()]);
    $status = 1;
} finally {
    foreach (array_reverse($files) as $file) {
        unlink($file);
    }
    foreach (['a-consumer', 'z-base'] as $directory) {
        rmdir($root . '/' . $directory . '/src');
        rmdir($root . '/' . $directory);
    }
    rmdir($root . '/src');
    rmdir($root);
}
exit($status);
