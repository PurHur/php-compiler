<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for rename() — libc rename(2) on user-script AOT, else RenameJitHelper (#16734).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringRename;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitRename
{
    /** @return Value */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            return self::invokeLibc($context, $fromStr, $toStr);
        }

        return StringRename::invoke($context, $fromStr, $toStr);
    }

    private static function invokeLibc(Context $context, Value $fromStr, Value $toStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $fromPtr = $context->builder->structGep($fromStr, $map['value']);
        $toPtr = $context->builder->structGep($toStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('rename'),
            $fromPtr,
            $toPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
