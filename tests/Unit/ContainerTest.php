<?php

namespace Tests\Unit;

use Naf\Core\Container;
use Naf\Exceptions\ContainerException;
use Naf\Exceptions\ServiceNotFoundException;
use Tests\NafTestCase;
use function Naf\app;

class ContainerTest extends NafTestCase {

    public function testContainerInternals()
    {
        $container = new Container();

        $container->set('testService', function() {
            return 'test';
        });

        $this->assertTrue($container->has('testService'));
        $this->assertSame('test', $container->get('testService'));

    }

    public function testContainerExceptionForServiceNotFound()
    {
        $this->expectException(ServiceNotFoundException::class);
        $container = new Container();

        $container->set('testService', function() {
            return 'test';
        });

        $container->get('missingTestService');
    }

    public function testContainerExceptionWhileCreatingService()
    {
        $this->expectException(ContainerException::class);
        $container = new Container();

        $container->set('testService', function() {
            throw new \Exception('test');
        });

        $container->get('testService');
    }

    public function testContainerReset()
    {
        $container = new Container();

        $container->set('testService', function() {
            return 'test';
        });

        $container->set('secondTestService', function() {
            return 'test';
        });
        
        $this->assertTrue($container->has('testService'));

        $container->reset('testService');
        
        $this->assertFalse($container->has('testService'));
        $this->assertTrue($container->has('secondTestService'));
    }

    public function testHelperFunction()
    {
        app()->container()->set('test', function () { return 'test'; });
        $this->assertSame('test', app()->container()->get('test'));
    }

}