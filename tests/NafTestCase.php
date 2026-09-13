<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Naf\Support\Stopwatch;

class NafTestCase extends TestCase
{
    protected function tearDown(): void
    {
        try {
            Stopwatch::stop('app');
        } catch (\RuntimeException) {
            // ignore when stopwatch was not running
        }

        parent::tearDown();
    }

}