<?php
declare(strict_types=1);

namespace Naf\Support;

class Stopwatch
{

    private static array $data = [];

    public static function start(?string $id = null): void
    {
        if (null === $id) {
            $id = time() . uniqid();
        }

        if (isset(static::$data[$id])) {
            throw new \RuntimeException('Stopwatch already started.');
        }

        static::$data[$id] = microtime(true);
    }

    public static function stop(?string $id = null): string
    {
        $startTime = null;

        if ($id && isset(static::$data[$id])) {
            $startTime = static::$data[$id];
            unset(static::$data[$id]);
        }

        if (null === $id && count(static::$data) === 1) {
            $startTime = array_pop(static::$data);
        }

        if (!$startTime) {
            throw new \RuntimeException('Stopwatch not started.');
        }

        $endTime = microtime(true);

        return static::format($endTime - $startTime);
    }

    private static function format(float $number): string
    {
        return number_format($number, 5);
    }

}