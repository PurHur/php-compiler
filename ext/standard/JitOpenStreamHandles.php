<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Shared NestedJIT stream-handle registry for AOT when VmFs is an ExternalMethod stub (#23777).
 *
 * StreamIoJitHelper::fopenArgv and StreamLifecycleJitHelper::isResourceArgv must NestedJIT this
 * class into the same module so alloc() and isOpen() share one static table. Without it, fopen
 * returns (int)null === 0 and ++$fh never TypeErrors because there is no live resource.
 *
 * php://memory|temp buffer/seek state lives in {@see JitMemoryStreamHelper} (#25299).
 * php-src: ext/standard/file.c — php_stream resource lifetime
 */
final class JitOpenStreamHandles
{
    public static function isMemoryUri(string $path): bool
    {
        if ('php://memory' === $path) {
            return true;
        }

        return \str_starts_with($path, 'php://temp');
    }

    /** Minimal mode check — enough for NestedJIT fopen of php://memory (#23777). */
    public static function modeLooksValid(string $mode): bool
    {
        if ('' === $mode) {
            return false;
        }
        $base = $mode[0];

        return 'r' === $base || 'w' === $base || 'a' === $base || 'x' === $base || 'c' === $base;
    }

    public static function alloc(): int
    {
        return JitMemoryStreamHelper::alloc();
    }

    public static function isOpen(int $handle): bool
    {
        return JitMemoryStreamHelper::isOpen($handle);
    }

    public static function release(int $handle): void
    {
        JitMemoryStreamHelper::release($handle);
    }
}
