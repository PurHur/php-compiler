<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for getcwd() (#10451, #25541, #26928).
 *
 * Thin AOT NestedJIT of GetcwdJitHelper left VmGetcwdNative as an external stub and
 * segfaulted after c:main_before_php (#26928 — peer getmypid #26944 / sys_get_temp_dir #26929).
 * Emit libc realpath(".") → __string__ (same shape as {@see SysGetTempDirRuntime}).
 * VM SSOT stays {@see \PHPCompiler\ext\standard\VmGetcwdNative}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(getcwd)
 */
final class GetcwdJit
{
    private const PATH_MAX = 4096;

    public static function invoke(Context $context): Value
    {
        LibcExtern::register($context);
        self::ensureLibc($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $nullPtr = $i8p->constNull();

        $buf = BasicBlockHelper::entryAlloca(
            $context,
            $i8->arrayType(self::PATH_MAX),
            'getcwd_realpath_buf'
        );
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $dot = $context->builder->pointerCast($context->constantFromString('.'), $i8p);
        $resolved = $context->builder->call(
            $context->lookupFunction('realpath'),
            $dot,
            $bufPtr
        );

        $failBb = BasicBlockHelper::append($context, 'getcwd_realpath_fail');
        $okBb = BasicBlockHelper::append($context, 'getcwd_realpath_ok');
        $doneBb = BasicBlockHelper::append($context, 'getcwd_realpath_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $nullPtr);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $charPtr)
        );
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $resolved);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($resolved, $charPtr)
        );
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'getcwd_str');
        $phi->addIncoming($empty, $failEnd);
        $phi->addIncoming($str, $okEnd);

        return $phi;
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        foreach ([
            ['realpath', $i8p, [$i8p, $i8p]],
            ['strlen', $i64, [$i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }
}
