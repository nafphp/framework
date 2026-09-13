<?php

namespace Tests\Unit;

use Naf\Support\Guard;
use Tests\NafTestCase;

class GuardTest extends NafTestCase
{

    private Guard $guard;

    protected function setUp(): void
    {
        $guard = new Guard();

        $guard->register('safeOutput', function($value) {
            if (is_array($value)) {
                return array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $value);
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        });

        $guard->register('safePath', function ($path) {

            if (
                $path === '' ||
                str_contains($path, '..') ||
                str_starts_with($path, '/') ||
                str_contains($path, '://') ||
                !preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)
            ) {
                throw new \InvalidArgumentException('Insecure path detected! Please find another solution.');
            }

            return $path;

        });

        $this->guard = $guard;
    }

    public function testSafeOutputWithRegularString()
    {
        $output = $this->guard->safeOutput('test');
        $this->assertSame('test', $output);
    }

    public function testSafeOutputWithRegularArray()
    {
        $output = $this->guard->safeOutput(['test']);
        $this->assertSame(['test'], $output);
    }

    public function testSafeOutputWithMaliciousString()
    {
        $output = $this->guard->safeOutput('<script>Test</script>');
        $this->assertSame('&lt;script&gt;Test&lt;/script&gt;', $output);
    }

    public function testSafeOutputWithMaliciousArray()
    {
        $output = $this->guard->safeOutput(['<script>Test</script>']);
        $this->assertSame(['&lt;script&gt;Test&lt;/script&gt;'], $output);
    }

    public function testSafePathSuccess()
    {
        $output = $this->guard->safePath('views/valid/file.php');
        $this->assertSame('views/valid/file.php', $output);
    }

    public function testSafePathWithRoot()
    {
        $this->expectException(\InvalidArgumentException::class);

        $output = $this->guard->safePath('/views/valid/file.php');
    }

    public function testSafePathWithTraversingPath()
    {
        $this->expectException(\InvalidArgumentException::class);

        $maliciousPath = '/../../../home/user/.ssh/id_rsa';
        $this->guard->safePath($maliciousPath);
    }

    public function testSafePathWithInvalidChars()
    {
        $this->expectException(\InvalidArgumentException::class);

        $maliciousPath = '/<script></script>';
        $this->guard->safePath($maliciousPath);
    }

    public function testHelperFunction()
    {
        $this->assertInstanceOf(Guard::class, \Naf\guard() );;
    }

}