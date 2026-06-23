<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamFilter as StreamFilterBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_filter_* via StreamFilterJitHelper (#9047). */
final class JitStreamFilter
{
    public static function append(Context $context, JITVariable ...$args): Value
    {
        return self::attach($context, '__compiler_stream_filter_append', 'stream_filter_append', ...$args);
    }

    public static function prepend(Context $context, JITVariable ...$args): Value
    {
        return self::attach($context, '__compiler_stream_filter_prepend', 'stream_filter_prepend', ...$args);
    }

    public static function remove(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_filter_remove() requires exactly 1 argument');
        }
        StreamFilterBuiltin::ensureLinked($context);

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_filter_remove() stream_filter'),
            $context->getTypeFromString('int64')
        );
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_stream_filter_remove'),
            $handle
        );
        $i32 = $context->getTypeFromString('int32');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));

        return self::boolBox($context, $isTrue);
    }

    public static function register(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_filter_register() requires exactly 2 arguments');
        }
        StreamFilterBuiltin::ensureLinked($context);

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_stream_filter_register'),
            JitStringBuiltinArg::lower($context, $args[0], 'stream_filter_register', 0, 'filtername'),
            JitStringBuiltinArg::lower($context, $args[1], 'stream_filter_register', 1, 'classname')
        );
        $i32 = $context->getTypeFromString('int32');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));

        return self::boolBox($context, $isTrue);
    }

    private static function attach(Context $context, string $abi, string $functionName, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException($functionName.'() expects 2 to 4 arguments');
        }
        StreamFilterBuiltin::ensureLinked($context);

        $stream = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $functionName.'() stream'),
            $context->getTypeFromString('int64')
        );
        $filterName = JitStringBuiltinArg::lower($context, $args[1], $functionName, 1, 'filtername');
        $i64 = $context->getTypeFromString('int64');
        $readWrite = $i64->constInt(VmStreamFilterChain::READ, false);
        if ($argc >= 3) {
            $readWrite = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], $functionName.'() read_write'),
                $i64
            );
        }

        $handle = $context->builder->call($context->lookupFunction($abi), $stream, $filterName, $readWrite);
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $functionName.'_fail');
        $okBlock = BasicBlockHelper::append($context, $functionName.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $functionName.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $handle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function boolBox(Context $context, Value $isTrue): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $isTrue);

        return $ptr;
    }
}
