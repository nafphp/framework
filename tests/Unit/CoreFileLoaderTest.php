<?php

namespace Tests\Unit;

use Naf\Support\CoreFileLoader;
use Tests\NafTestCase;

class CoreFileLoaderTest extends NafTestCase
{
    private const string LEGACY    = BASE_PATH . '/plugins/legacy-layout';
    private const string MODERN    = BASE_PATH . '/plugins/modern-layout';
    private const string BARE      = BASE_PATH . '/plugins/bare-layout';
    private const string COLLISION = BASE_PATH . '/plugins/collision-layout';

    public function testFileResolvesTheFirstExistingCandidate()
    {
        $this->assertSame(
            self::LEGACY . '/src/config.php',
            CoreFileLoader::file(self::LEGACY, CoreFileLoader::CONFIG_FILES)
        );

        $this->assertSame(
            self::MODERN . '/app/config.php',
            CoreFileLoader::file(self::MODERN, CoreFileLoader::CONFIG_FILES)
        );
    }

    public function testFirstCandidateWinsWhenBothSpellingsExist()
    {
        $this->assertSame(
            self::COLLISION . '/app/config.php',
            CoreFileLoader::file(self::COLLISION, CoreFileLoader::CONFIG_FILES)
        );

        $this->assertSame(
            self::COLLISION . '/app/routes.php',
            CoreFileLoader::file(self::COLLISION, CoreFileLoader::ROUTE_FILES)
        );

        // Views deliberately keep src/views in front, so an installed plugin
        // that already shipped one is not silently repointed.
        $this->assertSame(
            self::COLLISION . '/src/views',
            CoreFileLoader::directory(self::COLLISION, CoreFileLoader::VIEW_PATHS)
        );
    }

    public function testCollidingLayoutRegistersEachResourceOnlyOnce()
    {
        $plugin = CoreFileLoader::createPlugin('test/collision', self::COLLISION);

        $this->assertSame([self::COLLISION . '/app/config.php'], $plugin->getConfigPaths());
        $this->assertSame([self::COLLISION . '/app/routes.php'], $plugin->getRouteFiles());
        $this->assertSame([self::COLLISION . '/src/views'], $plugin->getViewPaths());
    }

    public function testFileReturnsNullWhenNoCandidateExists()
    {
        $this->assertNull(CoreFileLoader::file(self::BARE, CoreFileLoader::CONFIG_FILES));
    }

    public function testFileReturnsNullForAnUnknownRoot()
    {
        $this->assertNull(CoreFileLoader::file(null, CoreFileLoader::CONFIG_FILES));
        $this->assertNull(CoreFileLoader::file('  ', CoreFileLoader::CONFIG_FILES));
    }

    public function testDirectoryAcceptsBothViewLayouts()
    {
        $this->assertSame(
            self::LEGACY . '/src/views',
            CoreFileLoader::directory(self::LEGACY, CoreFileLoader::VIEW_PATHS)
        );

        $this->assertSame(
            self::MODERN . '/views',
            CoreFileLoader::directory(self::MODERN, CoreFileLoader::VIEW_PATHS)
        );
    }

    public function testDirectoryIgnoresFilesAndFileIgnoresDirectories()
    {
        $this->assertNull(CoreFileLoader::directory(self::LEGACY, ['src/config.php']));
        $this->assertNull(CoreFileLoader::file(self::LEGACY, ['src/views']));
    }

    public function testPluginIsBuiltFromTheLegacyLayout()
    {
        $plugin = CoreFileLoader::createPlugin('test/legacy', self::LEGACY);

        $this->assertSame([self::LEGACY . '/src/config.php'], $plugin->getConfigPaths());
        $this->assertSame([self::LEGACY . '/src/routes.php'], $plugin->getRouteFiles());
        $this->assertSame([self::LEGACY . '/src/views'], $plugin->getViewPaths());
        $this->assertSame([self::LEGACY . '/src/functions.php'], $plugin->getFunctionsFiles());
        $this->assertSame([self::LEGACY . '/src/view_helpers.php'], $plugin->getViewHelpersFiles());
        $this->assertSame(self::LEGACY . '/bootstrap.php', $plugin->getBootstrapFile());
    }

    public function testPluginIsBuiltFromTheModernLayout()
    {
        $plugin = CoreFileLoader::createPlugin('test/modern', self::MODERN);

        $this->assertSame([self::MODERN . '/app/config.php'], $plugin->getConfigPaths());
        $this->assertSame([self::MODERN . '/app/routes.php'], $plugin->getRouteFiles());
        $this->assertSame([self::MODERN . '/views'], $plugin->getViewPaths());
    }

    public function testPluginRegistersNoDeadPaths()
    {
        $plugin = CoreFileLoader::createPlugin('test/bare', self::BARE);

        $this->assertSame([], $plugin->getConfigPaths());
        $this->assertSame([], $plugin->getRouteFiles());
        $this->assertSame([], $plugin->getViewPaths());
        $this->assertSame([], $plugin->getFunctionsFiles());
        $this->assertSame([], $plugin->getViewHelpersFiles());
        $this->assertNull($plugin->getBootstrapFile());
    }

    public function testModernPluginRegistersNoViewHelpersWhenAbsent()
    {
        $plugin = CoreFileLoader::createPlugin('test/modern', self::MODERN);

        $this->assertSame([], $plugin->getViewHelpersFiles());
    }

}
