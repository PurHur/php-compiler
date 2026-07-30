<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php://memory|temp stream state for NestedJIT/AOT (#23777, #25299).
 *
 * Shared by StreamIoJitHelper (fopen) and StreamReadJitHelper (fseek/ftell). Must compile into
 * the same LLVM module once — both StreamIoRuntime and StreamReadRuntime list this file in their
 * helper bundles.
 *
 * php-src: main/streams/memory.c, ext/standard/file.c
 */
final class JitMemoryStreamHelper
{
    private static int $nextId = 0;

    /** @var array<int, true> */
    private static array $open = [];

    /** @var array<int, int> */
    private static array $position = [];

    /** @var array<int, string> */
    private static array $buffer = [];

    /** @var array<int, bool> */
    private static array $seekFailed = [];

    public static function alloc(): int
    {
        $id = ++self::$nextId;
        self::$open[$id] = true;
        self::$position[$id] = 0;
        self::$buffer[$id] = '';
        self::$seekFailed[$id] = false;

        return $id;
    }

    public static function isOpen(int $handle): bool
    {
        return isset(self::$open[$handle]);
    }

    public static function release(int $handle): void
    {
        unset(
            self::$open[$handle],
            self::$position[$handle],
            self::$buffer[$handle],
            self::$seekFailed[$handle],
        );
    }

    /** php-src main/streams/memory.c — php_stream_memory_seek (#25299). */
    public static function seek(int $handle, int $offset, int $whence): int
    {
        if (!isset(self::$open[$handle])) {
            return -1;
        }

        $len = \strlen(self::$buffer[$handle]);
        if (\SEEK_SET === $whence) {
            $pos = $offset;
        } elseif (\SEEK_CUR === $whence) {
            $pos = self::$position[$handle] + $offset;
        } elseif (\SEEK_END === $whence) {
            $pos = $len + $offset;
        } else {
            return -1;
        }
        if ($pos < 0 || $pos > $len) {
            self::$seekFailed[$handle] = true;

            return -1;
        }
        self::$position[$handle] = $pos;
        self::$seekFailed[$handle] = false;

        return 0;
    }

    /**
     * @return int position, or -1 on failure (ABI for __compiler_ftell)
     */
    public static function tellArgv(int $handle): int
    {
        if (!isset(self::$open[$handle])) {
            return -1;
        }
        if (self::$seekFailed[$handle] || self::$position[$handle] > \strlen(self::$buffer[$handle])) {
            return -1;
        }

        return self::$position[$handle];
    }
}
