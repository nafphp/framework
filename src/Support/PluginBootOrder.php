<?php

declare(strict_types=1);

namespace Naf\Support;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Computes boot order from package metadata without executing plugin code. */
final class PluginBootOrder
{
    /** @param array<string, string> $paths */
    public static function fromPaths(array $paths, array $preferred = []): array
    {
        $manifests = [];
        foreach ($paths as $package => $path) {
            $file = $path . '/composer.json';
            if (!is_readable($file)) {
                throw new RuntimeException(sprintf('Cannot read plugin manifest for "%s": %s', $package, $file));
            }

            try {
                $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Invalid plugin manifest for "%s": %s', $package, $file), 0, $exception);
            }
            if (!is_array($manifest)) {
                throw new InvalidArgumentException(sprintf('Plugin manifest for "%s" must be an object.', $package));
            }
            $manifests[$package] = $manifest;
        }

        return self::resolve($manifests, $preferred);
    }

    /**
     * Composer require describes availability, not whether another bootstrap must run first.
     * Only extra.naf.boot.before/after and explicit host ordering create graph edges.
     *
     * @param array<string, array> $manifests
     * @param list<string> $preferred Optional host priority; listed packages keep their relative order.
     * @return array<string, array{after: array<string, list<string>>, ignored: list<string>}>
     */
    public static function resolve(array $manifests, array $preferred = []): array
    {
        self::validateNames($preferred, 'Host plugins.php');
        $preferred    = array_values(array_unique(array_intersect($preferred, array_keys($manifests))));
        $dependencies = array_fill_keys(array_keys($manifests), []);
        $ignored      = $dependencies;

        foreach ($manifests as $package => $manifest) {
            $extra = $manifest['extra'] ?? [];
            $naf   = is_array($extra) ? ($extra['naf'] ?? []) : [];
            if (!is_array($naf)) {
                throw new InvalidArgumentException($package . ' extra.naf must be an object.');
            }
            $boot = array_key_exists('boot', $naf) ? $naf['boot'] : [];
            if (!is_array($boot) || array_diff(array_keys($boot), ['before', 'after']) !== []) {
                throw new InvalidArgumentException(sprintf('%s extra.naf.boot must contain only before/after lists.', $package));
            }
            foreach (['before', 'after'] as $relation) {
                $targets = $boot[$relation] ?? [];
                $source  = sprintf('%s extra.naf.boot.%s', $package, $relation);
                if (array_key_exists($relation, $boot) && $boot[$relation] === null) {
                    throw new InvalidArgumentException($source . ' must be a list of package names.');
                }
                self::validateNames($targets, $source);
                foreach (array_unique($targets) as $target) {
                    if (!array_key_exists($target, $manifests)) {
                        $ignored[$package][] = sprintf('%s %s (not installed)', $relation, $target);
                        continue;
                    }
                    [$before, $after]                = $relation === 'before' ? [$package, $target] : [$target, $package];
                    $dependencies[$after][$before][] = $source;
                }
            }
        }

        foreach ($preferred as $position => $package) {
            if ($position > 0) {
                $dependencies[$package][$preferred[$position - 1]][] = 'host plugins.php';
            }
        }

        $remaining = $dependencies;
        $rank      = array_flip($preferred);
        $plan      = [];
        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn(array $needs): bool => $needs === []));
            if ($ready === []) {
                $cycle   = self::cycle($remaining);
                $reasons = [];
                for ($i = 1; $i < count($cycle); ++$i) {
                    $reasons = [...$reasons, ...$remaining[$cycle[$i]][$cycle[$i - 1]]];
                }
                throw new RuntimeException('Plugin boot cycle: ' . implode(' -> ', $cycle)
                    . '. Declared by: ' . implode('; ', array_unique($reasons)) . '.');
            }
            usort($ready, static fn(string $a, string $b): int
                => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));
            $package = $ready[0];
            ksort($dependencies[$package], SORT_STRING);
            foreach ($dependencies[$package] as &$sources) {
                sort($sources, SORT_STRING);
            }
            unset($sources);
            sort($ignored[$package], SORT_STRING);
            $plan[$package] = ['after' => $dependencies[$package], 'ignored' => $ignored[$package]];
            unset($remaining[$package]);
            foreach ($remaining as &$needs) {
                unset($needs[$package]);
            }
            unset($needs);
        }

        return $plan;
    }

    private static function validateNames(mixed $names, string $source): void
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw new InvalidArgumentException($source . ' must be a list of package names.');
        }
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('{^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$}D', $name) !== 1) {
                throw new InvalidArgumentException($source . ' contains an invalid package name.');
            }
        }
    }

    /** Find an actual directed cycle, excluding packages merely blocked behind it. */
    private static function cycle(array $dependencies): array
    {
        $outgoing = array_fill_keys(array_keys($dependencies), []);
        foreach ($dependencies as $after => $needs) {
            foreach (array_keys($needs) as $before) {
                $outgoing[$before][] = $after;
            }
        }
        ksort($outgoing, SORT_STRING);
        foreach ($outgoing as &$targets) {
            sort($targets, SORT_STRING);
        }
        unset($targets);
        $finished = [];
        $path     = [];
        $visit    = static function (string $package) use (&$visit, &$path, &$finished, $outgoing): ?array {
            $start = array_search($package, $path, true);
            if ($start !== false) {
                return [...array_slice($path, $start), $package];
            }
            if (isset($finished[$package])) {
                return null;
            }
            $path[] = $package;
            foreach ($outgoing[$package] as $next) {
                if (null !== $cycle = $visit($next)) {
                    return $cycle;
                }
            }
            array_pop($path);
            $finished[$package] = true;

            return null;
        };
        foreach (array_keys($outgoing) as $package) {
            if (null !== $cycle = $visit($package)) {
                return $cycle;
            }
        }

        throw new RuntimeException('Unable to explain plugin boot cycle.');
    }
}
