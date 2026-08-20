<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ChownRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for chgrp()/lchgrp() via __compiler_chgrp (php-in-PHP ChownRuntime; #30167).
 *
 * NestedJIT leaf: libc chown(2)/fchownat(2) so compiling ChownJitHelper → VmFs → `@\chgrp`
 * does not hit an unbound Internal (#32466). Peer: JitChown / StringRename (#29141).
 */
final class JitChgrp
{
    private const AT_FDCWD = -100;

    private const AT_SYMLINK_NOFOLLOW = 0x100;

    /** @return Value true when __compiler_chgrp returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $groupVal, bool $lchgrp): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $pathStr, $groupVal, $lchgrp);
        }

        ChownRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $flag = $i32->constInt($lchgrp ? 1 : 0, false);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_chgrp'),
            $pathStr,
            $groupVal,
            $flag
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }

    /** @return Value i1 — true when libc chown succeeds for gid */
    public static function invokeNestedLeaf(
        Context $context,
        Value $pathStr,
        Value $groupVal,
        bool $lchgrp
    ): Value {
        LibcExtern::ensureChownFamily($context);
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $minusOne = $i32->constInt(-1, true);

        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $gid64 = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $groupVal
        );
        $gid = $context->builder->truncOrBitCast($gid64, $i32);

        if ($lchgrp) {
            $rc = $context->builder->call(
                $context->lookupFunction('fchownat'),
                $i32->constInt(self::AT_FDCWD, true),
                $pathPtr,
                $minusOne,
                $gid,
                $i32->constInt(self::AT_SYMLINK_NOFOLLOW, false)
            );
        } else {
            $rc = $context->builder->call(
                $context->lookupFunction('chown'),
                $pathPtr,
                $minusOne,
                $gid
            );
        }

        return $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
    }
}
