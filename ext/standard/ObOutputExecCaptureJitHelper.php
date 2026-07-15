<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Minimal ob stack for exec()/passthru()/system() stdout capture in user-script AOT (#10492).
 *
 * Avoids array_pop() lowering that nested-JIT misroutes to HashTable::popLast().
 * php-src: ext/standard/output.c + exec.c
 */
final class ObOutputExecCaptureJitHelper
{
    private const MAX_DEPTH = 8;

    private static int $depth = 0;

    /** @var list<string> */
    private static array $buffers = [];

    public static function reset(): void
    {
        self::$depth = 0;
        self::$buffers = [];
    }

    public static function start(): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            return;
        }
        self::$buffers[self::$depth] = '';
        ++self::$depth;
    }

    /** @return int 1 when bytes went direct to stdout (no active buffer) */
    public static function appendString(string $chunk): int
    {
        if ('' === $chunk) {
            return 0;
        }
        if (self::$depth <= 0) {
            echo $chunk;

            return 1;
        }
        $idx = self::$depth - 1;
        self::$buffers[$idx] .= $chunk;

        return 0;
    }

    public static function getClean(): ?string
    {
        if (self::$depth <= 0) {
            return null;
        }
        --self::$depth;

        return self::$buffers[self::$depth];
    }

    public static function getLevel(): int
    {
        return self::$depth;
    }

    /** @return int 1 when an active buffer exists */
    public static function hasActiveBuffer(): int
    {
        return self::$depth > 0 ? 1 : 0;
    }

    public static function getContents(): ?string
    {
        if (self::$depth <= 0) {
            return null;
        }

        return self::$buffers[self::$depth - 1];
    }

    public static function getLength(): int
    {
        if (self::$depth <= 0) {
            return -1;
        }

        return \strlen(self::$buffers[self::$depth - 1]);
    }

    public static function endClean(): int
    {
        if (self::$depth <= 0) {
            return 0;
        }
        --self::$depth;
        self::$buffers[self::$depth] = '';

        return 1;
    }
}
