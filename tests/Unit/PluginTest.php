<?php

namespace Tests\Unit;

use Naf\Support\Plugin;
use Tests\NafTestCase;

class PluginTest extends NafTestCase
{
    public function testPluginStoresPathsCorrectly()
    {
        $plugin = new Plugin('test/package');

        $plugin->addConfigPath('/path/to/config.php');
        $plugin->addViewPath('/path/to/views');
        $plugin->addRouteFile('/path/to/routes.php');
        $plugin->addFunctionFile('/path/to/functions.php');
        $plugin->addViewHelperFile('/path/to/view_helpers.php');

        $this->assertSame(['/path/to/config.php'], $plugin->getConfigPaths());
        $this->assertSame(['/path/to/views'], $plugin->getViewPaths());
        $this->assertSame(['/path/to/routes.php'], $plugin->getRouteFiles());
        $this->assertSame(['/path/to/functions.php'], $plugin->getFunctionsFiles());
        $this->assertSame(['/path/to/view_helpers.php'], $plugin->getViewHelpersFiles());
    }

    public function testPluginReportsWhetherItHasBooted()
    {
        $plugin = new Plugin('test/package');

        $this->assertFalse($plugin->isBooted());

        $plugin->boot();

        $this->assertTrue($plugin->isBooted());
    }

    public function testPluginBootsOnlyOnce()
    {
        $plugin = new Plugin('test/package');

        $tmpFile = tempnam(sys_get_temp_dir(), 'plugin_');
        file_put_contents($tmpFile, '<?php $GLOBALS["booted"] = ($GLOBALS["booted"] ?? 0) + 1;');

        try {
            $plugin->setBootstrapFile($tmpFile);
            $plugin->boot();
            $plugin->boot();
            $this->assertEquals(1, $GLOBALS['booted']);
        } finally {
            unlink($tmpFile);
        }
    }
}
