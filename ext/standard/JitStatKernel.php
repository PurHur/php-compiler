<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin libc stat(2)/lstat(2)/access(2) for path predicates (#19215).
 *
 * Standalone mode helpers avoid LLVM miscompile when nested into helper TUs (#8555).
 * Keep glibc layout here — not in {@see JitStat} (#9112 shrink).
 * php-src: ext/standard/filestat.c
 */
final class JitStatKernel
{
    /** sizeof(struct stat) on Linux x86_64 glibc */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_mode) on Linux x86_64 glibc */
    private const STAT_MODE_OFFSET = 24;

    /** @return Value i32 — st_mode, or -1 on failure */
    public static function mode(Context $context, Value $pathStr, bool $useLstat): Value
    {
        $statFn = $useLstat ? 'lstat' : 'stat';
        $fn = self::ensureModeStandalone($context, $statFn);

        return $context->builder->call($fn, $pathStr);
    }

    /** @return Value i1 — access(2) succeeds */
    public static function accessOk(Context $context, Value $pathStr, int $mode): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('access'),
            $pathPtr,
            $i32->constInt($mode, false)
        );

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(0, false));
    }

    private static function ensureModeStandalone(Context $context, string $statFn): Value
    {
        $name = '__phpc_jit_stat_mode_kernel_'.$statFn;
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'phpc_stat_mode_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction($statFn),
            $pathPtr,
            $bufPtr
        );
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);
        $bytePtr = $context->builder->gep($bufPtr, $i64->constInt(self::STAT_MODE_OFFSET, false));
        $modePtr = $context->builder->pointerCast($bytePtr, $i32->pointerType(0));
        $mode = $context->builder->load($modePtr);
        $minusOne = $i32->constInt(-1, true);
        $context->builder->returnValue($context->builder->select($failed, $minusOne, $mode));
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }
}
