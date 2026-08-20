<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * chown()/chgrp()/lchown()/lchgrp() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * NestedJIT must not call {@see VmFs::chown()} / PHP {@see chown()} on int ids — that
 * re-enters {@see __compiler_chown} under thin AOT (#32466). Numeric ids use whitelisted
 * {@see chown()} / {@see lchown()} leaves (peer {@see RenameJitHelper} @rename →
 * {@see StringRename::invokeNestedLeaf} #29141). String owners still use {@see VmFs}.
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownJitHelper
{
    /** @return 0|1 ABI for __compiler_chown */
    public static function chownArgv(string $path, Variable $user, int $lchown): int
    {
        $function = 0 !== $lchown ? 'lchown' : 'chown';
        $user = $user->resolveIndirect();
        if (Variable::TYPE_STRING === $user->type) {
            $ok = 0 !== $lchown ? VmFs::lchown($path, $user) : VmFs::chown($path, $user);
        } else {
            $uid = self::resolvePositiveId($user);
            if (null === $uid) {
                return 0;
            }
            $ok = 0 !== $lchown && \function_exists('lchown')
                ? @\lchown($path, $uid)
                : @\chown($path, $uid);
        }
        if (!$ok) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');
        }

        return $ok ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_chgrp */
    public static function chgrpArgv(string $path, Variable $group, int $lchgrp): int
    {
        $function = 0 !== $lchgrp ? 'lchgrp' : 'chgrp';
        $group = $group->resolveIndirect();
        if (Variable::TYPE_STRING === $group->type) {
            $ok = 0 !== $lchgrp ? VmFs::lchgrp($path, $group) : VmFs::chgrp($path, $group);
        } else {
            $gid = self::resolvePositiveId($group);
            if (null === $gid) {
                return 0;
            }
            $ok = 0 !== $lchgrp && \function_exists('lchgrp')
                ? @\lchgrp($path, $gid)
                : @\chgrp($path, $gid);
        }
        if (!$ok) {
            TriggerErrorJitHelper::warning($function.'(): No such file or directory');
        }

        return $ok ? 1 : 0;
    }

    /** @return int|null uid/gid when $var is a non-negative int/long */
    private static function resolvePositiveId(Variable $var): ?int
    {
        if (Variable::TYPE_INTEGER !== $var->type) {
            return null;
        }
        $id = $var->toInt();

        return $id >= 0 ? $id : null;
    }
}
