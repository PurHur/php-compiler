<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for chmod() via libc chmod(2). */
final class JitChmod
{
    /** @return Value
     * true when chmod(2) returns 0 */
    public static function invoke(Context $context, Value $pathStr, Value $modeI32): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('chmod'),
            $pathPtr,
            $modeI32
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
