<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
define('BASE_PATH', __DIR__);
$versions = [];
foreach (['first', 'second'] as $name) {
    $versions['test/' . $name] = [
        'type' => 'naf-plugin',
        'install_path' => __DIR__ . '/' . $name,
        'version' => '1.0.0.0',
        'pretty_version' => '1.0.0',
    ];
}
\Composer\InstalledVersions::reload(['root' => ['name' => 'test/host'], 'versions' => $versions]);
\Naf\app();
echo json_encode($GLOBALS['bootOrder']);
