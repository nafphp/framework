<?php

namespace Tests\Unit;

use Naf\Core\Log;
use Psr\Log\LoggerInterface;
use Tests\NafTestCase;
use function Naf\app;

class LogTest extends NafTestCase
{

    private const TEST_LOG_FILE = '/tmp/log/test.log';

    public function setUp(): void
    {
        if (file_exists(static::TEST_LOG_FILE)) {
            unlink(static::TEST_LOG_FILE);
        }
        if (!is_dir(dirname(static::TEST_LOG_FILE))) {
            mkdir(dirname(static::TEST_LOG_FILE));
        }

        touch(static::TEST_LOG_FILE);
        chmod(static::TEST_LOG_FILE, 0775);
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(static::TEST_LOG_FILE)) {
            unlink(static::TEST_LOG_FILE);
        }
    }

    public function testGenericLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->log('debug', 'Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testGenericLogWithVariables()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->log('debug', 'Test Message with {variable}', ['variable' => 'text']);
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message with text', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testDebugLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->debug('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testInfoLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->info('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testNoticeLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->notice('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testWarningLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->warning('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testErrorLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->error('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testCriticalLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->critical('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testAlertLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->alert('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testEmergencyLog()
    {
        $log = new Log(static::TEST_LOG_FILE);
        $log->emergency('Test Message');
        $this->assertFileExists(static::TEST_LOG_FILE);
        $this->assertStringContainsString('Test Message', file_get_contents(static::TEST_LOG_FILE));
    }

    public function testHelperFunction()
    {
        $log = new Log(static::TEST_LOG_FILE);
        app()->container()->set(LoggerInterface::class, $log);

        \Naf\log()->debug('Helper Test');
        $this->assertStringContainsString('Helper Test', file_get_contents(static::TEST_LOG_FILE));
    }
    
}