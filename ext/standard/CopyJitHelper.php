<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * copy() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * NestedJIT must not call {@see VmFs::copy()} / PHP {@see copy()} — that re-enters
 * {@see __compiler_copy} and fails under thin AOT (#32466). Use whitelisted
 * {@see file_get_contents()} / {@see file_put_contents()} leaves instead (peer
 * {@see RenameJitHelper} @rename → {@see StringRename::invokeNestedLeaf} #29141).
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyJitHelper
{
    /** @return 0|1 ABI for __compiler_copy */
    public static function copyArgv(string $from, string $to): int
    {
        if (\is_dir($from)) {
            TriggerErrorJitHelper::warning(
                'The first argument to copy() function cannot be a directory'
            );

            return 0;
        }

        $payload = @\file_get_contents($from);
        if (false === $payload) {
            return 0;
        }

        return false !== @\file_put_contents($to, $payload) ? 1 : 0;
    }
}
