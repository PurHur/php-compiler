<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chown()/chgrp()/lchown()/lchgrp() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * Int uid/gid ABI — NestedJIT must not take {@see Variable} (type tags diverge from
 * VM TYPE_INTEGER under value-box coerce; #32466). Call `@\chown` / `@\chgrp` so
 * NestedJIT hits {@see JitChown} / {@see JitChgrp} libc leaves (peer RenameJitHelper).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownJitHelper
{
    /** @return 0|1 ABI for __compiler_chown */
    public static function chownArgv(string $path, int $uid, int $lchown): int
    {
        $function = 0 !== $lchown ? 'lchown' : 'chown';
        if ($uid < 0) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');

            return 0;
        }
        $ok = 0 !== $lchown ? @\lchown($path, $uid) : @\chown($path, $uid);
        if (!$ok) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');
        }

        return $ok ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_chgrp */
    public static function chgrpArgv(string $path, int $gid, int $lchgrp): int
    {
        $function = 0 !== $lchgrp ? 'lchgrp' : 'chgrp';
        if ($gid < 0) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');

            return 0;
        }
        $ok = 0 !== $lchgrp ? @\lchgrp($path, $gid) : @\chgrp($path, $gid);
        if (!$ok) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');
        }

        return $ok ? 1 : 0;
    }
}
