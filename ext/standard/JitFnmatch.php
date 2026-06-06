<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fnmatch() via libc fnmatch(3) (issue #3189, #6721). */
final class JitFnmatch
{
    public static function invoke(Context $context, Value $pattern, Value $string, Value $flagsI32): Value
    {
        self::ensureExternal($context);
        $map = $context->structFieldMap['__string__'];
        $patternPtr = $context->builder->structGep($pattern, $map['value']);
        $stringPtr = $context->builder->structGep($string, $map['value']);
        $sysFlags = self::phpFlagsToSystem($context, $flagsI32);
        $i32 = $context->getTypeFromString('int32');
        $rc = $context->builder->call(
            $context->lookupFunction('fnmatch'),
            $patternPtr,
            $stringPtr,
            $sysFlags
        );

        return $context->builder->icmp(
            Builder::INT_EQ,
            $rc,
            $i32->constInt(0, false)
        );
    }

    /** Map PHP FNM_* bits to libc fnmatch(3) flags (php-src ext/standard/fnmatch.c). */
    private static function phpFlagsToSystem(Context $context, Value $phpFlags): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $sys = $i32->constInt(0, false);
        /** @var array<int, int> $map */
        $map = [
            VmFnmatch::FNM_NOESCAPE => VmFnmatch::FNM_NOESCAPE,
            VmFnmatch::FNM_PATHNAME => VmFnmatch::FNM_PATHNAME,
            VmFnmatch::FNM_PERIOD => VmFnmatch::FNM_PERIOD,
            VmFnmatch::FNM_CASEFOLD => VmFnmatch::FNM_CASEFOLD,
        ];
        foreach ($map as $phpBit => $sysBit) {
            $masked = $context->builder->and($phpFlags, $i32->constInt($phpBit, false));
            $hasBit = $context->builder->icmp(Builder::INT_NE, $masked, $i32->constInt(0, false));
            $orVal = $context->builder->or($sys, $i32->constInt($sysBit, false));
            $sys = $context->builder->select($hasBit, $orVal, $sys);
        }

        return $sys;
    }

    private static function ensureExternal(Context $context): void
    {
        try {
            $context->lookupFunction('fnmatch');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i32);
            $fn = $context->module->addFunction('fnmatch', $ft);
            $context->registerFunction('fnmatch', $fn);
        }
    }
}
