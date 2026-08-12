<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FinfoFileRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for finfo_file() / finfo::file() via FinfoFileRuntime (#27196, re-#3366).
 *
 * Boxes {@see FinfoFileJitHelper::mimeFromPath} {@see __string__*}|null into `__value__*` string|false
 * (peer {@see \PHPCompiler\ext\standard\JitStrstr}).
 *
 * php-src: ext/fileinfo/fileinfo.c — PHP_FUNCTION(finfo_file) / zim_finfo_file
 */
final class JitFinfoFile
{
    private static int $blockSerial = 0;

    /**
     * @param list<JITVariable> $args finfo_file($finfo, $filename, $flags = 0, $context = null)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_file() expects at least 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokePath(
            $context,
            $args[1],
            'finfo_file',
            1,
            'filename'
        );
    }

    /**
     * @param list<JITVariable> $args finfo::file($filename, …) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::file() expects at least 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokePath(
            $context,
            $args[1],
            'finfo::file',
            0,
            'filename'
        );
    }

    private static function invokePath(
        Context $context,
        JITVariable $pathArg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $id = (string) (++self::$blockSerial);
        $pathStr = JitStringBuiltinArg::lower($context, $pathArg, $function, $argIndex, $paramName);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $pathArg,
            $pathStr,
            VmString::emptyStringArgValueErrorMessageCannot($function, $argIndex, $paramName)
        );
        $raw = FinfoFileRuntime::invoke($context, $pathStr);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'finfo_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'finfo_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'finfo_file_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
