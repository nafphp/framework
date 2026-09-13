<?php

declare(strict_types=1);

namespace Naf\Support;

use Naf\Core\App;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Core\Container;

class AppHolder
{
    private static ?App $instance = null;

    public static function get(): App
    {
        if (self::$instance === null) {
            self::$instance = new App(new AutoResolvingContainer(new Container()));
        }
        return self::$instance;
    }

    public static function set(App $app): void
    {
        self::$instance = $app;
    }
}