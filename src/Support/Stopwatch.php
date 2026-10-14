<?php

declare(strict_types=1);

namespace Naf\Support;

use RuntimeException;

/** @internal Framework implementation; use the documented application and plugin APIs. */
class Stopwatch
{
    private static array $data = [];

    public static function start(?string $id = null): void
    {
        if (null === $id) {
            $id = time() . uniqid();
        }

        if (isset(self::$data[$id])) {
            throw new RuntimeException('Stopwatch already started.');
        }

        self::$data[$id] = microtime(true);
    }

    public static function stop(?string $id = null): string
    {
        $startTime = null;

        if ($id && isset(self::$data[$id])) {
            $startTime = self::$data[$id];
            unset(self::$data[$id]);
        }

        if (null === $id && count(self::$data) === 1) {
            $startTime = array_pop(self::$data);
        }

        if (!$startTime) {
            throw new RuntimeException('Stopwatch not started.');
        }

        $endTime = microtime(true);

        return self::format($endTime - $startTime);
    }

    private static function format(float $number): string
    {
        return number_format($number, 5);
    }
}
