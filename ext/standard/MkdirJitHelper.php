<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mkdir() for compiled JIT/AOT modules (#15586, php-in-PHP).
 *
 * SSOT: {@see VmFs::mkdir()} (user wrappers via {@see VmUserStream::tryMkdir}; #25987)
 * + {@see VmStatPath::isDir()} warning parity with {@see mkdir_::execute()}.
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(mkdir)
 */
final class MkdirJitHelper
{
    public static function invokeArgv(string $path, int $mode, bool $recursive): bool
    {
        $alreadyDir = VmStatPath::isDir($path);
        $ok = VmFs::mkdir($path, $mode, $recursive);
        if (!$ok) {
            // php-src filestat.c — recursive mkdir("") → "Invalid path" (#29359).
            if ($alreadyDir) {
                TriggerErrorJitHelper::warning('mkdir(): File exists');
            } elseif ($recursive && '' === $path) {
                TriggerErrorJitHelper::warning('mkdir(): Invalid path');
            } else {
                TriggerErrorJitHelper::warning('mkdir(): No such file or directory');
            }
        }

        return $ok;
    }
}
