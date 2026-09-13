<?php

namespace Tests\Unit;

use Fixtures\Enums\CustomEnvironment;
use Naf\Core\Environment;
use Tests\NafTestCase;
use function Naf\app;
use function Naf\env;

class EnvironmentTest extends NafTestCase
{

    public function testHelperFunction()
    {
        app()->container()->set(Environment::class, fn() => CustomEnvironment::TEST);
        $this->assertSame(CustomEnvironment::TEST, env());
    }

}