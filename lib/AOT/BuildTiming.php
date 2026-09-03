<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Optional phase wall-time report for `phpc build` / `bin/compile.php` (#36387).
 *
 * Enable with `PHP_COMPILER_BUILD_TIMING=1` (human) or `=json` (one JSON object on stderr).
 */
final class BuildTiming
{
    private static bool $enabled = false;

    private static bool $json = false;

    /** @var array<string, int> phase => hrtime start */
    private static array $open = [];

    /** @var array<string, float> phase => ms */
    private static array $ms = [];

    private static float $bootHr = 0.0;

    public static function boot(): void
    {
        $flag = Config::getenv('PHP_COMPILER_BUILD_TIMING');
        if (false === $flag || '' === $flag || '0' === $flag) {
            self::$enabled = false;

            return;
        }
        self::$enabled = true;
        self::$json = 'json' === strtolower((string) $flag);
        self::$open = [];
        self::$ms = [];
        self::$bootHr = (float) hrtime(true);
        self::mark('boot');
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function mark(string $phase): void
    {
        if (!self::$enabled) {
            return;
        }
        self::$open[$phase] = hrtime(true);
    }

    public static function end(string $phase): void
    {
        if (!self::$enabled || !isset(self::$open[$phase])) {
            return;
        }
        $elapsed = (hrtime(true) - self::$open[$phase]) / 1_000_000;
        unset(self::$open[$phase]);
        self::$ms[$phase] = (self::$ms[$phase] ?? 0.0) + $elapsed;
    }

    public static function note(string $phase, float $ms): void
    {
        if (!self::$enabled) {
            return;
        }
        self::$ms[$phase] = (self::$ms[$phase] ?? 0.0) + $ms;
    }

    public static function finish(?string $path = null): void
    {
        if (!self::$enabled) {
            return;
        }
        foreach (array_keys(self::$open) as $phase) {
            self::end($phase);
        }
        $total = self::$bootHr > 0.0
            ? (hrtime(true) - self::$bootHr) / 1_000_000
            : array_sum(self::$ms);
        $payload = self::$ms;
        $payload['total'] = $total;
        if (null !== $path && '' !== $path) {
            $payload['path'] = $path;
        }

        if (self::$json) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (false !== $json) {
                fwrite(STDERR, $json."\n");
            }
        } else {
            $parts = [];
            foreach ($payload as $k => $v) {
                if (!is_float($v) && !is_int($v)) {
                    continue;
                }
                $parts[] = sprintf('%s=%.1f', $k, (float) $v);
            }
            fwrite(STDERR, 'phpc build timing (ms): '.implode(' ', $parts)."\n");
        }
        self::$enabled = false;
    }
}
