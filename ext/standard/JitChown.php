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
 * LLVM lowering for chown()/lchown() via __compiler_chown (php-in-PHP ChownRuntime; #30167).
 *
 * NestedJIT leaf: libc chown(2)/fchownat(2) so compiling ChownJitHelper → VmFs → `@\chown`
 * does not hit an unbound Internal (#32466). Peer: StringRename::invokeNestedLeaf (#29141).
 */
final class JitChown
{
    private const AT_FDCWD = -100;

    private const AT_SYMLINK_NOFOLLOW = 0x100;

    /** @return Value true when __compiler_chown returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $userVal, bool $lchown): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $pathStr, $userVal, $lchown);
        }

        ChownRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $flag = $i32->constInt($lchown ? 1 : 0, false);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_chown'),
            $pathStr,
            $userVal,
            $flag
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }

    /** @return Value i1 — true when libc chown succeeds */
    public static function invokeNestedLeaf(
        Context $context,
        Value $pathStr,
        Value $userVal,
        bool $lchown
    ): Value {
        LibcExtern::ensureChownFamily($context);
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $minusOne = $i32->constInt(-1, true);

        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $uid64 = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $userVal
        );
        $uid = $context->builder->truncOrBitCast($uid64, $i32);

        if ($lchown) {
            $rc = $context->builder->call(
                $context->lookupFunction('fchownat'),
                $i32->constInt(self::AT_FDCWD, true),
                $pathPtr,
                $uid,
                $minusOne,
                $i32->constInt(self::AT_SYMLINK_NOFOLLOW, false)
            );
        } else {
            $rc = $context->builder->call(
                $context->lookupFunction('chown'),
                $pathPtr,
                $uid,
                $minusOne
            );
        }

        return $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
    }
}
