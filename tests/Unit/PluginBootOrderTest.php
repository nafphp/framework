<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Naf\Support\PluginBootOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginBootOrderTest extends TestCase
{
    public function testPackageDependenciesDoNotImposeBootstrapOrder(): void
    {
        $packages = [
            'app/board'   => ['extra' => ['naf' => ['boot' => ['after' => ['infra/queue']]]]],
            'z/extension' => [
                'require' => ['app/board' => '^1.0'],
                'extra'   => ['naf' => ['boot' => ['before' => ['app/board']]]],
            ],
            'infra/queue' => ['extra' => ['naf' => ['boot' => ['after' => ['infra/cli']]]]],
            'infra/cli'   => [],
        ];
        $plan = PluginBootOrder::resolve($packages);
        $this->assertSame(['infra/cli', 'infra/queue', 'z/extension', 'app/board'], array_keys($plan));
        $this->assertSame(['z/extension extra.naf.boot.before'], $plan['app/board']['after']['z/extension']);
        $this->assertSame($plan, PluginBootOrder::resolve(array_reverse($packages, true)));
    }

    public function testAbsentOptionalPackagesAreReportedButNeverInstalled(): void
    {
        $plan = PluginBootOrder::resolve([
            'plugin/example' => ['extra' => ['naf' => ['boot' => ['after' => ['optional/cli']]]]],
        ]);
        $this->assertSame(['plugin/example'], array_keys($plan));
        $this->assertSame(['after optional/cli (not installed)'], $plan['plugin/example']['ignored']);
        $this->assertSame([], $plan['plugin/example']['after']);
    }

    public function testHostPreferenceKeepsIndependentPackagesInOrder(): void
    {
        $plan = PluginBootOrder::resolve(
            ['pkg/a' => [], 'pkg/b' => [], 'pkg/c' => []],
            ['pkg/c', 'absent/plugin', 'pkg/b', 'pkg/c'],
        );
        $this->assertSame(['pkg/c', 'pkg/b', 'pkg/a'], array_keys($plan));
        $this->assertSame(['host plugins.php'], $plan['pkg/b']['after']['pkg/c']);
    }

    public function testNewlyReadyPackagesKeepTheirHostPriority(): void
    {
        $plan = PluginBootOrder::resolve([
            'pkg/a' => [],
            'pkg/b' => [],
            'pkg/c' => ['extra' => ['naf' => ['boot' => ['after' => ['pkg/a']]]]],
        ], ['pkg/c']);
        $this->assertSame(['pkg/a', 'pkg/c', 'pkg/b'], array_keys($plan));

        $plan = PluginBootOrder::resolve([
            'pkg/a' => ['extra' => ['naf' => ['boot' => ['after' => ['pkg/z']]]]],
            'pkg/b' => [],
            'pkg/z' => [],
        ], ['pkg/z']);
        $this->assertSame(['pkg/z', 'pkg/a', 'pkg/b'], array_keys($plan));
    }

    public function testHostCannotReverseADeclaredDependency(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('host plugins.php');
        PluginBootOrder::resolve([
            'pkg/a' => [],
            'pkg/b' => ['extra' => ['naf' => ['boot' => ['after' => ['pkg/a']]]]],
        ], ['pkg/b', 'pkg/a']);
    }

    public function testCycleNamesItsEdgesButNotAnUnrelatedBlockedPackage(): void
    {
        try {
            PluginBootOrder::resolve([
                'pkg/a'            => ['extra' => ['naf' => ['boot' => ['before' => ['pkg/b', 'pkg/0-downstream']]]]],
                'pkg/b'            => ['extra' => ['naf' => ['boot' => ['before' => ['pkg/c']]]]],
                'pkg/c'            => ['extra' => ['naf' => ['boot' => ['before' => ['pkg/a']]]]],
                'pkg/0-downstream' => [],
            ]);
            $this->fail('A cycle must fail before boot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pkg/a -> pkg/b -> pkg/c -> pkg/a', $exception->getMessage());
            $this->assertStringContainsString('pkg/c extra.naf.boot.before', $exception->getMessage());
            $this->assertStringNotContainsString('downstream', $exception->getMessage());
        }
    }

    public function testSelfDependencyIsAnError(): void
    {
        $this->expectExceptionMessage('pkg/a -> pkg/a');
        PluginBootOrder::resolve(['pkg/a' => ['extra' => ['naf' => ['boot' => ['after' => ['pkg/a']]]]]]);
    }

    #[DataProvider('invalidBootMetadata')]
    public function testMalformedMetadataNamesThePackage(mixed $boot): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pkg/a extra.naf.boot');
        PluginBootOrder::resolve(['pkg/a' => ['extra' => ['naf' => ['boot' => $boot]]]]);
    }

    public static function invalidBootMetadata(): array
    {
        return [
            [null], ['last'], [false], [['befroe'        => ['pkg/b']]],
            [['after' => 'pkg/b']], [['before'           => null]], [['after' => [1]]],
            [['after' => ['pkg/b' => true]]], [['before' => ['']]], [['after' => ['pkg/*']]],
        ];
    }

    public function testDuplicateRelationsDoNotBlockBoot(): void
    {
        $plan = PluginBootOrder::resolve([
            'pkg/a' => ['extra' => ['naf' => ['boot' => ['before' => ['pkg/b', 'pkg/b']]]]],
            'pkg/b' => ['extra' => ['naf' => ['boot' => ['after' => ['pkg/a']]]]],
        ]);
        $this->assertSame(['pkg/a', 'pkg/b'], array_keys($plan));
    }

    public function testInvalidHostConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Host plugins.php');
        PluginBootOrder::resolve(['pkg/a' => []], ['pkg/a' => true]);
    }

    public function testEmptyInstallationNeedsNoConfiguration(): void
    {
        $this->assertSame([], PluginBootOrder::resolve([]));
    }

    #[DataProvider('subprocessScenarios')]
    public function testRealAppBoot(string $scenario, bool $success): void
    {
        $lines = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/plugin-order/run.php')
            . ' ' . escapeshellarg($scenario) . ' 2>&1', $lines, $status);
        $output = implode("\n", $lines);
        $this->assertSame($success ? 0 : 1, $status, $output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if ($success) {
            $this->assertSame(['test/z-base', 'test/a-consumer'], $result['booted']);
            $this->assertSame('consumer', $result['config']);
            $this->assertSame('host', $result['route']);
            $this->assertSame(2, $result['registered_at_first_boot']);
            $this->assertSame('consumer', $result['host_routes_saw']);
        } else {
            $this->assertSame([], $result['booted'], 'No bootstrap may run before the complete plan is valid.');
            $this->assertStringContainsString($scenario === 'json' ? 'manifest' : ($scenario === 'invalid' ? 'extra.naf.boot' : 'boot cycle'), $result['error']);
        }
    }

    public static function subprocessScenarios(): array
    {
        return [['normal', true], ['manual', false], ['cycle', false], ['invalid', false], ['json', false]];
    }
}
