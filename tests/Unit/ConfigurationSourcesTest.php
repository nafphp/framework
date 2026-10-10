<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Contracts\ConfigurationSourceInterface;
use Naf\Core\App;
use Naf\Core\Config;
use Naf\Core\Container;
use Naf\Support\Plugin;
use ReflectionMethod;
use ReflectionProperty;
use Tests\NafTestCase;

final class ConfigurationSourcesTest extends NafTestCase
{
    public function testLayersPreserveFalsyValuesReplaceListsAndResetToEnvironment(): void
    {
        $_ENV['NAF_LAYER_TEST'] = 'server';

        try {
            $config = new Config(['probe' => 'ENV:NAF_LAYER_TEST', 'list' => ['a', 'b']]);
            $config->overlay('administration', ['probe' => false, 'list' => []]);
            self::assertFalse($config->get('probe'));
            self::assertSame([], $config->get('list'));
            self::assertSame('administration', $config->source('probe'));
            self::assertSame('server', $config->base('probe'));
            $config->overlay('administration', ['probe' => null]);
            self::assertNull($config->get('probe'));
            self::assertSame('administration', $config->source('probe'));
            $config->overlay('administration', []);
            self::assertSame('server', $config->get('probe'));
            self::assertSame('environment', $config->source('probe'));
            self::assertSame('ENV:NAF_LAYER_TEST', $config->raw('probe'));
        } finally {
            unset($_ENV['NAF_LAYER_TEST']);
        }
    }

    public function testSourcesLoadBeforeTheFirstPluginBootstrapReadsConfiguration(): void
    {
        $app       = new App(new Container());
        $source    = tempnam(sys_get_temp_dir(), 'naf-source-');
        $bootstrap = tempnam(sys_get_temp_dir(), 'naf-bootstrap-');
        file_put_contents($source, '<?php return [\\Tests\\Unit\\ProbeConfigurationSource::class];');
        file_put_contents($bootstrap, '<?php $GLOBALS["configurationDuringBoot"] = \\Naf\\config("database:host");');
        $plugin = new Plugin('test/configuration');
        $plugin->addConfigSourceFile($source);
        $plugin->setBootstrapFile($bootstrap);

        try {
            (new ReflectionProperty($app, 'plugins'))->setValue($app, [$plugin]);
            (new ReflectionMethod($app, 'loadServices'))->invoke($app);
            (new ReflectionMethod($app, 'bootPlugins'))->invoke($app);
            self::assertSame('from-administration', $GLOBALS['configurationDuringBoot']);
            self::assertSame('test', $app->container()->get(Config::class)->source('database:host'));
        } finally {
            unlink($source);
            unlink($bootstrap);
            unset($GLOBALS['configurationDuringBoot']);
        }
    }
}

final class ProbeConfigurationSource implements ConfigurationSourceInterface
{
    public function name(): string
    {
        return 'test';
    }

    public function load(string $basePath): array
    {
        return ['database:host' => 'from-administration'];
    }
}
