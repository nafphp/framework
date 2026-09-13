<?php

namespace Tests\Unit;

use Naf\Core\Route;
use Naf\Exceptions\RouteNotFoundException;
use Tests\NafTestCase;
use function Naf\app;

class RouteTest extends NafTestCase
{

    public function testShouldAddRoute()
    {
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; });
        $result = $route->find('/test', 'GET');
        $this->assertTrue(is_callable($result['action']));
    }
    
    public function testShouldReturnNotFoundException()
    {
        $this->expectException(RouteNotFoundException::class);
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; });
        $route->find('/other', 'GET');
    }

    public function testShouldIgnoreWrongMethod()
    {
        $this->expectException(RouteNotFoundException::class);
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; });
        $route->find('/test', 'POST');
    }

    public function testShouldThrowExceptionForMissingName()
    {
        $this->expectException(\LogicException::class);
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; });
        $route->add('GET', '/test2', function() { return 'test'; });
    }

    public function testShouldAllowMultipleRoutes()
    {
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; }, 'name');
        $route->add('GET', '/test2', function() { return 'test'; }, 'name2');
        $this->assertIsCallable($route->find('/test', 'GET')['action']);
        $this->assertIsCallable($route->find('/test2', 'GET')['action']);
    }

    public function testShouldReturnRouteByName()
    {
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; }, 'testname');
        $this->assertSame('/test', $route->url('testname'));
    }

    public function testShouldReturnRouteByNameWithParams()
    {
        $route = new Route();
        $route->add('GET', '/test/{id}', function($id) { return $id; }, 'testname');
        $this->assertSame('/test/1', $route->url('testname', ['id' => 1]));
    }

    public function testUrlShouldThrowExceptionWhenRouteNotFound()
    {
        $this->expectException(RouteNotFoundException::class);
        $route = new Route();
        $route->add('GET', '/test', function() { return 'test'; }, 'testname');
        $route->url('wrong_testname');
    }

    public function testHelperFunction()
    {
        \Naf\route()->add('GET', '/test', function() { return 'test'; }, 'test');

        $this->assertSame('/test', \Naf\route('test'));
    }


    public function testShouldTreatLiteralPathAsLiteral()
    {
        $route = new Route();
        $route->add('GET', '/.well-known/jwks.json', function() { return 'keys'; }, 'jwks');

        $this->assertIsArray($route->find('/.well-known/jwks.json', 'GET'));

        // A dot is a dot. Without escaping it matches any character, and this
        // route would answer for paths nobody registered.
        foreach (['/Xwell-known/jwks.json', '/.well-known/jwksXjson'] as $uri) {
            try {
                $route->find($uri, 'GET');
                $this->fail('Matched a path that was never registered: ' . $uri);
            } catch (RouteNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testShouldStillMatchPlaceholdersNextToMetacharacters()
    {
        $route = new Route();
        $route->add('GET', '/files/{name}.json', function() { return 'file'; }, 'file');

        $result = $route->find('/files/report.json', 'GET');

        $this->assertSame(['name' => 'report'], $result['params']);

        $this->expectException(RouteNotFoundException::class);
        $route->find('/files/reportXjson', 'GET');
    }

    public function testShouldNotLetAPathSmuggleInAnExpression()
    {
        $route = new Route();
        $route->add('GET', '/search/(.*)', function() { return 'search'; }, 'search');

        $this->assertIsArray($route->find('/search/(.*)', 'GET'));

        $this->expectException(RouteNotFoundException::class);
        $route->find('/search/anything-at-all', 'GET');
    }
}
