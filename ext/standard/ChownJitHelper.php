<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * chown()/chgrp()/lchown()/lchgrp() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * SSOT: {@see VmFs::chown()}, {@see VmFs::chgrp()}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownJitHelper
{
    /** @return 0|1 ABI for __compiler_chown */
    public static function chownArgv(string $path, Variable $user, int $lchown): int
    {
        $ok = 0 !== $lchown ? VmFs::lchown($path, $user) : VmFs::chown($path, $user);

        return $ok ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_chgrp */
    public static function chgrpArgv(string $path, Variable $group, int $lchgrp): int
    {
        $ok = 0 !== $lchgrp ? VmFs::lchgrp($path, $group) : VmFs::chgrp($path, $group);

        return $ok ? 1 : 0;
    }
}
