<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamFilter as StreamFilterBuiltin;
use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitResourceArg;
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
        JitResourceArg::rejectEnumCaseOperand($context, $args[0], 'stream_filter_remove', 0, 'stream_filter');
        if (JITVariable::TYPE_NULL === $args[0]->type) {
            JitResourceArg::emitResourceTypeErrorAndAbort(
                $context,
                'stream_filter_remove',
                0,
                'stream_filter',
                'null'
            );

            return self::boolBox($context, $context->getTypeFromString('int1')->constInt(0, false));
        }

        StreamFilterBuiltin::ensureLinked($context);
        StreamLifecycleRuntime::ensureLinked($context);

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_filter_remove() stream_filter'),
            $context->getTypeFromString('int64')
        );
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $filterBase = $i64->constInt(StreamFilterJitHelper::HANDLE_BASE, false);

        $isValidFilter = $context->builder->call(
            $context->lookupFunction('__compiler_is_stream_filter_resource'),
            $handle
        );
        $validBlock = BasicBlockHelper::append($context, 'stream_filter_remove_valid');
        $invalidBlock = BasicBlockHelper::append($context, 'stream_filter_remove_invalid');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $isValidFilter, $zeroI32),
            $validBlock,
            $invalidBlock
        );

        $context->builder->positionAtEnd($validBlock);
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_stream_filter_remove'),
            $handle
        );
        $isTrue = $context->builder->icmp(Builder::INT_NE, $ok, $zeroI32);
        $validResult = self::boolBox($context, $isTrue);
        $validEnd = $context->builder->getInsertBlock();
        $doneBlock = BasicBlockHelper::append($context, 'stream_filter_remove_done');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($invalidBlock);
        $inFilterRange = $context->builder->icmp(Builder::INT_SGE, $handle, $filterBase);
        $falseBlock = BasicBlockHelper::append($context, 'stream_filter_remove_false');
        $wrongResourceBlock = BasicBlockHelper::append($context, 'stream_filter_remove_wrong_resource');
        $context->builder->branchIf($inFilterRange, $falseBlock, $wrongResourceBlock);

        $context->builder->positionAtEnd($falseBlock);
        $falseResult = self::boolBox($context, $context->getTypeFromString('int1')->constInt(0, false));
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($wrongResourceBlock);
        $isRes = JitIsResource::invoke($context, $handle);
        $streamBlock = BasicBlockHelper::append($context, 'stream_filter_remove_stream_err');
        $typeBlock = BasicBlockHelper::append($context, 'stream_filter_remove_type_err');
        $context->builder->branchIf($isRes, $streamBlock, $typeBlock);

        $context->builder->positionAtEnd($streamBlock);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            VmStreamFilterArg::invalidStreamFilterTypeError('stream_filter_remove')->getMessage()
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($typeBlock);
        JitResourceArg::emitResourceTypeErrorAndAbort(
            $context,
            'stream_filter_remove',
            0,
            'stream_filter',
            JitOperandTypeLabel::givenLabel($context, $args[0])
        );

        $context->builder->positionAtEnd($doneBlock);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy, 'stream_filter_remove_phi');
        $phi->addIncoming($validResult, $validEnd);
        $phi->addIncoming($falseResult, $falseEnd);

        return $phi;
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
        // Z_PARAM_STR $filter_name — TypeError under declare(strict_types=1) (#31408).
        $filterName = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            $functionName,
            1,
            'filter_name'
        );
        // Catchable TypeError seals the insert block; open a dead BB so later attach
        // IR does not land after a terminator (#31408 / peer #30250 JitDl).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))
        ) {
            BasicBlockHelper::ensureOpenInsertBlock($context, $functionName.'_strict_null_dead');

            return self::boolBox($context, $context->getTypeFromString('int1')->constInt(0, false));
        }
        $i64 = $context->getTypeFromString('int64');
        $readWrite = $i64->constInt(VmStreamFilterChain::ALL, false);
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
