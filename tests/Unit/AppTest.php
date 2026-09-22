<?php

namespace Tests\Unit;

use Naf\Core\App;
use Naf\Core\Container;
use Naf\Core\Dispatcher;
use Naf\Core\Environment;
use Naf\Support\Plugin;
use Naf\Support\Stopwatch;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use RuntimeException;
use Tests\NafTestCase;

class AppTest extends NafTestCase
{
    public function testHasPluginHonoursVersionConstraint()
    {
        $app = new App(new Container());
        $reflection = new ReflectionClass($app);

        $pluginsProp = $reflection->getProperty('plugins');
        $pluginInstance = new Plugin('naf/database');
        $pluginInstance->setVersion('0.1.2');

        $pluginsProp->setValue($app, [
            'naf/database' => $pluginInstance,
        ]);

        $this->assertTrue($app->hasPlugin('naf/database'));
        try {
            $this->assertTrue($app->hasPlugin('naf/database:0.1.2'));
            $this->assertTrue($app->hasPlugin('naf/database:>=0.1.2'));
            $this->assertTrue($app->hasPlugin('naf/database:>0.1.1'));
            $this->assertTrue($app->hasPlugin('naf/database:<=0.1.2'));
            $this->assertFalse($app->hasPlugin('naf/database:>0.1.2'));
            $this->assertFalse($app->hasPlugin('naf/database:<0.1.2'));
            $this->assertFalse($app->hasPlugin('naf/nonexistent:>=1.0.0'));
        } finally {
            Stopwatch::stop('app');
        }
    }

    public function testEveryPluginIsRegisteredBeforeAnyOfThemBoots()
    {
        $app = new App(new Container());

        $bootstrap = tempnam(sys_get_temp_dir(), 'plugin_');
        file_put_contents(
            $bootstrap,
            '<?php $GLOBALS["seenDuringBoot"] = count(\Naf\app()->getPlugins());'
        );

        $first = new Plugin('test/first');
        $first->setBootstrapFile($bootstrap);

        $reflection = new ReflectionClass($app);
        $pluginsProp = $reflection->getProperty('plugins');
        $pluginsProp->setValue($app, [
            'test/first'  => $first,
            'test/second' => new Plugin('test/second'),
            'test/third'  => new Plugin('test/third'),
        ]);

        $bootPlugins = $reflection->getMethod('bootPlugins');

        try {
            $bootPlugins->invoke($app);

            // The very first bootstrap must already see all three plugins.
            // Otherwise config(), which caches itself on first access, would
            // freeze a partial view of the application.
            $this->assertSame(3, $GLOBALS['seenDuringBoot']);
            $this->assertTrue($first->isBooted());
        } finally {
            unset($GLOBALS['seenDuringBoot']);
            unlink($bootstrap);
            Stopwatch::stop('app');
        }
    }

    public function testPluginCompletionRunsOnceBeforeHostRoutesInARealBoot(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/boot-phase/run.php');
        exec($command . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame(['first', 'second', 'ready', 'routes'], json_decode(implode("\n", $output), true));
    }

}
